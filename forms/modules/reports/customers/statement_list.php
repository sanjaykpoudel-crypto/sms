<?php
require_once __DIR__ . '/../rpt_helpers.php';
$fy          = rpt_get_current_fiscal_year_dates();
$customer_id = $_GET['customer_id'] ?? '';
$from_date   = $_GET['from_date']   ?? $fy['start_date'];
$to_date     = $_GET['to_date']     ?? $fy['end_date'];

$customers_list = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
$customer_options = ['' => '-- Select Customer --'];
foreach ($customers_list as $c) { $customer_options[$c['id']] = $c['full_name']; }

$statement_data = [];
$customer_info = null;

if ($customer_id) {
    $customer_info = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [$customer_id]);
    $loc_sql = rpt_location_sql('th');
    
    // 1. Get Opening Balance (Invoices + Tagged Journals - Payments before from_date)
    $inv_before = $db->fetchOne("SELECT SUM(ci.total_amount) as total FROM customer_invoices ci 
                                JOIN transaction_headers th ON ci.header_id = th.id 
                                WHERE ci.customer_id = ? AND th.txn_date < ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 {$loc_sql}", [$customer_id, $from_date])['total'] ?? 0;
    
    $jour_before = $db->fetchOne("SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as total 
                                 FROM journal_entries j
                                 JOIN transaction_headers th ON j.header_id = th.id 
                                 WHERE (j.party_id = CAST(? AS CHAR) OR th.party_id = CAST(? AS CHAR)) AND (j.party_type = 'customer' OR j.party_type IS NULL) 
                                   AND th.txn_date < ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 AND th.txn_type IN ('Journal', 'journal_entry') {$loc_sql}", [$customer_id, $customer_id, $from_date])['total'] ?? 0;

    $pay_before = $db->fetchOne("
        SELECT COALESCE(SUM(p.amount), 0) as total 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id
        WHERE p.customer_id = ? AND p.payment_date < ? AND th.is_deleted = 0 {$loc_sql}
          AND th.id NOT IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ", [$customer_id, $from_date])['total'] ?? 0;
    
    $refund_before = $db->fetchOne("
        SELECT COALESCE(SUM(p.amount), 0) as total 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id
        WHERE p.customer_id = ? AND p.payment_date < ? AND th.is_deleted = 0 {$loc_sql}
          AND th.id IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ", [$customer_id, $from_date])['total'] ?? 0;

    $cm_before = $db->fetchOne("SELECT SUM(COALESCE(cm.total_amount, th.net_amount)) as total 
                                FROM transaction_headers th
                                LEFT JOIN credit_memos cm ON cm.header_id = th.id 
                                WHERE (cm.customer_id = ? OR (th.party_id = CAST(? AS CHAR) AND (th.party_type = 'customer' OR th.party_type IS NULL)))
                                  AND th.txn_type IN ('credit_memo', 'Credit Memo')
                                  AND th.txn_date < ? 
                                  AND th.status NOT IN ('void', 'voided', 'draft') 
                                  AND th.is_deleted = 0 {$loc_sql}", [$customer_id, $customer_id, $from_date])['total'] ?? 0;

    $opening_balance = ($inv_before + $jour_before + $refund_before) - $pay_before - $cm_before;

    // 2. Get Invoices in range
    $invoices = $db->fetchAll("SELECT th.txn_date as date, th.txn_number as number, 'Invoice' as type, ci.total_amount as debit, 0 as credit, th.memo
                               FROM customer_invoices ci 
                               JOIN transaction_headers th ON ci.header_id = th.id 
                               WHERE ci.customer_id = ? AND th.txn_date BETWEEN ? AND ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 {$loc_sql}", [$customer_id, $from_date, $to_date]);

    // 2b. Get Tagged Journals in range
    $journals = $db->fetchAll("SELECT th.txn_date as date, th.txn_number as number, 'Journal' as type,
                                      SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE 0 END) as debit,
                                      SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE 0 END) as credit,
                                      th.memo
                               FROM journal_entries j
                               JOIN transaction_headers th ON j.header_id = th.id
                               WHERE (j.party_id = CAST(? AS CHAR) OR th.party_id = CAST(? AS CHAR)) AND (j.party_type = 'customer' OR j.party_type IS NULL)
                                 AND th.txn_date BETWEEN ? AND ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 AND th.txn_type IN ('Journal', 'journal_entry') {$loc_sql}
                               GROUP BY th.id, th.txn_date, th.txn_number, th.memo", [$customer_id, $customer_id, $from_date, $to_date]);

    // 3. Get Payments in range (Money IN from customer)
    $payments = $db->fetchAll("
        SELECT p.payment_date as date, th.txn_number as number, 'Payment' as type, 
               0 as debit, p.amount as credit, th.memo, '' as applied_to_ref
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id
        WHERE p.customer_id = ? AND p.payment_date BETWEEN ? AND ? AND th.is_deleted = 0 AND p.amount > 0 {$loc_sql}
          AND th.id NOT IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ", [$customer_id, $from_date, $to_date]);

    // 3b. Get Cash Refunds / Returns in range (Money OUT to customer for Credit Memo)
    $refund_payments = $db->fetchAll("
        SELECT p.payment_date as date, th.txn_number as number, 'Customer Refund' as type, 
               p.amount as debit, 0 as credit, th.memo,
               (
                   SELECT GROUP_CONCAT(DISTINCT app_th.txn_number SEPARATOR ', ')
                   FROM transaction_links app_tl
                   JOIN transaction_headers app_th ON app_tl.child_id = app_th.id
                   WHERE app_tl.parent_id = th.id
               ) as applied_to_ref
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id
        WHERE p.customer_id = ? AND p.payment_date BETWEEN ? AND ? AND th.is_deleted = 0 AND p.amount > 0 {$loc_sql}
          AND th.id IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ", [$customer_id, $from_date, $to_date]);

    // 3c. Get Credit Memos in range
    $credit_memos = $db->fetchAll("SELECT th.id as header_id, th.txn_date as date, th.txn_number as number, 'Credit Memo' as type, 
                                          0 as debit, COALESCE(cm.total_amount, th.net_amount) as credit, th.memo,
                                          th.status, COALESCE(cm.remaining_credit, 0) as remaining_credit,
                                          (
                                              SELECT GROUP_CONCAT(DISTINCT app_th.txn_number SEPARATOR ', ')
                                              FROM transaction_links tl
                                              JOIN transaction_headers app_th ON tl.child_id = app_th.id
                                              WHERE tl.parent_id = th.id AND tl.link_type LIKE 'credit_memo_apply:%'
                                          ) as applied_to_ref
                                   FROM transaction_headers th
                                   LEFT JOIN credit_memos cm ON cm.header_id = th.id
                                   WHERE (cm.customer_id = ? OR (th.party_id = CAST(? AS CHAR) AND (th.party_type = 'customer' OR th.party_type IS NULL)))
                                     AND th.txn_type IN ('credit_memo', 'Credit Memo')
                                     AND th.txn_date BETWEEN ? AND ? 
                                     AND th.status NOT IN ('void', 'voided', 'draft') 
                                     AND th.is_deleted = 0 {$loc_sql}", [$customer_id, $customer_id, $from_date, $to_date]);

    $statement_data = array_merge($invoices, $journals, $payments, $refund_payments, $credit_memos);
    usort($statement_data, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    // 4. Aging Data
    $aging_res = get_customer_aging_summary($db, $customer_id, $to_date, $_GET['location_id'] ?? null);
    $aging7    = $aging_res['aging7'];

    $new_charges = array_sum(array_column($statement_data, 'debit'));
    $new_credits = array_sum(array_column($statement_data, 'credit'));
    $ending_balance = $opening_balance + $new_charges - $new_credits;

    $sys_info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
    $sys = []; foreach($sys_info as $row) { $sys[$row['meta_field']] = $row['meta_value']; }
    $wa_tpl = $sys['whatsapp_statement_template'] ?? "Dear {customer_name},\n\nPlease find your account statement summary for the period {from_date} to {to_date}:\n\n• Opening Balance: {currency} {opening_balance}\n• New Charges: {currency} {new_charges}\n• Payments Received: {currency} {payments}\n• Ending Balance Due: {currency} {ending_balance}\n\nThank you for doing business with us!\n{company_name}";
    
    $wa_message_default = str_replace(
        ['{customer_name}', '{from_date}', '{to_date}', '{opening_balance}', '{new_charges}', '{payments}', '{ending_balance}', '{currency}', '{company_name}'],
        [
            $customer_info['full_name'] ?? 'Valued Customer',
            date('M d, Y', strtotime($from_date)),
            date('M d, Y', strtotime($to_date)),
            number_format($opening_balance, 2),
            number_format($new_charges, 2),
            number_format($new_credits, 2),
            number_format($ending_balance, 2),
            'NPR',
            $sys['name'] ?? 'MNS LIQUORS'
        ],
        $wa_tpl
    );
}

$wa_btn = '';
if ($customer_id) {
    $wa_btn = '<button type="button" class="ns-btn" style="background: #25D366; color: white; border: none; padding: 6px 12px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: transform 0.15s ease;" title="Send via WhatsApp" onclick="openWaModal()"><i class="fab fa-whatsapp" style="font-size: 18px;"></i></button>';
}

rpt_filter_bar('Customer Statement', [
    ['name'=>'customer_id', 'label'=>'Customer', 'type'=>'select', 'options'=>$customer_options],
    ['name'=>'from_date', 'label'=>'From', 'type'=>'date', 'default'=>date('Y-m-01')],
    ['name'=>'to_date', 'label'=>'To', 'type'=>'date', 'default'=>date('Y-m-d')],
], 'tbl-statement', $wa_btn); ?>

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
            .ns-topbar, .ns-sidebar, .rpt-filter-bar, .ns-btn, button, .no-print, #whatsappModal, .modal, .modal-backdrop { display: none !important; }
            #ns-wrapper, .ns-main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; min-height: auto !important; }
            
            /* Print friendly letterhead & customer header */
            .rpt-header-print { display: flex !important; align-items: center !important; justify-content: space-between !important; border-bottom: 2px solid #0f172a !important; padding-bottom: 12px !important; margin-bottom: 15px !important; }

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
            .ns-report-table-static th { background: #f1f5f9 !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important; border-bottom: 2px solid #003087 !important; padding: 6px 8px !important; font-size: 11px !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
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
                        <td>
                            <?php
                            $badgeClass = 'ns-badge-secondary';
                            if ($row['type'] == 'Invoice') {
                                $badgeClass = 'ns-badge-primary';
                            } elseif ($row['type'] == 'Payment') {
                                $badgeClass = 'ns-badge-success';
                            } elseif ($row['type'] == 'Credit Memo') {
                                $badgeClass = 'ns-badge-warning';
                            } elseif ($row['type'] == 'Customer Refund' || $row['type'] == 'Refund') {
                                $badgeClass = 'ns-badge-danger';
                            }
                            ?>
                            <span class="ns-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($row['type']); ?></span>
                        </td>
                        <td style="font-size: 12px; color: #666;">
                            <?php 
                            echo htmlspecialchars($row['memo'] ?? '');
                            if (!empty($row['applied_to_ref'])) {
                                echo ($row['memo'] ? ' | ' : '') . '<span style="color: #0369a1; font-weight: 600;">Applied To: ' . htmlspecialchars($row['applied_to_ref']) . '</span>';
                            }
                            ?>
                        </td>
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

<?php if ($customer_id): ?>
<!-- WhatsApp Send Modal -->
<div id="whatsappModal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.6); z-index: 99999; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 12px; max-width: 580px; width: 90%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
            <div style="font-size: 18px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <i class="fab fa-whatsapp" style="color: #25D366; font-size: 22px;"></i> Send Statement via WhatsApp
            </div>
            <button type="button" onclick="closeWaModal()" style="background: none; border: none; font-size: 20px; color: #64748b; cursor: pointer;">&times;</button>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px; display: block;">Recipient Phone Number</label>
            <input type="text" id="wa-recipient-phone" class="ns-input" style="width: 100%; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 12px; font-weight: 600;" value="<?php echo htmlspecialchars($customer_info['phone'] ?? ''); ?>" placeholder="e.g. 9800000000">
            <span style="font-size: 11px; color: #64748b; margin-top: 4px; display: block;">Include country code if international (e.g. 9779800000000).</span>
        </div>

        <!-- PDF Document Attachment Option -->
        <div style="margin-bottom: 16px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                <label style="font-weight: 700; font-size: 13px; color: #003087; display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer;">
                    <input type="checkbox" id="wa-attach-pdf" checked onchange="toggleWaPdfAttachment()">
                    <i class="fas fa-file-pdf" style="color: #dc2626; font-size: 16px;"></i> Attach PDF Statement Document
                </label>
                <button type="button" class="ns-btn" style="padding: 4px 10px; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;" onclick="printStatementPdf()">
                    <i class="fas fa-print"></i> Print / Save PDF
                </button>
            </div>
            <div id="wa-pdf-info" style="font-size: 11px; color: #475569; line-height: 1.4; margin-top: 4px;">
                <i class="fas fa-check-circle" style="color: #16a085;"></i> Attachment Ready: <strong>Statement_<?php echo htmlspecialchars(str_replace(' ', '_', $customer_info['full_name'] ?? 'Customer')); ?>_<?php echo date('Ymd'); ?>.pdf</strong>
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px; display: block;">Message Preview</label>
            <textarea id="wa-message-body" class="ns-input" style="width: 100%; height: 140px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 10px; font-family: monospace; font-size: 12px; line-height: 1.5;"><?php echo htmlspecialchars($wa_message_default ?? ''); ?></textarea>
        </div>

        <div id="wa-modal-status" style="display: none; margin-bottom: 16px; padding: 10px; border-radius: 6px; font-size: 13px;"></div>

        <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 16px;">
            <button type="button" class="ns-btn" onclick="closeWaModal()">Cancel</button>
            <button type="button" class="ns-btn" onclick="sendWaWeb()" style="background: #0284c7; color: white; border: none; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fas fa-external-link-alt"></i> WhatsApp Web
            </button>
            <button type="button" class="ns-btn" id="btn-send-wa-api" onclick="sendWaApi()" style="background: #25D366; color: white; border: none; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fab fa-whatsapp"></i> Send via API
            </button>
        </div>
    </div>
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

function openWaModal() {
    document.getElementById('whatsappModal').style.display = 'flex';
    document.getElementById('wa-modal-status').style.display = 'none';
}
function closeWaModal() {
    document.getElementById('whatsappModal').style.display = 'none';
}
function printStatementPdf() {
    const modal = document.getElementById('whatsappModal');
    if (modal) modal.style.display = 'none';
    window.print();
    setTimeout(() => {
        if (modal) modal.style.display = 'flex';
    }, 1200);
}
function sendWaApi() {
    const phone = document.getElementById('wa-recipient-phone').value.trim();
    const msg = document.getElementById('wa-message-body').value.trim();
    if (!phone) { alert('Recipient phone number is required.'); return; }
    
    const btn = document.getElementById('btn-send-wa-api');
    const statusDiv = document.getElementById('wa-modal-status');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    statusDiv.style.display = 'block';
    statusDiv.style.background = '#f1f5f9';
    statusDiv.style.color = '#334155';
    statusDiv.innerHTML = 'Sending WhatsApp message...';

    fetch('api/send_whatsapp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ to: phone, message: msg })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fab fa-whatsapp"></i> Send via API';
        if (res.status === 'success') {
            statusDiv.style.background = '#dcfce7';
            statusDiv.style.color = '#166534';
            statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + res.message;
            setTimeout(() => { closeWaModal(); }, 2000);
        } else {
            statusDiv.style.background = '#fee2e2';
            statusDiv.style.color = '#991b1b';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + res.message;
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fab fa-whatsapp"></i> Send via API';
        statusDiv.style.background = '#fee2e2';
        statusDiv.style.color = '#991b1b';
        statusDiv.innerHTML = 'Error: ' + err.message;
    });
}
function sendWaWeb() {
    const phone = document.getElementById('wa-recipient-phone').value.trim().replace(/[^0-9]/g, '');
    const msg = encodeURIComponent(document.getElementById('wa-message-body').value.trim());
    if (!phone) { alert('Recipient phone number is required.'); return; }
    
    let cleanPhone = phone;
    if (cleanPhone.length === 10 && (cleanPhone.startsWith('98') || cleanPhone.startsWith('97'))) {
        cleanPhone = '977' + cleanPhone;
    }
    
    const waUrl = `https://api.whatsapp.com/send?phone=${cleanPhone}&text=${msg}`;
    window.open(waUrl, '_blank');
}
</script>
