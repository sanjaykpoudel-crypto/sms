<?php
/**
 * Accounts Payable (AP) Aging Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';

$db = db();
$today = date('Y-m-d');

// Fetch vendor aging outstanding balances
$sql = "
    SELECT 
        v.vendor_code,
        v.company_name as vendor_name,
        COALESCE(SUM(vb.balance_due), 0.00) as total_due,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, vb.bill_date) BETWEEN 0 AND 30 THEN vb.balance_due ELSE 0.00 END), 0.00) as bucket_30,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, vb.bill_date) BETWEEN 31 AND 60 THEN vb.balance_due ELSE 0.00 END), 0.00) as bucket_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, vb.bill_date) BETWEEN 61 AND 90 THEN vb.balance_due ELSE 0.00 END), 0.00) as bucket_90,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, vb.bill_date) > 90 THEN vb.balance_due ELSE 0.00 END), 0.00) as bucket_over_90
    FROM vendors v
    JOIN vendor_bills vb ON v.id = vb.vendor_id
    JOIN transaction_headers h ON vb.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    WHERE v.is_deleted = 0
    GROUP BY v.id, v.vendor_code, v.company_name
    HAVING total_due > 0.00
    ORDER BY v.company_name ASC
";

$rows = $db->fetchAll($sql, [$today, $today, $today, $today]);

$total_due = 0.0;
$total_30 = 0.0;
$total_60 = 0.0;
$total_90 = 0.0;
$total_over_90 = 0.0;

foreach ($rows as $r) {
    $total_due += (float)$r['total_due'];
    $total_30 += (float)$r['bucket_30'];
    $total_60 += (float)$r['bucket_60'];
    $total_90 += (float)$r['bucket_90'];
    $total_over_90 += (float)$r['bucket_over_90'];
}
?>
<?php rpt_header('Accounts Payable (AP) Aging Report'); ?>

<div class="ns-page-header" style="margin-bottom: 20px;">
    <h1 class="ns-page-title"><i class="fas fa-history"></i> Accounts Payable (AP) Aging Report</h1>
    <div style="font-size: 12px; color: #666; margin-top: 4px;">As of Date: <?= rpt_date($today) ?></div>
</div>

<div class="rpt-summary">
  <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_due) ?></div><div class="lbl">Total Payables</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_30) ?></div><div class="lbl">0 - 30 Days (Current)</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_60) ?></div><div class="lbl">31 - 60 Days</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#b7791f"><?= rpt_currency($total_90) ?></div><div class="lbl">61 - 90 Days</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_over_90) ?></div><div class="lbl">91+ Days</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-ap-aging">
      <thead>
        <tr>
          <th>Code</th>
          <th>Vendor Company Name</th>
          <th style="text-align:right">Total Outstanding</th>
          <th style="text-align:right">0 - 30 Days</th>
          <th style="text-align:right">31 - 60 Days</th>
          <th style="text-align:right">61 - 90 Days</th>
          <th style="text-align:right">91+ Days</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="font-weight:700; color:#888;"><?= $r['vendor_code'] ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($r['vendor_name']) ?></td>
            <td style="text-align:right; font-weight:700; color:#c00;"><?= rpt_currency($r['total_due']) ?></td>
            <td style="text-align:right; color:<?= $r['bucket_30'] > 0 ? '#1a7f37' : '#ccc' ?>"><?= $r['bucket_30'] > 0 ? rpt_currency($r['bucket_30']) : '—' ?></td>
            <td style="text-align:right; color:<?= $r['bucket_60'] > 0 ? '#003087' : '#ccc' ?>"><?= $r['bucket_60'] > 0 ? rpt_currency($r['bucket_60']) : '—' ?></td>
            <td style="text-align:right; color:<?= $r['bucket_90'] > 0 ? '#b7791f' : '#ccc' ?>"><?= $r['bucket_90'] > 0 ? rpt_currency($r['bucket_90']) : '—' ?></td>
            <td style="text-align:right; color:<?= $r['bucket_over_90'] > 0 ? '#c00' : '#ccc' ?>; font-weight:700"><?= $r['bucket_over_90'] > 0 ? rpt_currency($r['bucket_over_90']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:900; background:#003087; color:#fff">
          <th>TOTALS</th>
          <th></th>
          <th style="text-align:right"><?= rpt_currency($total_due) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_30) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_60) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_90) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_over_90) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
