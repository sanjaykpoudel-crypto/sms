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

require_once 'api/InventoryEngine.php';

$user_loc = function_exists('get_user_default_location_id') ? get_user_default_location_id() : '';
$location_id = $_GET['location_id'] ?? ($user_loc ?: ($_SESSION['location_id'] ?? null));

$invEngine = InventoryEngine::getInstance();
$filtered_rows = $invEngine->getRealtimeStockValuation(date('Y-m-d'), $location_id, $cat_filter);

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

// ── Inventory Subledger vs GL Reconciliation Check ──
require_once 'api/ReportingEngine.php';
$inv_gl = re_get_inventory_gl_balance($db);
$inv_diff = abs($total_cost_val - $inv_gl);
$inv_ok = ($inv_diff < 0.05);
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

<?php if (!$inv_ok): ?>
<div class="bs-recon-warn" style="text-align:center;padding:8px 20px;margin:6px auto 16px auto;max-width:1000px;background:#fff3cd;color:#856404;font-weight:600;border-radius:6px;font-size:12px">
  <i class="fas fa-exclamation-circle"></i> INVENTORY RECONCILIATION ERROR — Subledger: <?= rpt_currency($total_cost_val) ?> | GL: <?= rpt_currency($inv_gl) ?> | Diff: <?= rpt_currency($inv_diff) ?>
</div>
<?php endif; ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= count($filtered_rows) ?></div><div class="lbl">Total Items</div></div>
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
          <td colspan="3">TOTAL</td>
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
