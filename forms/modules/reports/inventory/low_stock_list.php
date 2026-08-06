<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$loc_sql = rpt_location_sql('h');

$items = $db->fetchAll("
    SELECT 
        i.id, i.sku, i.item_name, rc.name as category_name, i.reorder_level, i.reorder_qty, i.cost_price,
        COALESCE(SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment', 'credit_memo', 'Credit Memo') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale', 'vendor_credit', 'bill_credit', 'Vendor Credit') THEN -l.quantity 
            ELSE 0 END), 0) as current_stock
    FROM items i
    LEFT JOIN transaction_lines l ON l.item_id = i.id
    LEFT JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE i.is_deleted = 0 AND i.is_active = 1
    GROUP BY i.id
    ORDER BY current_stock ASC
");

$status_filter = $_GET['status'] ?? 'low_stock';

$filtered_items = [];
foreach ($items as $r) {
    $stock = (float)$r['current_stock'];
    $reorder = $r['reorder_level'] !== null ? (float)$r['reorder_level'] : null;

    if ($status_filter === 'low_stock') {
        if ($reorder === null || $stock > $reorder || $stock <= 0) continue;
    } elseif ($status_filter === 'available' || $status_filter === 'in_stock') {
        if ($stock <= 0) continue;
    } elseif ($status_filter === 'out_of_stock') {
        if ($stock > 0) continue;
    } elseif ($status_filter === 'negative') {
        if ($stock >= 0) continue;
    }

    $filtered_items[] = $r;
}

$out_of_stock_count = 0;
$low_stock_count = 0;
$total_cost_value = 0;

foreach ($filtered_items as $r) {
    $stock = (float)$r['current_stock'];
    if ($stock <= 0) {
        $out_of_stock_count++;
    } elseif ($r['reorder_level'] !== null && $stock <= (float)$r['reorder_level']) {
        $low_stock_count++;
    }
    $total_cost_value += max(0, $stock) * (float)$r['cost_price'];
}

$statusOptions = [
    '' => 'All Statuses',
    'low_stock' => 'Low Stock (In-Stock <= Reorder)',
    'available' => 'Available Only (Stock > 0)',
    'out_of_stock' => 'Out of Stock (Stock <= 0)',
    'negative' => 'Negative Stock (Stock < 0)'
];

rpt_filter_bar('Low Stock Report', [
    ['name'=>'status','label'=>'Status','type'=>'select','default'=>'low_stock','options'=>$statusOptions],
    rpt_location_filter(),
], 'tbl-low-stock');
?>

<div class="rpt-summary">
    <div class="rpt-summary-card">
        <div class="val"><?= count($filtered_items) ?></div>
        <div class="lbl">Filtered Items</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#e67e22"><?= $low_stock_count ?></div>
        <div class="lbl">Low Stock Items</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#c0392b"><?= $out_of_stock_count ?></div>
        <div class="lbl">Out of Stock Items</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#2ecc71"><?= rpt_currency($total_cost_value) ?></div>
        <div class="lbl">Total Stock Value</div>
    </div>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table" id="tbl-low-stock">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th style="text-align:right">Current Stock</th>
                    <th style="text-align:right">Reorder Level</th>
                    <th style="text-align:right">Cost Price</th>
                    <th style="text-align:right">Stock Value (Cost)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($filtered_items)): foreach ($filtered_items as $r): 
                $stock = (float)$r['current_stock'];
                $val_cost = max(0, $stock) * (float)$r['cost_price'];
                $status = 'HEALTHY';
                $badge_color = '#2ecc71';
                $row_bg = '#ffffff';

                if ($stock <= 0) {
                    $status = 'OUT OF STOCK';
                    $badge_color = '#e74c3c';
                    $row_bg = '#fdf2f2';
                } elseif ($r['reorder_level'] !== null && $stock <= (float)$r['reorder_level']) {
                    $status = 'LOW STOCK';
                    $badge_color = '#e67e22';
                    $row_bg = '#fef9f2';
                }
            ?>
                <tr style="background:<?= $row_bg ?>">
                    <td><strong><?= htmlspecialchars($r['item_name']) ?></strong></td>
                    <td><?= htmlspecialchars($r['category_name'] ?? 'Uncategorized') ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= $stock <= 0 ? '#e74c3c' : ($status == 'LOW STOCK' ? '#e67e22' : '#2c3e50') ?>"><?= number_format($stock, 0) ?></td>
                    <td style="text-align:right"><?= $r['reorder_level'] !== null ? number_format($r['reorder_level'], 0) : 'N/A' ?></td>
                    <td style="text-align:right"><?= rpt_currency((float)$r['cost_price']) ?></td>
                    <td style="text-align:right;font-weight:600"><?= rpt_currency($val_cost) ?></td>
                    <td><?= rpt_badge($status, $badge_color) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 25px; color: #64748b;">No items matching the selected status filter and location.</td>
                </tr>
            <?php endif; ?>
            </tbody>
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
    a.download = 'low_stock_report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
