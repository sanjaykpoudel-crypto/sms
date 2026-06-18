<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;
$item_id   = $_GET['item_id']   ?? '';

$items = $db->fetchAll("SELECT id, sku, item_name FROM items WHERE is_deleted=0 ORDER BY updated_at DESC");
$item_options = ['' => 'All Items'];
foreach ($items as $it) { $item_options[$it['id']] = $it['sku'].' - '.$it['item_name']; }

$where  = "h.txn_date BETWEEN ? AND ?";
$params = [$date_from, $date_to];
if ($item_id) { $where .= " AND l.item_id = ?"; $params[] = $item_id; }

$rows = $db->fetchAll("
    SELECT h.txn_date, h.txn_number, h.txn_type,
           i.sku, i.item_name,
           CASE 
             WHEN h.txn_type='vendor_bill' THEN 'Purchase IN'
             WHEN h.txn_type IN ('customer_invoice','POS') THEN 'Sale OUT'
             WHEN h.txn_type='inventory_adjustment' AND l.quantity > 0 THEN 'Adjustment IN'
             WHEN h.txn_type='inventory_adjustment' AND l.quantity < 0 THEN 'Adjustment OUT'
             ELSE h.txn_type 
           END AS movement_type,
           CASE 
             WHEN h.txn_type='vendor_bill' THEN l.quantity 
             WHEN h.txn_type='inventory_adjustment' AND l.quantity > 0 THEN l.quantity 
             ELSE 0 
           END AS qty_in,
           CASE 
             WHEN h.txn_type IN ('customer_invoice','POS') THEN l.quantity 
             WHEN h.txn_type='inventory_adjustment' AND l.quantity < 0 THEN ABS(l.quantity) 
             ELSE 0 
           END AS qty_out,
           l.unit_price
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    JOIN items i ON l.item_id = i.id
    WHERE $where AND h.txn_type IN ('vendor_bill','customer_invoice','POS','inventory_adjustment')
    ORDER BY h.txn_date, h.created_at
", $params);

$total_in  = array_sum(array_column($rows, 'qty_in'));
$total_out = array_sum(array_column($rows, 'qty_out'));
?>
?>

<?php rpt_filter_bar('Stock Ledger', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
    ['name'=>'item_id',  'label'=>'Item','type'=>'select','default'=>'','options'=>$item_options],
], 'tbl-stock-ledger'); ?>

<div class="rpt-summary">
  <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Movements</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= number_format($total_in,2) ?></div><div class="lbl">Total Qty In</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= number_format($total_out,2) ?></div><div class="lbl">Total Qty Out</div></div>
  <div class="rpt-summary-card"><div class="val"><?= number_format($total_in-$total_out,2) ?></div><div class="lbl">Net Movement</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-stock-ledger">
      <thead><tr>
        <th>Date</th><th>Ref #</th><th>Movement</th><th>SKU</th><th>Item</th>
        <th style="text-align:right">Qty In</th>
        <th style="text-align:right">Qty Out</th>
        <th style="text-align:right">Unit Price</th>
      </tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" style="text-align:center;color:#888;padding:30px">No stock movements found for selected period.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['txn_date'] ?></td>
          <td style="font-weight:600"><?= htmlspecialchars($r['txn_number']) ?></td>
          <td><?= $r['qty_in']>0 ? rpt_badge($r['movement_type'],'#1a7f37') : rpt_badge($r['movement_type'],'#c00') ?></td>
          <td><?= htmlspecialchars($r['sku']) ?></td>
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td style="text-align:right;color:#1a7f37;font-weight:600"><?= $r['qty_in']>0 ? number_format($r['qty_in'],2) : '-' ?></td>
          <td style="text-align:right;color:#c00;font-weight:600"><?= $r['qty_out']>0 ? number_format($r['qty_out'],2) : '-' ?></td>
          <td style="text-align:right"><?= rpt_currency($r['unit_price']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td colspan="5">TOTAL</td>
        <td style="text-align:right;color:#1a7f37"><?= number_format($total_in,2) ?></td>
        <td style="text-align:right;color:#c00"><?= number_format($total_out,2) ?></td>
        <td></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<script>function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='stock_ledger.csv';a.click()}</script>
