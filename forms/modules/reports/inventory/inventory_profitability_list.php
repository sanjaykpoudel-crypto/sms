<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;
$cat_filter = $_GET['category'] ?? '';

// Fetch categories for the filter bar
$catQuery = $db->fetchAll("SELECT id, name FROM reference_codes WHERE type = 'category' AND is_active = 1 ORDER BY name ASC");
$catOptions = ['' => 'All Categories'];
foreach($catQuery as $c) {
    $catOptions[$c['id']] = $c['name'];
}

$sql = "
    SELECT 
        i.sku, i.item_name, rc.name as item_category,
        SUM(l.quantity) AS qty_sold,
        SUM(CASE 
            WHEN h.txn_number LIKE 'INV-POS-%' OR h.txn_number LIKE 'POS-SUM-%' THEN l.line_total
            ELSE l.line_total - l.tax_amount
        END) AS gross_revenue,
        SUM(l.gross_profit) AS gross_profit
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    JOIN items i ON l.item_id = i.id
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE h.txn_type IN ('customer_invoice','POS')
      AND h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
";
$params = [$date_from, $date_to];
if ($cat_filter !== '') {
    $sql .= " AND i.item_category = ?";
    $params[] = $cat_filter;
}
$sql .= " GROUP BY i.id ORDER BY gross_profit DESC";
$rows = $db->fetchAll($sql, $params);

$total_revenue = array_sum(array_column($rows, 'gross_revenue'));
$total_profit  = array_sum(array_column($rows, 'gross_profit'));
$total_qty     = array_sum(array_column($rows, 'qty_sold'));
$total_cogs    = $total_revenue - $total_profit;
$overall_margin = $total_revenue > 0 ? ($total_profit / $total_revenue) * 100 : 0;
?>
<style>
.rpt-summary { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.rpt-summary-card { background: #fff; border: 1px solid #dde2e8; border-radius: 6px; padding: 14px 20px; flex: 1; min-width: 150px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.rpt-summary-card .val { font-size: 20px; font-weight: 800; color: var(--ns-primary); }
.rpt-summary-card .lbl { font-size: 11px; color: #888; margin-top: 4px; text-transform: uppercase; font-weight: 600; }
@media print { .ns-header, .ns-nav, .rpt-toolbar, form { display: none !important; } }
</style>

<?php rpt_filter_bar('Inventory Profitability Report', [
    ['name'=>'date_from','label'=>'From',    'type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',      'type'=>'date','default'=>$today],
    ['name'=>'category', 'label'=>'Category','type'=>'select','default'=>'','options'=>$catOptions],
], 'tbl-inv-profitability'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_revenue) ?></div><div class="lbl">Sales Revenue</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_cogs) ?></div><div class="lbl">Cost of Goods Sold</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_profit) ?></div><div class="lbl">Gross Profit</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#8e44ad"><?= number_format($overall_margin, 1) ?>%</div><div class="lbl">Overall Margin</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-inv-profitability">
      <thead>
        <tr>
          <th>SKU</th>
          <th>Item Name</th>
          <th>Category</th>
          <th style="text-align:right">Units Sold</th>
          <th style="text-align:right">Revenue</th>
          <th style="text-align:right">Cost of Goods Sold (COGS)</th>
          <th style="text-align:right">Gross Profit</th>
          <th style="text-align:right">Profit Margin</th>
          <th style="text-align:right">Profit Share</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $item_cogs = $r['gross_revenue'] - $r['gross_profit'];
        $item_margin = $r['gross_revenue'] > 0 ? ($r['gross_profit'] / $r['gross_revenue']) * 100 : 0;
        $profit_share = $total_profit > 0 ? ($r['gross_profit'] / $total_profit) * 100 : 0;
        $color = $item_margin >= 20 ? '#1a7f37' : ($item_margin >= 10 ? '#9a6700' : '#c00');
      ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($r['sku']) ?></td>
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td><?= htmlspecialchars($r['item_category'] ?? 'Uncategorized') ?></td>
          <td style="text-align:right;font-weight:600"><?= number_format($r['qty_sold'],0) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['gross_revenue']) ?></td>
          <td style="text-align:right;color:#c00"><?= rpt_currency($item_cogs) ?></td>
          <td style="text-align:right;font-weight:600;color:#1a7f37"><?= rpt_currency($r['gross_profit']) ?></td>
          <td style="text-align:right;font-weight:600;color:<?= $color ?>"><?= number_format($item_margin, 1) ?>%</td>
          <td style="text-align:right;font-weight:600;color:#8e44ad"><?= number_format($profit_share, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:#f8f9fa">
          <td colspan="3">TOTAL</td>
          <td style="text-align:right"><?= number_format($total_qty,0) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_revenue) ?></td>
          <td style="text-align:right;color:#c00"><?= rpt_currency($total_cogs) ?></td>
          <td style="text-align:right;color:#1a7f37"><?= rpt_currency($total_profit) ?></td>
          <td style="text-align:right;color:#8e44ad"><?= number_format($overall_margin, 1) ?>%</td>
          <td style="text-align:right;color:#8e44ad">100.0%</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='inventory_profitability.csv';a.click()}
</script>
