<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$items = $db->fetchAll("
    SELECT 
        i.id, i.sku, i.item_name, rc.name as item_category, i.unit_type,
        i.cost_price, i.reorder_level, i.reorder_qty,
        COALESCE(SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
            ELSE 0 
        END), 0) AS stock_qty
    FROM items i
    LEFT JOIN transaction_lines l ON l.item_id = i.id
    LEFT JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE i.is_deleted = 0 AND i.is_active = 1
    GROUP BY i.id
    HAVING stock_qty <= i.reorder_level
    ORDER BY stock_qty ASC
");

$total_value = 0;
foreach ($items as $r) { $total_value += max(0, $r['stock_qty']) * $r['cost_price']; }
?>
?>

<?php rpt_filter_bar('Low Stock Report', [], 'tbl-low-stock'); ?>

<div class="rpt-summary">
  <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= count($items) ?></div><div class="lbl">Items Need Reorder</div></div>
  <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_value) ?></div><div class="lbl">Current Stock Value</div></div>
  <div class="rpt-summary-card"><div class="val"><?= count(array_filter($items, fn($r)=>$r['stock_qty']<=0)) ?></div><div class="lbl">Out of Stock</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-low-stock">
      <thead><tr>
        <th>SKU</th><th>Item Name</th><th>Category</th>
        <th style="text-align:right">Current Stock</th>
        <th style="text-align:right">Reorder Level</th>
        <th style="text-align:right">Reorder Qty</th>
        <th style="text-align:right">Cost Price</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php if (!empty($items)): foreach ($items as $r): ?>
        <tr style="background:<?= $r['stock_qty']<=0?'#f8d7da':'#fff3cd' ?>">
          <td style="font-weight:600"><?= htmlspecialchars($r['sku']) ?></td>
          <td><strong><?= htmlspecialchars($r['item_name']) ?></strong></td>
          <td><?= htmlspecialchars($r['item_category'] ?? 'Uncategorized') ?></td>
          <td style="text-align:right;font-weight:800;color:<?= $r['stock_qty']<=0?'#842029':'#664d03' ?>"><?= number_format($r['stock_qty'],0) ?></td>
          <td style="text-align:right"><?= number_format($r['reorder_level']) ?></td>
          <td style="text-align:right;color:#003087;font-weight:700"><?= number_format($r['reorder_qty']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['cost_price']) ?></td>
          <td><?= $r['stock_qty']<=0 ? rpt_badge('OUT OF STOCK','#842029') : rpt_badge('LOW STOCK','#9a6700') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='low_stock.csv';a.click()}</script>
