<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;

$rows = $db->fetchAll("
    SELECT 
        i.sku, i.item_name, rc.name as item_category,
        SUM(l.quantity)   AS qty_purchased,
        AVG(l.unit_price) AS avg_cost,
        SUM(l.line_total) AS total_cost
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    JOIN items i ON l.item_id = i.id
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE h.txn_type = 'vendor_bill'
      AND h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY i.id
    ORDER BY total_cost DESC
", [$date_from, $date_to]);

$total_cost = array_sum(array_column($rows, 'total_cost'));
$total_qty  = array_sum(array_column($rows, 'qty_purchased'));
?>
<style>
.rpt-toolbar{background:#f4f5f7;border:1px solid #dde2e8;border-radius:6px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;flex-wrap:wrap;gap:12px}
.rpt-title{font-size:15px;font-weight:700;color:#333;flex:1;min-width:180px}
.rpt-filter-form{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.rpt-filter-group{display:flex;align-items:center;gap:5px}
.rpt-filter-group label{font-size:12px;color:#555;white-space:nowrap}
.rpt-input{padding:5px 8px!important;font-size:12px!important;height:30px!important}
.rpt-summary{display:flex;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.rpt-summary-card{background:#fff;border:1px solid #dde2e8;border-radius:6px;padding:14px 20px;flex:1;min-width:150px;text-align:center}
.rpt-summary-card .val{font-size:20px;font-weight:800;color:#003087}
.rpt-summary-card .lbl{font-size:11px;color:#888;margin-top:4px}
@media print{.ns-header,.ns-nav,.rpt-filter-form button{display:none!important}}
</style>

<?php rpt_filter_bar('Purchase by Item', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'tbl-pur-item'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_cost) ?></div><div class="lbl">Total Purchase Cost</div></div>
    <div class="rpt-summary-card"><div class="val"><?= number_format($total_qty,2) ?></div><div class="lbl">Total Units Purchased</div></div>
    <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Unique Items</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-pur-item">
      <thead><tr>
        <th>SKU</th><th>Item Name</th><th>Category</th>
        <th style="text-align:right">Qty Purchased</th>
        <th style="text-align:right">Avg Cost Price</th>
        <th style="text-align:right">Total Cost</th>
      </tr></thead>
      <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($r['sku']) ?></td>
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td><?= htmlspecialchars($r['item_category'] ?? 'Uncategorized') ?></td>
          <td style="text-align:right"><?= number_format($r['qty_purchased'],2) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['avg_cost']) ?></td>
          <td style="text-align:right;font-weight:600"><?= rpt_currency($r['total_cost']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td colspan="3">TOTAL</td>
        <td style="text-align:right"><?= number_format($total_qty,2) ?></td>
        <td></td>
        <td style="text-align:right"><?= rpt_currency($total_cost) ?></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='purchase_by_item.csv';a.click()}
</script>
