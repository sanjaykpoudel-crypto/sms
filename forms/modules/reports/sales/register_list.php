<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;
$sale_type = $_GET['sale_type'] ?? '';

$sql = "
    SELECT 
        ci.header_id, ci.invoice_number, ci.invoice_date, ci.sale_type,
        c.full_name AS customer_name,
        ci.subtotal, ci.discount_amount, ci.tax_amount,
        ci.total_amount, ci.amount_paid, ci.balance_due, ci.payment_status
    FROM customer_invoices ci
    JOIN customers c ON ci.customer_id = c.id
    JOIN transaction_headers th ON ci.header_id = th.id
    WHERE ci.invoice_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status != 'void'
";
$params = [$date_from, $date_to];
if ($sale_type) { $sql .= " AND ci.sale_type = ?"; $params[] = $sale_type; }
$sql .= " ORDER BY ci.invoice_date DESC, ci.invoice_number DESC";
$rows = $db->fetchAll($sql, $params);

$total_amount  = array_sum(array_column($rows, 'total_amount'));
$total_tax     = array_sum(array_column($rows, 'tax_amount'));
$total_paid    = array_sum(array_column($rows, 'amount_paid'));
$total_balance = array_sum(array_column($rows, 'balance_due'));
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

<?php rpt_filter_bar('Sales Register', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
    ['name'=>'sale_type','label'=>'Type','type'=>'select','default'=>'','options'=>[
        ''=>'All Types','cash'=>'Cash','credit'=>'Credit'
    ]],
], 'tbl-sales-reg'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Total Invoices</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_amount) ?></div><div class="lbl">Gross Sales</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_tax) ?></div><div class="lbl">Total VAT</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_paid) ?></div><div class="lbl">Collected</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_balance) ?></div><div class="lbl">Pending</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-sales-reg">
      <thead><tr>
        <th>Invoice #</th><th>Date</th><th>Customer</th><th>Type</th>
        <th style="text-align:right">Subtotal</th>
        <th style="text-align:right">Discount</th>
        <th style="text-align:right">VAT</th>
        <th style="text-align:right">Total</th>
        <th style="text-align:right">Paid</th>
        <th style="text-align:right">Balance</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="11" style="text-align:center;color:#888;padding:30px">No invoices found for the selected period.</td></tr>
      <?php else: foreach ($rows as $r):
        $status_colors = ['unpaid'=>'#c00','partial'=>'#9a6700','paid'=>'#1a7f37'];
        $sc = $status_colors[$r['payment_status']] ?? '#888';
      ?>
        <tr>
          <td style="font-weight:600"><a href="?page=transactions/view&id=<?= $r['header_id'] ?>"><?= htmlspecialchars($r['invoice_number']) ?></a></td>
          <td><?= $r['invoice_date'] ?></td>
          <td><?= htmlspecialchars($r['customer_name']) ?></td>
          <td><?= rpt_badge(ucfirst($r['sale_type']), $r['sale_type']==='cash' ? '#1a7f37' : '#003087') ?></td>
          <td style="text-align:right"><?= rpt_currency($r['subtotal']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['discount_amount']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['tax_amount']) ?></td>
          <td style="text-align:right;font-weight:600"><?= rpt_currency($r['total_amount']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['amount_paid']) ?></td>
          <td style="text-align:right;color:<?= $r['balance_due']>0?'#c00':'#1a7f37' ?>;font-weight:600"><?= rpt_currency($r['balance_due']) ?></td>
          <td><?= rpt_badge(strtoupper($r['payment_status']), $sc) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td colspan="4">TOTAL (<?= count($rows) ?> invoices)</td>
        <td style="text-align:right"><?= rpt_currency(array_sum(array_column($rows,'subtotal'))) ?></td>
        <td style="text-align:right"><?= rpt_currency(array_sum(array_column($rows,'discount_amount'))) ?></td>
        <td style="text-align:right"><?= rpt_currency($total_tax) ?></td>
        <td style="text-align:right"><?= rpt_currency($total_amount) ?></td>
        <td style="text-align:right"><?= rpt_currency($total_paid) ?></td>
        <td style="text-align:right;color:#c00"><?= rpt_currency($total_balance) ?></td>
        <td></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='sales_register.csv';a.click()}
</script>
