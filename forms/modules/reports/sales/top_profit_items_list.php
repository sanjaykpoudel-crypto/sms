<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today      = date('Y-m-d');
$date_from  = $_GET['date_from'] ?? date('Y-m-01');
$date_to    = $_GET['date_to']   ?? $today;

$items = $db->fetchAll("
    SELECT 
        i.sku, 
        i.item_name, 
        rc.name as category_name,
        SUM(l.quantity) as total_qty, 
        SUM(l.line_total) as total_revenue, 
        SUM(l.cost_price * l.quantity) as total_cost,
        SUM(l.gross_profit) as total_profit
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    JOIN items i ON l.item_id = i.id
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE h.txn_type = 'customer_invoice' 
      AND h.is_deleted = 0 
      AND h.status != 'voided'
      AND h.txn_date BETWEEN ? AND ?
    GROUP BY l.item_id
    ORDER BY total_profit DESC
", [$date_from, $date_to]);

$sum_qty     = 0;
$sum_revenue = 0;
$sum_cost    = 0;
$sum_profit  = 0;

foreach ($items as $r) {
    $sum_qty     += (float)$r['total_qty'];
    $sum_revenue += (float)$r['total_revenue'];
    $sum_cost    += (float)$r['total_cost'];
    $sum_profit  += (float)$r['total_profit'];
}

$overall_margin = $sum_revenue > 0 ? ($sum_profit / $sum_revenue) * 100 : 0;
?>

<?php rpt_filter_bar('Top Profit Items Report', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'tbl-top-profit-items'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card">
        <div class="val"><?= number_format($sum_qty, 0) ?></div>
        <div class="lbl">Total Units Sold</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val"><?= rpt_currency($sum_revenue) ?></div>
        <div class="lbl">Total Sales Revenue</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#16a085"><?= rpt_currency($sum_profit) ?></div>
        <div class="lbl">Total Profit</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#2980b9"><?= number_format($overall_margin, 2) ?>%</div>
        <div class="lbl">Average Margin (%)</div>
    </div>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table" id="tbl-top-profit-items">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>SKU</th>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th style="text-align:right">Qty Sold</th>
                    <th style="text-align:right">Total Revenue</th>
                    <th style="text-align:right">Total Cost</th>
                    <th style="text-align:right">Gross Profit</th>
                    <th style="text-align:right">Margin (%)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): $rank = 1; foreach ($items as $r): 
                $margin = $r['total_revenue'] > 0 ? ($r['total_profit'] / $r['total_revenue']) * 100 : 0;
            ?>
                <tr>
                    <td style="font-weight:700;color:#7f8c8d">#<?= $rank++ ?></td>
                    <td style="font-weight:600"><?= htmlspecialchars($r['sku']) ?></td>
                    <td><strong><?= htmlspecialchars($r['item_name']) ?></strong></td>
                    <td><?= htmlspecialchars($r['category_name'] ?? 'Uncategorized') ?></td>
                    <td style="text-align:right;font-weight:600"><?= number_format($r['total_qty'], 0) ?></td>
                    <td style="text-align:right"><?= rpt_currency((float)$r['total_revenue']) ?></td>
                    <td style="text-align:right;color:#7f8c8d"><?= rpt_currency((float)$r['total_cost']) ?></td>
                    <td style="text-align:right;font-weight:700;color:#16a085"><?= rpt_currency((float)$r['total_profit']) ?></td>
                    <td style="text-align:right;font-weight:600;color:#2980b9"><?= number_format($margin, 2) ?>%</td>
                </tr>
            <?php endforeach; else: ?>
                <tr>
                    <td colspan="9" style="text-align:center;color:#999;padding:20px">No item profit records found for the selected period.</td>
                </tr>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($items)): ?>
            <tfoot>
                <tr style="background:#f8f9fa;font-weight:800;border-top:2px solid #ccc">
                    <td colspan="4">TOTALS</td>
                    <td style="text-align:right"><?= number_format($sum_qty, 0) ?></td>
                    <td style="text-align:right"><?= rpt_currency($sum_revenue) ?></td>
                    <td style="text-align:right"><?= rpt_currency($sum_cost) ?></td>
                    <td style="text-align:right;color:#16a085"><?= rpt_currency($sum_profit) ?></td>
                    <td style="text-align:right;color:#2980b9"><?= number_format($overall_margin, 2) ?>%</td>
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
    a.download = 'top_profit_items_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
