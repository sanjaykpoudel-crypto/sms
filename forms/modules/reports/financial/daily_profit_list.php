<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today      = date('Y-m-d');
$date_from  = $_GET['date_from'] ?? date('Y-m-01');
$date_to    = $_GET['date_to']   ?? $today;

// ── Sales data: merge POS items (direct) + non-POS invoice lines ──
// Using pos_items directly is the single source of truth, same as the dashboard tile.
// gross_profit = (net_amount - tax) - (qty × cost_price)  ← tax excluded from margin
$pos_sales_rows = $db->fetchAll("
    SELECT
        DATE(pe.date_time) as txn_date,
        SUM(pi.net_amount - pi.tax)                            as total_sales,
        SUM(pi.quantity * i.cost_price)                        as total_cogs,
        SUM((pi.net_amount - pi.tax) - (pi.quantity * i.cost_price)) as gross_profit
    FROM pos_items pi
    JOIN items i ON pi.item_id = i.id AND i.is_deleted = 0
    JOIN pos_entry pe ON pi.pos_id = pe.id
    WHERE pe.is_deleted = 0 
      AND (pe.invoice_no NOT LIKE 'POS-SUM-%' OR pe.invoice_no IN (SELECT txn_number FROM transaction_headers WHERE txn_type = 'customer_invoice' AND is_deleted = 0))
      AND DATE(pe.date_time) BETWEEN ? AND ?
    GROUP BY DATE(pe.date_time)
", [$date_from, $date_to]);

$non_pos_sales_rows = $db->fetchAll("
    SELECT
        h.txn_date,
        SUM(l.line_total)             as total_sales,
        SUM(l.cost_price * l.quantity) as total_cogs,
        SUM(l.gross_profit)           as gross_profit
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_type = 'customer_invoice'
      AND h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_number NOT LIKE 'POS-%'
    GROUP BY h.txn_date
", [$date_from, $date_to]);

$expense_rows = $db->fetchAll("
    SELECT h.txn_date, SUM(e.amount) as total_expenses
    FROM expenses e
    JOIN transaction_headers h ON e.header_id = h.id
    WHERE h.txn_type = 'expense' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ?
    GROUP BY h.txn_date
", [$date_from, $date_to]);

// Merge all into a date-keyed map
$date_map = [];
foreach ($pos_sales_rows as $r) {
    $dt = $r['txn_date'];
    if (!isset($date_map[$dt])) $date_map[$dt] = ['total_sales'=>0,'total_cogs'=>0,'gross_profit'=>0,'total_expenses'=>0];
    $date_map[$dt]['total_sales']  += (float)$r['total_sales'];
    $date_map[$dt]['total_cogs']   += (float)$r['total_cogs'];
    $date_map[$dt]['gross_profit'] += (float)$r['gross_profit'];
}
foreach ($non_pos_sales_rows as $r) {
    $dt = $r['txn_date'];
    if (!isset($date_map[$dt])) $date_map[$dt] = ['total_sales'=>0,'total_cogs'=>0,'gross_profit'=>0,'total_expenses'=>0];
    $date_map[$dt]['total_sales']  += (float)$r['total_sales'];
    $date_map[$dt]['total_cogs']   += (float)$r['total_cogs'];
    $date_map[$dt]['gross_profit'] += (float)$r['gross_profit'];
}
foreach ($expense_rows as $r) {
    $dt = $r['txn_date'];
    if (!isset($date_map[$dt])) $date_map[$dt] = ['total_sales'=>0,'total_cogs'=>0,'gross_profit'=>0,'total_expenses'=>0];
    $date_map[$dt]['total_expenses'] += (float)$r['total_expenses'];
}

krsort($date_map); // newest first
$rows = [];
foreach ($date_map as $dt => $v) {
    $rows[] = [
        'date'           => $dt,
        'total_sales'    => $v['total_sales'],
        'total_cogs'     => $v['total_cogs'],
        'gross_profit'   => $v['gross_profit'],
        'total_expenses' => $v['total_expenses'],
        'net_profit'     => $v['gross_profit'] - $v['total_expenses'],
    ];
}


$sum_sales    = 0;
$sum_cogs     = 0;
$sum_gross    = 0;
$sum_expense  = 0;
$sum_net      = 0;

foreach ($rows as $r) {
    $sum_sales   += (float)$r['total_sales'];
    $sum_cogs    += (float)$r['total_cogs'];
    $sum_gross   += (float)$r['gross_profit'];
    $sum_expense += (float)$r['total_expenses'];
    $sum_net     += (float)$r['net_profit'];
}
?>

<?php rpt_filter_bar('Daily Profit & Loss Report', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'tbl-daily-profit'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card">
        <div class="val"><?= rpt_currency($sum_sales) ?></div>
        <div class="lbl">Total Sales</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#16a085"><?= rpt_currency($sum_gross) ?></div>
        <div class="lbl">Gross Profit</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#c0392b"><?= rpt_currency($sum_expense) ?></div>
        <div class="lbl">Operating Expenses</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:<?= $sum_net >= 0 ? '#27ae60' : '#d35400' ?>"><?= rpt_currency($sum_net) ?></div>
        <div class="lbl">Net Profit</div>
    </div>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table" id="tbl-daily-profit">
            <thead>
                <tr>
                    <th>Date</th>
                    <th style="text-align:right">Sales (Revenue)</th>
                    <th style="text-align:right">Cost of Sales (COGS)</th>
                    <th style="text-align:right">Gross Profit</th>
                    <th style="text-align:right">Operating Expenses</th>
                    <th style="text-align:right">Net Profit</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr>
                    <td style="font-weight:600"><?= rpt_date($r['date']) ?></td>
                    <td style="text-align:right"><?= rpt_currency((float)$r['total_sales']) ?></td>
                    <td style="text-align:right;color:#7f8c8d"><?= rpt_currency((float)$r['total_cogs']) ?></td>
                    <td style="text-align:right;font-weight:600;color:#16a085"><?= rpt_currency((float)$r['gross_profit']) ?></td>
                    <td style="text-align:right;color:#c0392b"><?= rpt_currency((float)$r['total_expenses']) ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= (float)$r['net_profit'] >= 0 ? '#27ae60' : '#d35400' ?>">
                        <?= rpt_currency((float)$r['net_profit']) ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr style="background:#f8f9fa;font-weight:800;border-top:2px solid #ccc">
                    <td>TOTALS</td>
                    <td style="text-align:right"><?= rpt_currency($sum_sales) ?></td>
                    <td style="text-align:right"><?= rpt_currency($sum_cogs) ?></td>
                    <td style="text-align:right;color:#16a085"><?= rpt_currency($sum_gross) ?></td>
                    <td style="text-align:right;color:#c0392b"><?= rpt_currency($sum_expense) ?></td>
                    <td style="text-align:right;color:<?= $sum_net >= 0 ? '#27ae60' : '#d35400' ?>"><?= rpt_currency($sum_net) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
function exportTableToCSV(id) {
    const t = document.getElementById(id);
    let csv = [];
    t.querySelectorAll('tr').forEach(r => {
        let row = [];
        r.querySelectorAll('th,td').forEach(c => row.push('"' + c.innerText.replace(/"/g, '""') + '"'));
        csv.push(row.join(','));
    });
    const b = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(b);
    a.download = 'daily_profit_report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
