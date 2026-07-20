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
        END) AS gross_revenue
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
$sql .= " GROUP BY i.id ORDER BY gross_revenue DESC";
$rows = $db->fetchAll($sql, $params);

$total_revenue = array_sum(array_column($rows, 'gross_revenue'));
$total_qty = array_sum(array_column($rows, 'qty_sold'));
$avg_price = $total_qty > 0 ? $total_revenue / $total_qty : 0;
?>
<style>
.rpt-summary { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.rpt-summary-card { background: #fff; border: 1px solid #dde2e8; border-radius: 6px; padding: 14px 20px; flex: 1; min-width: 150px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.rpt-summary-card .val { font-size: 20px; font-weight: 800; color: var(--ns-primary); }
.rpt-summary-card .lbl { font-size: 11px; color: #888; margin-top: 4px; text-transform: uppercase; font-weight: 600; }
@media print { .ns-header, .ns-nav, .rpt-toolbar, form { display: none !important; } }
</style>

<?php rpt_filter_bar('Inventory Revenue Report', [
    ['name'=>'date_from','label'=>'From',    'type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',      'type'=>'date','default'=>$today],
    ['name'=>'category', 'label'=>'Category','type'=>'select','default'=>'','options'=>$catOptions],
], 'tbl-inv-revenue'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Unique Items Sold</div></div>
    <div class="rpt-summary-card"><div class="val"><?= number_format($total_qty, 0) ?></div><div class="lbl">Total Units Sold</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_revenue) ?></div><div class="lbl">Total Revenue</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#2ecc71"><?= rpt_currency($avg_price) ?></div><div class="lbl">Average Price / Unit</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-inv-revenue">
      <thead>
        <tr>
          <th>SKU</th>
          <th>Item Name</th>
          <th>Category</th>
          <th style="text-align:right">Units Sold</th>
          <th style="text-align:right">Avg. Selling Price</th>
          <th style="text-align:right">Revenue</th>
          <th style="text-align:right">Revenue Share</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $item_avg = $r['qty_sold'] > 0 ? $r['gross_revenue'] / $r['qty_sold'] : 0;
        $rev_share = $total_revenue > 0 ? ($r['gross_revenue'] / $total_revenue) * 100 : 0;
      ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($r['sku']) ?></td>
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td><?= htmlspecialchars($r['item_category'] ?? 'Uncategorized') ?></td>
          <td style="text-align:right;font-weight:600"><?= number_format($r['qty_sold'],0) ?></td>
          <td style="text-align:right"><?= rpt_currency($item_avg) ?></td>
          <td style="text-align:right;font-weight:600;color:#003087"><?= rpt_currency($r['gross_revenue']) ?></td>
          <td style="text-align:right;font-weight:600;color:#8e44ad"><?= number_format($rev_share, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:#f8f9fa">
          <td colspan="3">TOTAL</td>
          <td style="text-align:right"><?= number_format($total_qty,0) ?></td>
          <td style="text-align:right"><?= rpt_currency($avg_price) ?></td>
          <td style="text-align:right;color:#003087"><?= rpt_currency($total_revenue) ?></td>
          <td style="text-align:right;color:#8e44ad">100.0%</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='inventory_revenue.csv';a.click()}
</script>
