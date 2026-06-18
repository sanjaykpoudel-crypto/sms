<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $today;
$date_to   = $_GET['date_to']   ?? $today;

// 1. Overall Summary
$summary = $db->fetchOne("
    SELECT 
        COUNT(*) as total_txns,
        SUM(gross_amount) as gross_sales,
        SUM(discount_amount) as total_discount,
        SUM(tax_amount) as total_vat,
        SUM(net_amount) as net_sales,
        (
            SELECT SUM(pi.net_amount - (pi.quantity * i.cost_price))
            FROM pos_items pi
            JOIN items i ON pi.item_id = i.id
            JOIN pos_entry p ON pi.pos_id = p.id
            WHERE DATE(p.date_time) BETWEEN ? AND ? AND p.is_deleted = 0
        ) as total_profit
    FROM pos_entry
    WHERE DATE(date_time) BETWEEN ? AND ? AND is_deleted = 0
", [$date_from, $date_to, $date_from, $date_to]);

// 2. Payment Method Breakdown
$payments = $db->fetchAll("
    SELECT 
        pp.payment_mode, 
        a.account_name,
        COUNT(DISTINCT pp.pos_id) as txn_count, 
        SUM(pp.amount) as total_amount
    FROM pos_payments pp
    JOIN pos_entry p ON pp.pos_id = p.id
    LEFT JOIN accounts a ON pp.account_id = a.id
    WHERE DATE(p.date_time) BETWEEN ? AND ? AND p.is_deleted = 0
    GROUP BY pp.account_id, pp.payment_mode
    ORDER BY total_amount DESC
", [$date_from, $date_to]);

// 3. Top Selling Items
$top_items = $db->fetchAll("
    SELECT 
        i.item_name, i.sku,
        SUM(pi.quantity) as total_qty, 
        SUM(pi.net_amount) as total_net,
        SUM(pi.net_amount - (pi.quantity * i.cost_price)) as total_profit
    FROM pos_items pi
    JOIN pos_entry p ON pi.pos_id = p.id
    JOIN items i ON pi.item_id = i.id
    WHERE DATE(p.date_time) BETWEEN ? AND ? AND p.is_deleted = 0
    GROUP BY pi.item_id
    ORDER BY total_qty DESC LIMIT 15
", [$date_from, $date_to]);

// 4. Hourly Sales Distribution (Optional but nice)
$hourly_sales = $db->fetchAll("
    SELECT 
        HOUR(date_time) as hr, 
        COUNT(*) as txn_count, 
        SUM(net_amount) as total_amount
    FROM pos_entry
    WHERE DATE(date_time) BETWEEN ? AND ? AND is_deleted = 0 AND status = 'completed'
    GROUP BY HOUR(date_time)
    ORDER BY hr ASC
", [$date_from, $date_to]);

// 5. POS Invoices List
$invoices = $db->fetchAll("
    SELECT 
        p.id, 
        p.invoice_no, 
        p.date_time, 
        c.full_name as customer_name,
        p.gross_amount, 
        p.discount_amount, 
        p.tax_amount, 
        p.net_amount,
        u.full_name as cashier_name,
        (
            SELECT SUM(pi.net_amount - (pi.quantity * i.cost_price))
            FROM pos_items pi
            JOIN items i ON pi.item_id = i.id
            WHERE pi.pos_id = p.id
        ) as profit
    FROM pos_entry p
    LEFT JOIN customers c ON p.customer_id = c.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE DATE(p.date_time) BETWEEN ? AND ? AND p.is_deleted = 0
    ORDER BY p.date_time DESC
", [$date_from, $date_to]);

?>

<?php rpt_filter_bar('Daily POS Summary', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>$today],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'pos-summary-report'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card">
        <div class="val"><?= number_format($summary['total_txns'] ?? 0) ?></div>
        <div class="lbl">Total Transactions</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val"><?= rpt_currency($summary['gross_sales'] ?? 0) ?></div>
        <div class="lbl">Gross Sales</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color: #e74c3c;"><?= rpt_currency($summary['total_discount'] ?? 0) ?></div>
        <div class="lbl">Total Discounts</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val"><?= rpt_currency($summary['net_sales'] ?? 0) ?></div>
        <div class="lbl">Net Collection</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color: #2ecc71;"><?= rpt_currency($summary['total_profit'] ?? 0) ?></div>
        <div class="lbl">Total Profit</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color: #27ae60;"><?= rpt_currency(($summary['net_sales'] ?? 0) / max(1, $summary['total_txns'] ?? 1)) ?></div>
        <div class="lbl">Avg. Ticket Size</div>
    </div>
</div>

<div style="display: flex; gap: 20px; align-items: flex-start;">
    <!-- Left: Payment & Items -->
    <div style="flex: 2; display: flex; flex-direction: column; gap: 20px;">
        <!-- Payment Breakdown -->
        <div class="ns-portlet">
            <div class="ns-portlet-header">
                <div class="ns-portlet-title"><i class="fas fa-wallet"></i> Sales by Payment Method</div>
            </div>
            <div class="ns-portlet-content">
                <table class="ns-report-table-static">
                    <thead>
                        <tr>
                            <th>Payment Mode</th>
                            <th style="text-align: right;">No. of Txns</th>
                            <th style="text-align: right;">Total Collected</th>
                            <th style="text-align: right;">% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_collected = array_sum(array_column($payments, 'total_amount'));
                        if (empty($payments)): 
                        ?>
                            <tr><td colspan="4" style="text-align:center; padding: 20px; color: #999;">No payments recorded for this period.</td></tr>
                        <?php else: foreach ($payments as $p): ?>
                            <tr>
                                <td style="font-weight: 700;">
                                    <?= htmlspecialchars($p['account_name'] ?: strtoupper($p['payment_mode'])) ?>
                                    <br><small style="color: #94a3b8; font-weight: 500;"><?= strtoupper($p['payment_mode']) ?></small>
                                </td>
                                <td style="text-align: right;"><?= $p['txn_count'] ?></td>
                                <td style="text-align: right; font-weight: 700;"><?= rpt_currency($p['total_amount']) ?></td>
                                <td style="text-align: right;"><?= number_format(($p['total_amount'] / max(1, $total_collected)) * 100, 1) ?>%</td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>TOTAL</td>
                            <td style="text-align: right;"><?= array_sum(array_column($payments, 'txn_count')) ?></td>
                            <td style="text-align: right;"><?= rpt_currency($total_collected) ?></td>
                            <td style="text-align: right;">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Top Selling Items -->
        <div class="ns-portlet">
            <div class="ns-portlet-header">
                <div class="ns-portlet-title"><i class="fas fa-star"></i> Top Selling Items</div>
            </div>
            <div class="ns-portlet-content">
                <table class="ns-report-table-static">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th style="text-align: right;">Qty Sold</th>
                            <th style="text-align: right;">Total Net</th>
                            <th style="text-align: right;">Avg. Price</th>
                            <th style="text-align: right;">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_items)): ?>
                            <tr><td colspan="5" style="text-align:center; padding: 20px; color: #999;">No items sold in this period.</td></tr>
                        <?php else: foreach ($top_items as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($item['item_name']) ?></strong><br>
                                    <small style="color: #7f8c8d;"><?= htmlspecialchars($item['sku']) ?></small>
                                </td>
                                <td style="text-align: right; font-weight: 700;"><?= number_format($item['total_qty'], 2) ?></td>
                                <td style="text-align: right;"><?= rpt_currency($item['total_net']) ?></td>
                                <td style="text-align: right;"><?= rpt_currency($item['total_net'] / max(1, $item['total_qty'])) ?></td>
                                <td style="text-align: right; font-weight: 700; color: #2ecc71;"><?= rpt_currency($item['total_profit']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right: Hourly & VAT -->
    <div style="flex: 1; display: flex; flex-direction: column; gap: 20px;">
        <!-- Hourly Distribution -->
        <div class="ns-portlet">
            <div class="ns-portlet-header">
                <div class="ns-portlet-title"><i class="fas fa-clock"></i> Sales by Hour</div>
            </div>
            <div class="ns-portlet-content">
                <table class="ns-report-table-static">
                    <thead>
                        <tr>
                            <th>Hour</th>
                            <th style="text-align: right;">Txns</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($hourly_sales)): ?>
                            <tr><td colspan="3" style="text-align:center; padding: 20px; color: #999;">No data.</td></tr>
                        <?php else: foreach ($hourly_sales as $h): ?>
                            <tr>
                                <td><?= date('h A', strtotime($h['hr'].":00")) ?></td>
                                <td style="text-align: right;"><?= $h['txn_count'] ?></td>
                                <td style="text-align: right; font-weight: 600;"><?= rpt_currency($h['total_amount']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- VAT Summary -->
        <div class="ns-portlet">
            <div class="ns-portlet-header">
                <div class="ns-portlet-title"><i class="fas fa-percent"></i> Tax Summary</div>
            </div>
            <div class="ns-portlet-content">
                <div style="padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <span style="font-size: 12px; color: #64748b; font-weight: 600;">TAXABLE SALES</span>
                        <span style="font-weight: 700;"><?= rpt_currency(($summary['net_sales'] ?? 0) - ($summary['total_vat'] ?? 0)) ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <span style="font-size: 12px; color: #64748b; font-weight: 600;">TOTAL VAT (13%)</span>
                        <span style="font-weight: 700; color: var(--ns-primary);"><?= rpt_currency($summary['total_vat'] ?? 0) ?></span>
                    </div>
                    <hr style="border: none; border-top: 1px dashed #cbd5e1; margin: 10px 0;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="font-size: 13px; color: #1e293b; font-weight: 800;">TOTAL NET</span>
                        <span style="font-size: 15px; font-weight: 800; color: var(--ns-primary);"><?= rpt_currency($summary['net_sales'] ?? 0) ?></span>
                    </div>
                </div>
                <div style="margin-top: 15px; font-size: 11px; color: #94a3b8; text-align: center;">
                    <i class="fas fa-info-circle"></i> This summary only includes 'Completed' POS transactions.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- POS Invoices List -->
<div class="ns-portlet" style="margin-top: 20px;">
    <div class="ns-portlet-header">
        <div class="ns-portlet-title"><i class="fas fa-file-invoice"></i> POS Invoices for the Period</div>
    </div>
    <div class="ns-portlet-content">
        <table class="ns-report-table-static" id="tbl-pos-invoices">
            <thead>
                <tr>
                    <th>Invoice No</th>
                    <th>Date & Time</th>
                    <th>Customer</th>
                    <th>Cashier</th>
                    <th style="text-align: right;">Gross Amount</th>
                    <th style="text-align: right;">Discount</th>
                    <th style="text-align: right;">Tax (VAT)</th>
                    <th style="text-align: right;">Net Amount</th>
                    <th style="text-align: right;">Profit</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sum_gross = 0;
                $sum_discount = 0;
                $sum_tax = 0;
                $sum_net = 0;
                $sum_profit = 0;
                if (empty($invoices)): 
                ?>
                    <tr><td colspan="9" style="text-align:center; padding: 20px; color: #999;">No invoices found for this period.</td></tr>
                <?php else: foreach ($invoices as $inv): 
                    $sum_gross += (float)$inv['gross_amount'];
                    $sum_discount += (float)$inv['discount_amount'];
                    $sum_tax += (float)$inv['tax_amount'];
                    $sum_net += (float)$inv['net_amount'];
                    $sum_profit += (float)$inv['profit'];
                ?>
                    <tr>
                        <td>
                            <a href="?page=transactions/pos/view&id=<?= $inv['id'] ?>" style="font-weight: 700; text-decoration: none; color: var(--ns-primary);">
                                <?= htmlspecialchars($inv['invoice_no']) ?>
                            </a>
                        </td>
                        <td><?= date('Y-m-d h:i A', strtotime($inv['date_time'])) ?></td>
                        <td><?= htmlspecialchars($inv['customer_name'] ?? 'Walk-in Customer') ?></td>
                        <td><?= htmlspecialchars($inv['cashier_name'] ?? 'System') ?></td>
                        <td style="text-align: right;"><?= rpt_currency($inv['gross_amount']) ?></td>
                        <td style="text-align: right; color: #e74c3c;"><?= rpt_currency($inv['discount_amount']) ?></td>
                        <td style="text-align: right;"><?= rpt_currency($inv['tax_amount']) ?></td>
                        <td style="text-align: right; font-weight: 700;"><?= rpt_currency($inv['net_amount']) ?></td>
                        <td style="text-align: right; font-weight: 700; color: #2ecc71;"><?= rpt_currency($inv['profit']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($invoices)): ?>
            <tfoot>
                <tr style="font-weight: 800; background: #f8f9fa;">
                    <td colspan="4">TOTALS</td>
                    <td style="text-align: right;"><?= rpt_currency($sum_gross) ?></td>
                    <td style="text-align: right; color: #e74c3c;"><?= rpt_currency($sum_discount) ?></td>
                    <td style="text-align: right;"><?= rpt_currency($sum_tax) ?></td>
                    <td style="text-align: right; font-weight: 800; color: var(--ns-primary);"><?= rpt_currency($sum_net) ?></td>
                    <td style="text-align: right; color: #2ecc71;"><?= rpt_currency($sum_profit) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
function exportTableToCSV(id) {
    // Basic CSV export logic
    let csv = [];
    let rows = document.querySelectorAll("table tr");
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length; j++) 
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        csv.push(row.join(","));        
    }
    const b = new Blob([csv.join('\n')], {type: 'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(b);
    a.download = 'pos_summary_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
