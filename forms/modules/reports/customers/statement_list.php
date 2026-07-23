<?php
require_once __DIR__ . '/../rpt_helpers.php';
$customer_id = $_GET['customer_id'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');

$customers_list = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY updated_at DESC");
$customer_options = ['' => '-- Select Customer --'];
foreach ($customers_list as $c) { $customer_options[$c['id']] = $c['full_name']; }

$statement_data = [];
$customer_info = null;

if ($customer_id) {
    $customer_info = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [$customer_id]);
    
    // 1. Get Opening Balance (Invoices + Tagged Journals - Payments before from_date)
    $inv_before = $db->fetchOne("SELECT SUM(total_amount) as total FROM customer_invoices ci 
                                JOIN transaction_headers th ON ci.header_id = th.id 
                                WHERE ci.customer_id = ? AND th.txn_date < ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0", [$customer_id, $from_date])['total'] ?? 0;
    
    $jour_before = $db->fetchOne("SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as total 
                                 FROM journal_entries j
                                 JOIN transaction_headers th ON j.header_id = th.id 
                                 WHERE (j.party_id = ? OR th.party_id = ?) AND (j.party_type = 'customer' OR j.party_type IS NULL) 
                                   AND th.txn_date < ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 AND th.txn_type IN ('Journal', 'journal_entry')", [$customer_id, $customer_id, $from_date])['total'] ?? 0;

    $pay_before = $db->fetchOne("SELECT SUM(p.amount) as total FROM payments p
                                JOIN transaction_headers th ON p.header_id = th.id
                                WHERE p.customer_id = ? AND p.payment_date < ? AND th.is_deleted = 0", [$customer_id, $from_date])['total'] ?? 0;
    
    $opening_balance = ($inv_before + $jour_before) - $pay_before;

    // 2. Get Invoices in range
    $invoices = $db->fetchAll("SELECT th.txn_date as date, th.txn_number as number, 'Invoice' as type, ci.total_amount as debit, 0 as credit, th.memo
                               FROM customer_invoices ci 
                               JOIN transaction_headers th ON ci.header_id = th.id 
                               WHERE ci.customer_id = ? AND th.txn_date BETWEEN ? AND ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0", [$customer_id, $from_date, $to_date]);

    // 2b. Get Tagged Journals in range
    $journals = $db->fetchAll("SELECT th.txn_date as date, th.txn_number as number, 'Journal' as type,
                                      SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE 0 END) as debit,
                                      SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE 0 END) as credit,
                                      th.memo
                               FROM journal_entries j
                               JOIN transaction_headers th ON j.header_id = th.id
                               WHERE (j.party_id = ? OR th.party_id = ?) AND (j.party_type = 'customer' OR j.party_type IS NULL)
                                 AND th.txn_date BETWEEN ? AND ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 AND th.txn_type IN ('Journal', 'journal_entry')
                               GROUP BY th.id, th.txn_date, th.txn_number, th.memo", [$customer_id, $customer_id, $from_date, $to_date]);

    // 3. Get Payments in range
    $payments = $db->fetchAll("SELECT p.payment_date as date, th.txn_number as number, 'Payment' as type, 0 as debit, SUM(p.amount) as credit, th.memo
                               FROM payments p
                               JOIN transaction_headers th ON p.header_id = th.id
                               WHERE p.customer_id = ? AND p.payment_date BETWEEN ? AND ? AND th.is_deleted = 0
                               GROUP BY p.header_id", [$customer_id, $from_date, $to_date]);

    $statement_data = array_merge($invoices, $journals, $payments);
    usort($statement_data, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    // 4. Aging Data
    $today = date('Y-m-d');
    $aging7 = ['current' => 0, '1_7' => 0, '8_14' => 0, '15_21' => 0, 'over_21' => 0];
    
    $open_docs = $db->fetchAll("
        SELECT ci.balance_due, th.txn_date FROM customer_invoices ci JOIN transaction_headers th ON ci.header_id = th.id WHERE ci.customer_id = ? AND ci.balance_due > 0.01 AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0
        UNION ALL
        SELECT (SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0.00)) as balance_due, th.txn_date
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        LEFT JOIN transaction_links tl ON tl.child_id = th.id AND tl.link_type LIKE 'payment:%'
        WHERE (j.party_id = ? OR th.party_id = ?) AND (j.party_type = 'customer' OR j.party_type IS NULL) AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 AND th.txn_type IN ('Journal', 'journal_entry')
        GROUP BY th.id, th.txn_date
        HAVING balance_due > 0.01
    ", [$customer_id, $customer_id, $customer_id]);
    
    foreach($open_docs as $inv) {
        $days = floor((strtotime($today) - strtotime($inv['txn_date'])) / 86400);
        
        // 7-Day aging
        if ($days <= 0) $aging7['current'] += $inv['balance_due'];
        elseif ($days <= 7) $aging7['1_7'] += $inv['balance_due'];
        elseif ($days <= 14) $aging7['8_14'] += $inv['balance_due'];
        elseif ($days <= 21) $aging7['15_21'] += $inv['balance_due'];
        else $aging7['over_21'] += $inv['balance_due'];
    }
}
?>

<?php rpt_filter_bar('Customer Statement', [
    ['name'=>'customer_id', 'label'=>'Customer', 'type'=>'select', 'options'=>$customer_options],
    ['name'=>'from_date', 'label'=>'From', 'type'=>'date', 'default'=>date('Y-m-01')],
    ['name'=>'to_date', 'label'=>'To', 'type'=>'date', 'default'=>date('Y-m-d')],
], 'tbl-statement'); ?>

<?php if ($customer_id): ?>
    <style>
        .stmt-header { display: flex; justify-content: space-between; margin-bottom: 30px; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .stmt-summary { display: flex; gap: 20px; margin-bottom: 30px; }
        .stmt-box { flex: 1; background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #3498db; }
        .stmt-box.charges { border-left-color: #e74c3c; }
        .stmt-box.payments { border-left-color: #27ae60; }
        .stmt-box.ending { background: var(--ns-primary); color: white; border-left: none; }
        .stmt-box-title { font-size: 12px; color: #7f8c8d; text-transform: uppercase; }
        .stmt-box.ending .stmt-box-title { opacity: 0.8; color: white; }
        .stmt-box-value { font-size: 20px; font-weight: 700; }

        @media print {
            @page { margin: 8mm; size: portrait; }
            body { background: #fff !important; color: #1e293b !important; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif !important; }
            .ns-topbar, .ns-sidebar, .rpt-filter-bar, .ns-btn, button { display: none !important; }
            #ns-wrapper, .ns-main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; min-height: auto !important; }
            
            /* Print friendly compact company header & customer header */
            .rpt-header-print { margin-bottom: 6px !important; padding-bottom: 4px !important; border-bottom: 1px solid #64748b !important; }
            .rpt-header-print img { max-height: 30px !important; margin-bottom: 2px !important; }
            .rpt-header-print h2 { font-size: 14px !important; margin: 0 !important; }
            .rpt-header-print p { font-size: 10px !important; margin: 1px 0 !important; line-height: 1.2 !important; }
            .rpt-header-print h3 { margin: 4px 0 2px 0 !important; padding-top: 3px !important; font-size: 12px !important; }

            .stmt-header { border: none !important; padding: 0 0 6px 0 !important; border-bottom: 2px solid #1e293b !important; border-radius: 0 !important; margin-bottom: 10px !important; }
            .stmt-header h3 { font-size: 16px !important; color: #0f172a !important; margin-bottom: 2px !important; }
            .stmt-header p { font-size: 11px !important; color: #334155 !important; margin: 2px 0 !important; line-height: 1.3 !important; }
            .stmt-header div:last-child div:nth-child(1) { font-size: 15px !important; margin-bottom: 2px !important; }
            .stmt-header div:last-child div:nth-child(2) { font-size: 10px !important; }
            .stmt-header div:last-child div:nth-child(3) { font-size: 11px !important; }
            
            .stmt-summary { gap: 10px !important; margin-bottom: 15px !important; }
            .stmt-box { padding: 8px 10px !important; border: 1px solid #cbd5e1 !important; border-left-width: 4px !important; background: #f8fafc !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .stmt-box.ending { background: var(--ns-primary) !important; color: white !important; }
            .stmt-box-title { font-size: 10px !important; }
            .stmt-box-value { font-size: 15px !important; }
            
            .ns-report-table-static { border: 1px solid #cbd5e1 !important; width: 100% !important; }
            .ns-report-table-static th { background: #f1f5f9 !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important; padding: 6px 8px !important; font-size: 11px !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .ns-report-table-static td { border: 1px solid #cbd5e1 !important; padding: 5px 8px !important; font-size: 11px !important; }
            
            .aging-container { display: block !important; margin-top: 15px !important; }
            .ns-portlet { border: none !important; box-shadow: none !important; margin: 0 !important; }
            .ns-portlet-content { padding: 0 !important; }
            
            h3 { break-after: avoid; }
            table { break-inside: auto; }
            tr { break-inside: avoid; break-after: auto; }
            td { break-inside: avoid; break-after: auto; }
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
        }
    </style>

    <!-- Statement Header -->
    <div class="stmt-header">
        <div>
            <h3 style="margin: 0; color: var(--ns-primary); font-size: 20px;"><?php echo htmlspecialchars($customer_info['full_name']); ?></h3>
            <p style="margin: 5px 0; color: #64748b; font-size: 13px;">
                <?php if (!empty($customer_info['address'])): ?>
                    <?php echo nl2br(htmlspecialchars($customer_info['address'])); ?><br>
                <?php endif; ?>
                <?php 
                    $info_parts = [];
                    $pan = $customer_info['pan_number'] ?? $customer_info['pan_no'] ?? '';
                    if (!empty($pan)) $info_parts[] = 'PAN: ' . htmlspecialchars($pan);
                    if (!empty($customer_info['phone'])) $info_parts[] = 'Phone: ' . htmlspecialchars($customer_info['phone']);
                    if (!empty($customer_info['email'])) $info_parts[] = 'Email: ' . htmlspecialchars($customer_info['email']);
                    echo implode(' | ', $info_parts);
                ?>
            </p>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 22px; font-weight: 700; color: #0f172a; margin-bottom: 5px; text-transform: uppercase;">Statement</div>
            <div style="font-size: 14px; color: #64748b; font-weight: 600; text-transform: uppercase;">Period</div>
            <div style="font-size: 16px; font-weight: 700; color: #1e293b;"><?php echo date('M d, Y', strtotime($from_date)); ?> - <?php echo date('M d, Y', strtotime($to_date)); ?></div>
        </div>
    </div>

            <!-- Summary Bar -->
            <div class="stmt-summary">
                <div class="stmt-box">
                    <div class="stmt-box-title">Opening Balance</div>
                    <div class="stmt-box-value"><?php echo number_format($opening_balance, 2); ?></div>
                </div>
                <div class="stmt-box charges">
                    <div class="stmt-box-title">New Charges</div>
                    <?php 
                        $new_charges = array_sum(array_column($statement_data, 'debit'));
                    ?>
                    <div class="stmt-box-value"><?php echo number_format($new_charges, 2); ?></div>
                </div>
                <div class="stmt-box payments">
                    <div class="stmt-box-title">Payments</div>
                    <?php 
                        $new_credits = array_sum(array_column($statement_data, 'credit'));
                    ?>
                    <div class="stmt-box-value"><?php echo number_format($new_credits, 2); ?></div>
                </div>
                <div class="stmt-box ending">
                    <div class="stmt-box-title">Ending Balance</div>
                    <div class="stmt-box-value"><?php echo number_format($opening_balance + $new_charges - $new_credits, 2); ?></div>
                </div>
            </div>

            <!-- Transaction Table -->
            <table id="tbl-statement" class="ns-report-table-static" style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th width="120">Date</th>
                        <th width="150">Ref No.</th>
                        <th>Type</th>
                        <th>Description / Memo</th>
                        <th style="text-align: right;">Debit (+)</th>
                        <th style="text-align: right;">Credit (-)</th>
                        <th style="text-align: right;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="4" style="font-weight: 600; background: #fafafa;">Opening Balance</td>
                        <td style="text-align: right; background: #fafafa;">-</td>
                        <td style="text-align: right; background: #fafafa;">-</td>
                        <td style="text-align: right; font-weight: 700; background: #fafafa;"><?php echo number_format($opening_balance, 2); ?></td>
                    </tr>
                    <?php 
                    $running_balance = $opening_balance;
                    foreach($statement_data as $row): 
                        $running_balance += ($row['debit'] - $row['credit']);
                    ?>
                    <tr>
                        <td><?php echo date('Y-m-d', strtotime($row['date'])); ?></td>
                        <td style="font-family: monospace;"><?php echo $row['number']; ?></td>
                        <td><span class="ns-badge <?php echo $row['type'] == 'Invoice' ? 'ns-badge-primary' : 'ns-badge-success'; ?>"><?php echo $row['type']; ?></span></td>
                        <td style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($row['memo'] ?? ''); ?></td>
                        <td style="text-align: right;"><?php echo $row['debit'] > 0 ? number_format($row['debit'], 2) : '-'; ?></td>
                        <td style="text-align: right; color: #27ae60;"><?php echo $row['credit'] > 0 ? number_format($row['credit'], 2) : '-'; ?></td>
                        <td style="text-align: right; font-weight: 600;"><?php echo number_format($running_balance, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="4" style="text-align: right;">Total for Period</th>
                        <th style="text-align: right;"><?php echo number_format($new_charges, 2); ?></th>
                        <th style="text-align: right;"><?php echo number_format($new_credits, 2); ?></th>
                        <th style="text-align: right;"><?php echo number_format($running_balance, 2); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Aging Tables Container -->
    <style>
        .aging-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-top: 30px;
        }
        @media (min-width: 1200px) {
            .aging-container {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media print {
            .aging-container {
                display: block !important;
            }
            .aging-container .ns-portlet {
                margin-top: 20px !important;
                page-break-inside: avoid;
            }
        }
    </style>
    
    <div class="aging-container" style="grid-template-columns: 1fr;">
        <!-- 7-Day Aging Table -->
        <div class="ns-portlet" style="margin: 0;">
            <div class="ns-portlet-content">
                <h3 style="margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 8px; font-size: 14px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px;">Aging Summary (7 Days - 5 Bands)</h3>
                <div style="overflow-x: auto;">
                    <table class="ns-report-table-static" style="width:100%; border-collapse: collapse; min-width: 400px;">
                        <thead>
                            <tr>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">Current</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">1-7 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">8-14 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">15-21 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">Over 21 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px; background: var(--ns-primary); color: white;">Total Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging7['current'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging7['1_7'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging7['8_14'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging7['15_21'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px; color: #e74c3c; font-weight: 600;"><?php echo number_format($aging7['over_21'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px; font-weight: 700; background: #f8f9fa; border: 1px solid #ddd;"><?php echo number_format(array_sum($aging7), 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <div style="padding: 100px 50px; text-align: center; color: #64748b; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 20px;">
        <i class="fas fa-users" style="font-size: 64px; margin-bottom: 20px; opacity: 0.2;"></i>
        <h3 style="margin: 0; font-size: 18px;">Statement Generator</h3>
        <p style="margin-top: 10px;">Please select a customer and date range from the filters above to generate a statement.</p>
    </div>
<?php endif; ?>

<script>
window.onbeforeprint = function() {
    window.originalTitle = document.title;
    document.title = "Statement_<?php echo str_replace(' ', '_', $customer_info['full_name'] ?? 'Customer'); ?>_<?php echo date('Ymd'); ?>";
};
window.onafterprint = function() {
    document.title = window.originalTitle;
};
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='customer_statement.csv';a.click()}
</script>
