<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$cat_filter = $_GET['category'] ?? '';

// Fetch categories for the filter bar
$catQuery = $db->fetchAll("SELECT id, name FROM reference_codes WHERE type = 'category' AND is_active = 1 ORDER BY name ASC");
$catOptions = ['' => 'All Categories'];
foreach($catQuery as $c) {
    $catOptions[$c['id']] = $c['name'];
}

// Calculate stock = purchases - sales for each item
$rows = $db->fetchAll("
    SELECT 
        i.id, i.sku, i.item_name, rc1.name as item_category, rc2.name as unit_type,
        i.cost_price, i.selling_price, i.item_category as category_id,
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

$filtered_rows = [];
foreach ($rows as $r) {
    if ($cat_filter && $r['category_id'] !== $cat_filter) {
        continue;
    }
    $filtered_rows[] = $r;
}

$total_qty = 0;
$total_cost_val = 0;
$total_retail_val = 0;

foreach ($filtered_rows as $r) {
    $total_qty += $r['stock_qty'];
    $total_cost_val += $r['stock_qty'] * $r['cost_price'];
    $total_retail_val += $r['stock_qty'] * $r['selling_price'];
}

$total_profit = $total_retail_val - $total_cost_val;
$overall_margin = $total_retail_val > 0 ? ($total_profit / $total_retail_val) * 100 : 0;
?>
<style>
.rpt-summary { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.rpt-summary-card { background: #fff; border: 1px solid #dde2e8; border-radius: 6px; padding: 14px 20px; flex: 1; min-width: 150px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.rpt-summary-card .val { font-size: 20px; font-weight: 800; color: var(--ns-primary); }
.rpt-summary-card .lbl { font-size: 11px; color: #888; margin-top: 4px; text-transform: uppercase; font-weight: 600; }
@media print { .ns-header, .ns-nav, .rpt-toolbar, form { display: none !important; } }
</style>

<?php rpt_filter_bar('Inventory Valuation Report', [
    ['name'=>'category','label'=>'Category','type'=>'select','default'=>'','options'=>$catOptions],
], 'tbl-inv-valuation'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= count($filtered_rows) ?></div><div class="lbl">Total SKUs</div></div>
    <div class="rpt-summary-card"><div class="val"><?= number_format($total_qty, 0) ?></div><div class="lbl">Total Stock Qty</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_cost_val) ?></div><div class="lbl">Valuation at Cost</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#2ecc71"><?= rpt_currency($total_retail_val) ?></div><div class="lbl">Valuation at Retail</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_profit) ?></div><div class="lbl">Unrealized Profit</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#8e44ad"><?= number_format($overall_margin, 1) ?>%</div><div class="lbl">Potential Margin</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-inv-valuation">
      <thead>
        <tr>
          <th>SKU</th>
          <th>Item Name</th>
          <th>Category</th>
          <th>Unit</th>
          <th style="text-align:right">Stock Qty</th>
          <th style="text-align:right">Cost Price</th>
          <th style="text-align:right">Valuation @ Cost</th>
          <th style="text-align:right">Retail Price</th>
          <th style="text-align:right">Valuation @ Retail</th>
          <th style="text-align:right">Potential Profit</th>
          <th style="text-align:right">Markup %</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($filtered_rows as $r):
        $cost_val = $r['stock_qty'] * $r['cost_price'];
        $retail_val = $r['stock_qty'] * $r['selling_price'];
        $profit = $retail_val - $cost_val;
        $markup = $cost_val > 0 ? ($profit / $cost_val) * 100 : 0;
      ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($r['sku']) ?></td>
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td><?= htmlspecialchars($r['item_category'] ?? 'Uncategorized') ?></td>
          <td><?= htmlspecialchars($r['unit_type'] ?? '') ?></td>
          <td style="text-align:right;font-weight:600"><?= number_format($r['stock_qty'],0) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['cost_price']) ?></td>
          <td style="text-align:right;font-weight:600;color:#003087"><?= rpt_currency($cost_val) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['selling_price']) ?></td>
          <td style="text-align:right;font-weight:600;color:#2ecc71"><?= rpt_currency($retail_val) ?></td>
          <td style="text-align:right;color:<?= $profit >= 0 ? '#1a7f37' : '#c00' ?>"><?= rpt_currency($profit) ?></td>
          <td style="text-align:right;font-weight:600;color:<?= $markup >= 20 ? '#1a7f37' : ($markup >= 10 ? '#9a6700' : '#c00') ?>"><?= number_format($markup, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:#f8f9fa">
          <td colspan="4">TOTAL</td>
          <td style="text-align:right"><?= number_format($total_qty,0) ?></td>
          <td style="text-align:right">-</td>
          <td style="text-align:right;color:#003087"><?= rpt_currency($total_cost_val) ?></td>
          <td style="text-align:right">-</td>
          <td style="text-align:right;color:#2ecc71"><?= rpt_currency($total_retail_val) ?></td>
          <td style="text-align:right;color:#1a7f37"><?= rpt_currency($total_profit) ?></td>
          <td style="text-align:right;color:#8e44ad"><?= number_format($overall_margin, 1) ?>%</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='inventory_valuation.csv';a.click()}
</script>
