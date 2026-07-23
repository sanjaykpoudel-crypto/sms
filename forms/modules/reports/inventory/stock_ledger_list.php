<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;
$item_id   = $_GET['item_id']   ?? '';

$items = $db->fetchAll("SELECT id, sku, item_name FROM items WHERE is_deleted=0 AND is_active=1 ORDER BY item_name ASC");
$item_options = ['' => 'All Items'];
foreach ($items as $it) { $item_options[$it['id']] = $it['item_name']; }

$params = [
    'date_from' => $date_from,
    'date_to'   => $date_to
];

$item_clause = '';
if ($item_id) {
    $item_clause = " AND i.id = :item_id ";
    $params['item_id'] = $item_id;
}

$rows = $db->fetchAll("
    SELECT 
        i.id, i.sku, i.item_name, i.cost_price,
        COALESCE(SUM(CASE 
            WHEN h.txn_date < :date_from THEN
                CASE 
                    WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
                    WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
                    ELSE 0 
                END
            ELSE 0 
        END), 0) AS opening_qty,
        
        COALESCE(SUM(CASE 
            WHEN h.txn_date BETWEEN :date_from AND :date_to THEN
                CASE 
                    WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock') THEN l.quantity 
                    WHEN h.txn_type = 'inventory_adjustment' AND l.quantity > 0 THEN l.quantity
                    ELSE 0 
                END
            ELSE 0 
        END), 0) AS qty_in,
        
        COALESCE(SUM(CASE 
            WHEN h.txn_date BETWEEN :date_from AND :date_to THEN
                CASE 
                    WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN l.quantity 
                    WHEN h.txn_type = 'inventory_adjustment' AND l.quantity < 0 THEN ABS(l.quantity)
                    ELSE 0 
                END
            ELSE 0 
        END), 0) AS qty_out
    FROM items i
    LEFT JOIN transaction_lines l ON l.item_id = i.id
    LEFT JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    WHERE i.is_deleted = 0 AND i.is_active = 1 $item_clause
    GROUP BY i.id, i.sku, i.item_name, i.cost_price
    ORDER BY i.item_name ASC
", $params);

$filtered_rows = [];
foreach ($rows as $r) {
    $filtered_rows[] = $r;
}

$total_in  = array_sum(array_column($filtered_rows, 'qty_in'));
$total_out = array_sum(array_column($filtered_rows, 'qty_out'));
?>

<?php rpt_filter_bar('Stock Ledger', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
    ['name'=>'item_id',  'label'=>'Item','type'=>'select','default'=>'','options'=>$item_options],
], 'tbl-stock-ledger'); ?>

<div class="rpt-summary">
  <div class="rpt-summary-card"><div class="val"><?= count($filtered_rows) ?></div><div class="lbl">Total Items</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= number_format($total_in,0) ?></div><div class="lbl">Total Qty In</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= number_format($total_out,0) ?></div><div class="lbl">Total Qty Out</div></div>
  <div class="rpt-summary-card"><div class="val"><?= number_format($total_in-$total_out,0) ?></div><div class="lbl">Net Movement</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-stock-ledger">
      <thead><tr>
        <th>Item Name</th>
        <th style="text-align:right">Opening Qty</th>
        <th style="text-align:right">Qty In</th>
        <th style="text-align:right">Qty Out</th>
        <th style="text-align:right">Closing Qty</th>
        <th style="text-align:right">Cost Price</th>
        <th style="text-align:right">Stock Value</th>
      </tr></thead>
      <tbody>
      <?php if (empty($filtered_rows)): ?>
        <tr><td colspan="7" style="text-align:center;color:#888;padding:30px">No stock items found.</td></tr>
      <?php else: 
        $grand_opening = 0;
        $grand_closing = 0;
        $grand_value = 0;
        foreach ($filtered_rows as $r): 
            $closing = $r['opening_qty'] + $r['qty_in'] - $r['qty_out'];
            $stock_value = $closing * $r['cost_price'];
            $grand_opening += $r['opening_qty'];
            $grand_closing += $closing;
            $grand_value += $stock_value;
      ?>
        <tr>
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td style="text-align:right;color:#666"><?= number_format($r['opening_qty'],0) ?></td>
          <td style="text-align:right;color:#1a7f37;font-weight:600"><?= $r['qty_in']>0 ? number_format($r['qty_in'],0) : '—' ?></td>
          <td style="text-align:right;color:#c00;font-weight:600"><?= $r['qty_out']>0 ? number_format($r['qty_out'],0) : '—' ?></td>
          <td style="text-align:right;font-weight:700"><?= number_format($closing,0) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['cost_price']) ?></td>
          <td style="text-align:right;font-weight:600"><?= rpt_currency($stock_value) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td colspan="1">TOTAL</td>
        <td style="text-align:right;color:#666"><?= number_format($grand_opening,0) ?></td>
        <td style="text-align:right;color:#1a7f37"><?= number_format($total_in,0) ?></td>
        <td style="text-align:right;color:#c00"><?= number_format($total_out,0) ?></td>
        <td style="text-align:right"><?= number_format($grand_closing,0) ?></td>
        <td></td>
        <td style="text-align:right"><?= rpt_currency($grand_value) ?></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<script>function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='stock_ledger.csv';a.click()}</script>
