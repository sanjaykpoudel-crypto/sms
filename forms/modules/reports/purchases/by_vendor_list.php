<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;

$rows = $db->fetchAll("
    SELECT 
        v.vendor_code, v.company_name, v.phone,
        COUNT(DISTINCT vb.id) AS bill_count,
        SUM(vb.total_amount)  AS total_purchased,
        SUM(vb.amount_paid)   AS total_paid,
        SUM(vb.balance_due)   AS balance_due
    FROM vendor_bills vb
    JOIN vendors v ON vb.vendor_id = v.id
    JOIN transaction_headers th ON vb.header_id = th.id
    WHERE vb.bill_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    GROUP BY vb.vendor_id
    ORDER BY total_purchased DESC
", [$date_from, $date_to]);

$total_amount  = array_sum(array_column($rows, 'total_purchased'));
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

<?php rpt_filter_bar('Purchase by Vendor', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'tbl-pur-vendor'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_amount) ?></div><div class="lbl">Total Purchases</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_paid) ?></div><div class="lbl">Amount Paid</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_balance) ?></div><div class="lbl">Outstanding Payable</div></div>
    <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Active Vendors</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-pur-vendor">
      <thead><tr>
        <th>Code</th><th>Vendor Name</th><th>Phone</th>
        <th style="text-align:right">Bills</th>
        <th style="text-align:right">Total Purchased</th>
        <th style="text-align:right">Amount Paid</th>
        <th style="text-align:right">Balance Due</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($r['vendor_code']) ?></td>
          <td><?= htmlspecialchars($r['company_name']) ?></td>
          <td><?= htmlspecialchars($r['phone']) ?></td>
          <td style="text-align:right"><?= $r['bill_count'] ?></td>
          <td style="text-align:right"><?= rpt_currency($r['total_purchased']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['total_paid']) ?></td>
          <td style="text-align:right;color:<?= $r['balance_due']>0?'#c00':'#1a7f37' ?>;font-weight:600"><?= rpt_currency($r['balance_due']) ?></td>
          <td><?= $r['balance_due']>0 ? rpt_badge('PAYABLE','#c00') : rpt_badge('CLEAR','#1a7f37') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td colspan="4">TOTAL</td>
        <td style="text-align:right"><?= rpt_currency($total_amount) ?></td>
        <td style="text-align:right"><?= rpt_currency($total_paid) ?></td>
        <td style="text-align:right;color:#c00"><?= rpt_currency($total_balance) ?></td>
        <td></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<script>function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='purchase_by_vendor.csv';a.click()}</script>
