<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

// Calculate stock = purchases - sales for each item
$rows = $db->fetchAll("
    SELECT 
        i.id, i.sku, i.item_name, rc1.name as item_category, rc2.name as unit_type,
        i.cost_price, i.selling_price, i.reorder_level, i.item_category as category_id,
        COALESCE(SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
            ELSE 0 
        END), 0) AS stock_qty
    FROM items i
    LEFT JOIN transaction_lines l ON l.item_id = i.id
    LEFT JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    LEFT JOIN reference_codes rc1 ON i.item_category = rc1.id AND rc1.type = 'category'
    LEFT JOIN reference_codes rc2 ON i.unit_type = rc2.id AND rc2.type IN ('unit', 'units')
    WHERE i.is_deleted = 0 AND i.is_active = 1
    GROUP BY i.id
    ORDER BY rc1.name, i.item_name
");

$cat_filter = $_GET['category'] ?? '';
$filtered_rows = [];
foreach ($rows as $r) {
    if ($cat_filter && $r['category_id'] !== $cat_filter) {
        continue;
    }
    $filtered_rows[] = $r;
}

$total_value = 0;
foreach ($filtered_rows as $r) { $total_value += $r['stock_qty'] * $r['cost_price']; }
$low_stock_count = count(array_filter($filtered_rows, fn($r) => $r['stock_qty'] <= $r['reorder_level']));
?>
<style>
.stock-low{background:#fff3cd;color:#664d03}
.stock-out{background:#f8d7da;color:#842029}
</style>

<?php 
$catQuery = $db->fetchAll("SELECT id, name FROM reference_codes WHERE type = 'category' AND is_active = 1 ORDER BY name ASC");
$catOptions = ['' => 'All Categories'];
foreach($catQuery as $c) $catOptions[$c['id']] = $c['name'];

rpt_filter_bar('Stock Summary', [
    ['name'=>'category','label'=>'Category','type'=>'select','default'=>'','options'=>$catOptions],
], 'tbl-stock'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= count($filtered_rows) ?></div><div class="lbl">Total Items</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_value) ?></div><div class="lbl">Stock Value (Cost)</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= $low_stock_count ?></div><div class="lbl">Low / Out of Stock</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-stock">
      <thead><tr>
        <th>Item Name</th><th>Category</th><th>Unit</th>
        <th style="text-align:right">Stock Qty</th>
        <th style="text-align:right">Reorder Lvl</th>
        <th style="text-align:right">Cost Price</th>
        <th style="text-align:right">Stock Value</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php
        foreach ($filtered_rows as $r):
            $stock_val = $r['stock_qty'] * $r['cost_price'];
            $is_out = $r['stock_qty'] <= 0;
            $is_low = !$is_out && $r['stock_qty'] <= $r['reorder_level'];
            $row_class = $is_out ? 'stock-out' : ($is_low ? 'stock-low' : '');
      ?>
        <tr class="<?= $row_class ?>">
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td><?= htmlspecialchars($r['item_category'] ?? 'Uncategorized') ?></td>
          <td><?= htmlspecialchars($r['unit_type'] ?? '') ?></td>
          <td style="text-align:right;font-weight:600"><?= number_format($r['stock_qty'],0) ?></td>
          <td style="text-align:right"><?= number_format($r['reorder_level']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['cost_price']) ?></td>
          <td style="text-align:right"><?= rpt_currency($stock_val) ?></td>
          <td><?php
            if ($is_out) echo rpt_badge('OUT OF STOCK','#842029');
            elseif ($is_low) echo rpt_badge('LOW STOCK','#9a6700');
            else echo rpt_badge('OK','#1a7f37');
          ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='stock_summary.csv';a.click()}</script>
