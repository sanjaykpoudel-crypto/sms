<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$items = $db->fetchAll("
    SELECT 
        i.id, i.sku, i.item_name, rc.name as category_name, i.reorder_level, i.reorder_qty, i.cost_price,
        COALESCE(SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
            ELSE 0 END), 0) as current_stock
    FROM items i
    LEFT JOIN transaction_lines l ON l.item_id = i.id
    LEFT JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE i.is_deleted = 0 AND i.is_active = 1
    GROUP BY i.id
    HAVING current_stock <= i.reorder_level
    ORDER BY (i.reorder_level - current_stock) DESC
");

$out_of_stock_count = 0;
$total_replenish_qty = 0;
$total_replenish_cost = 0;

foreach ($items as $r) {
    if ($r['current_stock'] <= 0) {
        $out_of_stock_count++;
    }
    $total_replenish_qty += (float)$r['reorder_qty'];
    $total_replenish_cost += (float)$r['reorder_qty'] * (float)$r['cost_price'];
}
?>

<?php rpt_filter_bar('Urgent Purchases Report (Items Below Reorder Level)', [], 'tbl-urgent-buy'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card">
        <div class="val" style="color:#c0392b"><?= count($items) ?></div>
        <div class="lbl">Items Needing Restock</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#e67e22"><?= $out_of_stock_count ?></div>
        <div class="lbl">Out of Stock Items</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val"><?= number_format($total_replenish_qty, 0) ?></div>
        <div class="lbl">Suggested Order Qty</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#2980b9"><?= rpt_currency($total_replenish_cost) ?></div>
        <div class="lbl">Est. Replenishment Cost</div>
    </div>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table" id="tbl-urgent-buy">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th style="text-align:right">Current Stock</th>
                    <th style="text-align:right">Reorder Level</th>
                    <th style="text-align:right">Shortage Gap</th>
                    <th style="text-align:right">Suggested Order Qty</th>
                    <th style="text-align:right">Unit Cost</th>
                    <th style="text-align:right">Estimated Cost</th>
                    <th>Urgency</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): foreach ($items as $r): 
                $stock = (float)$r['current_stock'];
                $reorder_lvl = (float)$r['reorder_level'];
                $gap = $reorder_lvl - $stock;
                $order_qty = (float)$r['reorder_qty'];
                $est_cost = $order_qty * (float)$r['cost_price'];

                $urgency = 'MEDIUM';
                $badge_color = '#e67e22';
                $row_bg = '#fef9f2';

                if ($stock <= 0) {
                    $urgency = 'CRITICAL';
                    $badge_color = '#c0392b';
                    $row_bg = '#fdf2f2';
                } elseif ($gap > ($reorder_lvl * 0.5)) {
                    $urgency = 'HIGH';
                    $badge_color = '#d35400';
                    $row_bg = '#fff5eb';
                }
            ?>
                <tr style="background:<?= $row_bg ?>">
                    <td><strong><?= htmlspecialchars($r['item_name']) ?></strong></td>
                    <td><?= htmlspecialchars($r['category_name'] ?? 'Uncategorized') ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= $stock <= 0 ? '#c0392b' : '#d35400' ?>"><?= number_format($stock, 0) ?></td>
                    <td style="text-align:right"><?= number_format($reorder_lvl, 0) ?></td>
                    <td style="text-align:right;font-weight:700;color:#c0392b"><?= number_format($gap, 0) ?></td>
                    <td style="text-align:right;font-weight:700;color:#2980b9"><?= number_format($order_qty, 0) ?></td>
                    <td style="text-align:right"><?= rpt_currency((float)$r['cost_price']) ?></td>
                    <td style="text-align:right;font-weight:700"><?= rpt_currency($est_cost) ?></td>
                    <td><?= rpt_badge($urgency, $badge_color) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr>
                    <td colspan="9" style="text-align:center;color:#27ae60;font-weight:bold;padding:30px">
                        <i class="fas fa-check-circle" style="font-size:24px;margin-bottom:10px;display:block"></i>
                        All items are fully stocked! No urgent purchases required.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($items)): ?>
            <tfoot>
                <tr style="background:#f8f9fa;font-weight:800;border-top:2px solid #ccc">
                    <td colspan="5">TOTAL EST. REPLENISHMENT BUDGET</td>
                    <td style="text-align:right;color:#2980b9"><?= number_format($total_replenish_qty, 0) ?></td>
                    <td></td>
                    <td style="text-align:right;color:#c0392b"><?= rpt_currency($total_replenish_cost) ?></td>
                    <td></td>
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
    a.download = 'urgent_purchases_report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
