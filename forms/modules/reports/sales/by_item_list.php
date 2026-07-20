<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;

$rows = $db->fetchAll("
    SELECT 
        i.sku, i.item_name, rc.name as item_category,
        SUM(l.quantity)    AS qty_sold,
        SUM(CASE 
            WHEN h.txn_number LIKE 'INV-POS-%' OR h.txn_number LIKE 'POS-SUM-%' THEN l.line_total
            ELSE l.line_total - l.tax_amount
        END)  AS gross_revenue,
        SUM(l.gross_profit) AS gross_profit
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    JOIN items i ON l.item_id = i.id
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE h.txn_type IN ('customer_invoice','POS')
      AND h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY i.id
    ORDER BY gross_revenue DESC
", [$date_from, $date_to]);

$total_revenue = array_sum(array_column($rows, 'gross_revenue'));
$total_profit  = array_sum(array_column($rows, 'gross_profit'));
$total_qty     = array_sum(array_column($rows, 'qty_sold'));
?>
?>

<?php rpt_filter_bar('Sales by Item', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'tbl-sales-item'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_revenue) ?></div><div class="lbl">Total Revenue</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_profit) ?></div><div class="lbl">Gross Profit</div></div>
    <div class="rpt-summary-card"><div class="val"><?= number_format($total_qty) ?></div><div class="lbl">Total Units Sold</div></div>
    <div class="rpt-summary-card"><div class="val"><?= $total_revenue > 0 ? number_format($total_profit/$total_revenue*100,1).'%' : '0%' ?></div><div class="lbl">Profit Margin</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-sales-item">
      <thead><tr>
        <th>SKU</th><th>Item Name</th><th>Category</th>
        <th style="text-align:right">Qty Sold</th>
        <th style="text-align:right">Revenue</th>
        <th style="text-align:right">Gross Profit</th>
        <th style="text-align:right">Margin %</th>
      </tr></thead>
      <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r):
        $margin = $r['gross_revenue'] > 0 ? $r['gross_profit']/$r['gross_revenue']*100 : 0;
        $color = $margin >= 20 ? '#1a7f37' : ($margin >= 10 ? '#9a6700' : '#c00');
      ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($r['sku']) ?></td>
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td><?= htmlspecialchars($r['item_category'] ?? 'Uncategorized') ?></td>
          <td style="text-align:right"><?= number_format($r['qty_sold'],2) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['gross_revenue']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['gross_profit']) ?></td>
          <td style="text-align:right;color:<?= $color ?>;font-weight:600"><?= number_format($margin,1) ?>%</td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td colspan="3">TOTAL</td>
        <td style="text-align:right"><?= number_format($total_qty,2) ?></td>
        <td style="text-align:right"><?= rpt_currency($total_revenue) ?></td>
        <td style="text-align:right"><?= rpt_currency($total_profit) ?></td>
        <td style="text-align:right"><?= $total_revenue > 0 ? number_format($total_profit/$total_revenue*100,1).'%' : '0%' ?></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='sales_by_item.csv';a.click()}
</script>
