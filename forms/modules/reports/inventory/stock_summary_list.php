<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';
$db = db();

if (function_exists('auto_sync_pos_items_and_invoices')) {
    auto_sync_pos_items_and_invoices(true);
}

require_once 'api/InventoryEngine.php';

$user_loc = function_exists('get_user_default_location_id') ? get_user_default_location_id() : '';
$loc_id = $_GET['location_id'] ?? ($user_loc ?: ($_SESSION['location_id'] ?? null));

$invEngine = InventoryEngine::getInstance();
$rows = $invEngine->getRealtimeStockValuation(date('Y-m-d'), $loc_id);

$cat_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';

$filtered_rows = [];
foreach ($rows as $r) {
    if ($cat_filter && $r['category_id'] !== $cat_filter) {
        continue;
    }
    
    $stock_qty = (float)$r['stock_qty'];
    $reorder = $r['reorder_level'] !== null ? (float)$r['reorder_level'] : null;

    if ($status_filter === 'available' || $status_filter === 'in_stock') {
        if ($stock_qty <= 0) continue;
    } elseif ($status_filter === 'low_stock') {
        if ($reorder === null || $stock_qty > $reorder || $stock_qty <= 0) continue;
    } elseif ($status_filter === 'out_of_stock') {
        if ($stock_qty > 0) continue;
    } elseif ($status_filter === 'negative') {
        if ($stock_qty >= 0) continue;
    }

    $filtered_rows[] = $r;
}

$total_value = 0;
foreach ($filtered_rows as $r) { $total_value += $r['stock_qty'] * $r['cost_price']; }
$low_stock_count = count(array_filter($filtered_rows, fn($r) => $r['reorder_level'] !== null && $r['stock_qty'] <= $r['reorder_level']));
?>
<style>
.stock-low{background:#fff3cd;color:#664d03}
.stock-out{background:#f8d7da;color:#842029}
</style>

<?php 
$catQuery = $db->fetchAll("SELECT id, name FROM reference_codes WHERE type = 'category' AND is_active = 1 ORDER BY name ASC");
$catOptions = ['' => 'All Categories'];
foreach($catQuery as $c) $catOptions[$c['id']] = $c['name'];

$statusOptions = [
    '' => 'All Statuses',
    'available' => 'Available Only (Stock > 0)',
    'low_stock' => 'Low Stock',
    'out_of_stock' => 'Out of Stock',
    'negative' => 'Negative Stock'
];

rpt_filter_bar('Stock Summary', [
    ['name'=>'category','label'=>'Category','type'=>'select','default'=>'','options'=>$catOptions],
    ['name'=>'status','label'=>'Status','type'=>'select','default'=>'','options'=>$statusOptions],
    rpt_location_filter(),
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
          <td style="text-align:right;font-weight:600">
            <?= number_format($r['stock_qty'],0) ?> <?= htmlspecialchars($r['unit_type'] ?? 'PCS') ?>
            <?php 
              $conv = (int)($r['units_per_case'] ?? 0);
              if ($conv > 1) {
                  $case_qty = round($r['stock_qty'] / $conv, 1);
                  $case_unit = !empty($r['case_unit_name']) ? $r['case_unit_name'] : 'CASE';
                  echo '<br><small style="color:#666;font-weight:normal">(' . $case_qty . ' ' . htmlspecialchars($case_unit) . ')</small>';
              }
            ?>
          </td>
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
