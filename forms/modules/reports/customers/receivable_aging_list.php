<?php
/**
 * Accounts Receivable (AR) Aging Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';

$db = db();
$today = date('Y-m-d');

// Fetch customer aging outstanding balances
$customers = $db->fetchAll("SELECT id, customer_code, full_name FROM customers WHERE is_deleted = 0 ORDER BY full_name ASC");

$rows = [];
$total_due = 0.0;
$total_30 = 0.0;
$total_60 = 0.0;
$total_90 = 0.0;
$total_over_90 = 0.0;

foreach ($customers as $c) {
    $ag = get_customer_aging_summary($db, $c['id'], $today);
    $b = $ag['aging30'];
    $tot = $ag['total_due'];
    if ($tot > 0.005) {
        $rows[] = [
            'customer_code' => $c['customer_code'],
            'customer_name' => $c['full_name'],
            'total_due'     => $tot,
            'bucket_30'     => $b['current'] + $b['b30'],
            'bucket_60'     => $b['b60'],
            'bucket_90'     => $b['b90'],
            'bucket_over_90'=> $b['over_90'],
        ];
        $total_due     += $tot;
        $total_30      += ($b['current'] + $b['b30']);
        $total_60      += $b['b60'];
        $total_90      += $b['b90'];
        $total_over_90 += $b['over_90'];
    }
}

// ── AR Subledger vs GL Reconciliation Check ──
require_once 'api/ReportingEngine.php';
$ar_gl = re_get_ar_gl_balance($db, $today);
$ar_ok = abs($total_due - $ar_gl) < 0.05;
?>
<?php rpt_header('Accounts Receivable (AR) Aging Report'); ?>

<div class="ns-page-header" style="margin-bottom: 20px;">
    <h1 class="ns-page-title"><i class="fas fa-history"></i> Accounts Receivable (AR) Aging Report</h1>
    <div style="font-size: 12px; color: #666; margin-top: 4px;">As of Date: <?= rpt_date($today) ?></div>
</div>

<?php if (!$ar_ok): ?>
<div class="bs-recon-warn" style="text-align:center;padding:8px 20px;margin:6px auto 16px auto;max-width:1000px;background:#fff3cd;color:#856404;font-weight:600;border-radius:6px;font-size:12px">
  <i class="fas fa-exclamation-circle"></i> AR RECONCILIATION ERROR — Subledger: <?= rpt_currency($total_due) ?> | GL: <?= rpt_currency($ar_gl) ?> | Diff: <?= rpt_currency(abs($total_due - $ar_gl)) ?>
</div>
<?php endif; ?>

<div class="rpt-summary">
  <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_due) ?></div><div class="lbl">Total Receivables</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_30) ?></div><div class="lbl">0 - 30 Days (Current)</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_60) ?></div><div class="lbl">31 - 60 Days</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#b7791f"><?= rpt_currency($total_90) ?></div><div class="lbl">61 - 90 Days</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_over_90) ?></div><div class="lbl">91+ Days</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-ar-aging">
      <thead>
        <tr>
          <th>Customer Name</th>
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
            <td style="font-weight:600;"><?= htmlspecialchars($r['customer_name']) ?></td>
            <td style="text-align:right; font-weight:700; color:#003087;"><?= rpt_currency($r['total_due']) ?></td>
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
