<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;

$rows = $db->fetchAll("
    SELECT 
        c.customer_code, c.full_name, c.customer_type,
        COUNT(DISTINCT ci.id)   AS txn_count,
        SUM(ci.total_amount)    AS total_sales,
        SUM(ci.amount_paid)     AS total_paid,
        SUM(ci.balance_due)     AS balance_due
    FROM customer_invoices ci
    JOIN customers c ON ci.customer_id = c.id
    JOIN transaction_headers th ON ci.header_id = th.id
    WHERE th.txn_type = 'customer_invoice' AND th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    GROUP BY ci.customer_id
    ORDER BY total_sales DESC
", [$date_from, $date_to]);

$total_sales   = array_sum(array_column($rows, 'total_sales'));
$total_paid    = array_sum(array_column($rows, 'total_paid'));
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

<?php rpt_filter_bar('Sales by Customer', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'tbl-sales-cust'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_sales) ?></div><div class="lbl">Total Sales</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_paid) ?></div><div class="lbl">Amount Collected</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_balance) ?></div><div class="lbl">Outstanding Balance</div></div>
    <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Active Customers</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-sales-cust">
      <thead><tr>
        <th>Code</th><th>Customer Name</th><th>Type</th>
        <th style="text-align:right">Transactions</th>
        <th style="text-align:right">Total Sales</th>
        <th style="text-align:right">Paid</th>
        <th style="text-align:right">Balance Due</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($r['customer_code']) ?></td>
          <td><?= htmlspecialchars($r['full_name']) ?></td>
          <td><?= ucfirst($r['customer_type']) ?></td>
          <td style="text-align:right"><?= $r['txn_count'] ?></td>
          <td style="text-align:right"><?= rpt_currency($r['total_sales']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['total_paid']) ?></td>
          <td style="text-align:right;color:<?= $r['balance_due'] > 0 ? '#c00' : '#1a7f37' ?>;font-weight:600"><?= rpt_currency($r['balance_due']) ?></td>
          <td><?= $r['balance_due'] > 0 ? rpt_badge('Credit', '#c00') : rpt_badge('Clear', '#1a7f37') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td colspan="4">TOTAL</td>
        <td style="text-align:right"><?= rpt_currency($total_sales) ?></td>
        <td style="text-align:right"><?= rpt_currency($total_paid) ?></td>
        <td style="text-align:right;color:#c00"><?= rpt_currency($total_balance) ?></td>
        <td></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='sales_by_customer.csv';a.click()}
</script>
