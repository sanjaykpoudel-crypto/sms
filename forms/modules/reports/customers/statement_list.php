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
    
    // 1. Get Opening Balance (Invoices - Payments before from_date)
    $inv_before = $db->fetchOne("SELECT SUM(total_amount) as total FROM customer_invoices ci 
                                JOIN transaction_headers th ON ci.header_id = th.id 
                                WHERE ci.customer_id = ? AND th.txn_date < ? AND th.status != 'void' AND th.is_deleted = 0", [$customer_id, $from_date])['total'] ?? 0;
    
    $pay_before = $db->fetchOne("SELECT SUM(p.amount) as total FROM payments p
                                JOIN transaction_headers th ON p.header_id = th.id
                                WHERE p.customer_id = ? AND p.payment_date < ? AND th.is_deleted = 0", [$customer_id, $from_date])['total'] ?? 0;
    
    $opening_balance = $inv_before - $pay_before;

    // 2. Get Invoices in range
    $invoices = $db->fetchAll("SELECT th.txn_date as date, th.txn_number as number, 'Invoice' as type, ci.total_amount as debit, 0 as credit, th.memo
                               FROM customer_invoices ci 
                               JOIN transaction_headers th ON ci.header_id = th.id 
                               WHERE ci.customer_id = ? AND th.txn_date BETWEEN ? AND ? AND th.status != 'void' AND th.is_deleted = 0", [$customer_id, $from_date, $to_date]);

    // 3. Get Payments in range
    $payments = $db->fetchAll("SELECT p.payment_date as date, th.txn_number as number, 'Payment' as type, 0 as debit, SUM(p.amount) as credit, th.memo
                               FROM payments p
                               JOIN transaction_headers th ON p.header_id = th.id
                               WHERE p.customer_id = ? AND p.payment_date BETWEEN ? AND ? AND th.is_deleted = 0
                               GROUP BY p.header_id", [$customer_id, $from_date, $to_date]);

    $statement_data = array_merge($invoices, $payments);
    usort($statement_data, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    // 4. Aging Data
    $today = date('Y-m-d');
    $aging = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
    $aging15 = ['current' => 0, '1_15' => 0, '16_30' => 0, '31_45' => 0, '46_60' => 0, '61_75' => 0, 'over_75' => 0];
    $open_invoices = $db->fetchAll("SELECT ci.balance_due, th.txn_date FROM customer_invoices ci JOIN transaction_headers th ON ci.header_id = th.id WHERE ci.customer_id = ? AND ci.balance_due > 0 AND th.status != 'void' AND th.is_deleted = 0", [$customer_id]);
    foreach($open_invoices as $inv) {
        $days = floor((strtotime($today) - strtotime($inv['txn_date'])) / 86400);
        
        // 30-Day aging
        if ($days <= 0) $aging['current'] += $inv['balance_due'];
        elseif ($days <= 30) $aging['1_30'] += $inv['balance_due'];
        elseif ($days <= 60) $aging['31_60'] += $inv['balance_due'];
        elseif ($days <= 90) $aging['61_90'] += $inv['balance_due'];
        else $aging['over_90'] += $inv['balance_due'];

        // 15-Day aging
        if ($days <= 0) $aging15['current'] += $inv['balance_due'];
        elseif ($days <= 15) $aging15['1_15'] += $inv['balance_due'];
        elseif ($days <= 30) $aging15['16_30'] += $inv['balance_due'];
        elseif ($days <= 45) $aging15['31_45'] += $inv['balance_due'];
        elseif ($days <= 60) $aging15['46_60'] += $inv['balance_due'];
        elseif ($days <= 75) $aging15['61_75'] += $inv['balance_due'];
        else $aging15['over_75'] += $inv['balance_due'];
    }
}
?>

<?php rpt_filter_bar('Customer Statement', [
    ['name'=>'customer_id', 'label'=>'Customer', 'type'=>'select', 'options'=>$customer_options],
    ['name'=>'from_date', 'label'=>'From', 'type'=>'date', 'default'=>date('Y-m-01')],
    ['name'=>'to_date', 'label'=>'To', 'type'=>'date', 'default'=>date('Y-m-d')],
], 'tbl-statement'); ?>

<?php if ($customer_id): ?>
    <!-- Statement Header -->
    <div style="display: flex; justify-content: space-between; margin-bottom: 30px; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <div>
            <h3 style="margin: 0; color: var(--ns-primary); font-size: 20px;"><?php echo htmlspecialchars($customer_info['full_name']); ?></h3>
            <p style="margin: 5px 0; color: #64748b; font-size: 13px;">
                <?php echo nl2br(htmlspecialchars($customer_info['address'] ?? '')); ?><br>
                <?php echo htmlspecialchars($customer_info['email'] ?? ''); ?> | <?php echo htmlspecialchars($customer_info['phone'] ?? ''); ?>
            </p>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 14px; color: #64748b; font-weight: 600; text-transform: uppercase;">Period</div>
            <div style="font-size: 16px; font-weight: 700; color: #1e293b;"><?php echo date('M d, Y', strtotime($from_date)); ?> - <?php echo date('M d, Y', strtotime($to_date)); ?></div>
        </div>
    </div>

            <!-- Summary Bar -->
            <div style="display: flex; gap: 20px; margin-bottom: 30px;">
                <div style="flex: 1; background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #3498db;">
                    <div style="font-size: 12px; color: #7f8c8d; text-transform: uppercase;">Opening Balance</div>
                    <div style="font-size: 20px; font-weight: 700;"><?php echo number_format($opening_balance, 2); ?></div>
                </div>
                <div style="flex: 1; background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #e74c3c;">
                    <div style="font-size: 12px; color: #7f8c8d; text-transform: uppercase;">New Charges</div>
                    <?php 
                        $new_charges = array_sum(array_column($statement_data, 'debit'));
                    ?>
                    <div style="font-size: 20px; font-weight: 700;"><?php echo number_format($new_charges, 2); ?></div>
                </div>
                <div style="flex: 1; background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #27ae60;">
                    <div style="font-size: 12px; color: #7f8c8d; text-transform: uppercase;">Payments</div>
                    <?php 
                        $new_credits = array_sum(array_column($statement_data, 'credit'));
                    ?>
                    <div style="font-size: 20px; font-weight: 700;"><?php echo number_format($new_credits, 2); ?></div>
                </div>
                <div style="flex: 1; background: var(--ns-primary); color: white; padding: 15px; border-radius: 8px;">
                    <div style="font-size: 12px; opacity: 0.8; text-transform: uppercase;">Ending Balance</div>
                    <div style="font-size: 20px; font-weight: 700;"><?php echo number_format($opening_balance + $new_charges - $new_credits, 2); ?></div>
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
    
    <div class="aging-container">
        <!-- 30-Day Aging Table -->
        <div class="ns-portlet" style="margin: 0;">
            <div class="ns-portlet-content">
                <h3 style="margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 8px; font-size: 14px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px;">Aging Summary (30 Days)</h3>
                <div style="overflow-x: auto;">
                    <table class="ns-report-table-static" style="width:100%; border-collapse: collapse; min-width: 400px;">
                        <thead>
                            <tr>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">Current</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">1-30 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">31-60 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">61-90 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">Over 90 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px; background: var(--ns-primary); color: white;">Total Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging['current'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging['1_30'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging['31_60'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging['61_90'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px; color: #e74c3c; font-weight: 600;"><?php echo number_format($aging['over_90'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px; font-weight: 700; background: #f8f9fa; border: 1px solid #ddd;"><?php echo number_format(array_sum($aging), 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 15-Day Aging Table -->
        <div class="ns-portlet" style="margin: 0;">
            <div class="ns-portlet-content">
                <h3 style="margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 8px; font-size: 14px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px;">Aging Summary (15 Days - 6 Bands)</h3>
                <div style="overflow-x: auto;">
                    <table class="ns-report-table-static" style="width:100%; border-collapse: collapse; min-width: 500px;">
                        <thead>
                            <tr>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">Current</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">1-15 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">16-30 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">31-45 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">46-60 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">61-75 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px;">Over 75 Days</th>
                                <th style="text-align: center; padding: 8px 4px; font-size: 10px; background: var(--ns-primary); color: white;">Total Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging15['current'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging15['1_15'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging15['16_30'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging15['31_45'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging15['46_60'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px;"><?php echo number_format($aging15['61_75'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px; color: #e74c3c; font-weight: 600;"><?php echo number_format($aging15['over_75'], 2); ?></td>
                                <td style="text-align: center; padding: 10px 4px; font-size: 12px; font-weight: 700; background: #f8f9fa; border: 1px solid #ddd;"><?php echo number_format(array_sum($aging15), 2); ?></td>
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
