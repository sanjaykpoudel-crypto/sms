<?php
/**
 * Trial Balance Report
 * ─────────────────────────────────────────────────────────────
 * AUTHORITATIVE SOURCE: ReportingEngine.php → re_get_trial_balance()
 * Shows Opening Balance | Period Movement | Closing Balance
 * Total Debits MUST equal Total Credits (verified and displayed).
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';
require_once 'api/ReportingEngine.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;
$user_loc  = function_exists('get_user_default_location_id') ? get_user_default_location_id() : '';
$location_id = $_GET['location_id'] ?? ($user_loc ?: ($_SESSION['location_id'] ?? null));

// ── Central Engine Call ──────────────────────────────────────
$tb      = re_get_trial_balance($db, $date_from, $date_to, $location_id);
$rows    = $tb['rows'];
$totals  = $tb['totals'];
$is_balanced = $tb['is_balanced'];

$type_colors = [
    'asset'     => '#003087',
    'liability' => '#c00',
    'income'    => '#1a7f37',
    'expense'   => '#9a6700',
    'equity'    => '#6f42c1',
];
?>
<style>
  .tb-balance-ok  { text-align:center;padding:12px 20px;background:#d4edda;color:#1a7f37;font-weight:700;border-radius:6px;font-size:13px;margin-top:16px }
  .tb-balance-err { text-align:center;padding:12px 20px;background:#f8d7da;color:#842029;font-weight:700;border-radius:6px;font-size:13px;margin-top:16px }
  .tb-group-hdr   { background:#f1f5f9;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#64748b;padding:6px 12px;border-top:2px solid #e2e8f0 }
  @media print { .no-print { display:none!important } }
</style>

<?php rpt_filter_bar('Trial Balance', [
  ['name' => 'date_from', 'label' => 'From Date', 'type' => 'date', 'default' => $date_from],
  ['name' => 'date_to',   'label' => 'To Date',   'type' => 'date', 'default' => $date_to],
  rpt_location_filter(),
], 'tbl-trial'); ?>

<!-- Summary Cards -->
<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap">
  <div class="rpt-summary-card" style="flex:1;min-width:160px">
    <div class="val"><?= rpt_currency($totals['closing_dr']) ?></div>
    <div class="lbl">Total Debit (Closing)</div>
  </div>
  <div class="rpt-summary-card" style="flex:1;min-width:160px">
    <div class="val"><?= rpt_currency($totals['closing_cr']) ?></div>
    <div class="lbl">Total Credit (Closing)</div>
  </div>
  <div class="rpt-summary-card" style="flex:1;min-width:160px">
    <div class="val" style="color:<?= $is_balanced ? '#1a7f37' : '#c00' ?>"><?= rpt_currency(abs($totals['closing_dr'] - $totals['closing_cr'])) ?></div>
    <div class="lbl"><?= $is_balanced ? '✓ Balanced' : '⚠ Difference' ?></div>
  </div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content" style="padding:0">
    <table class="ns-table" id="tbl-trial">
      <thead>
        <tr>
          <th>Account Name</th>
          <th style="width:80px">Type</th>
          <th style="text-align:right;width:130px">Opening Dr</th>
          <th style="text-align:right;width:130px">Opening Cr</th>
          <th style="text-align:right;width:130px">Period Dr</th>
          <th style="text-align:right;width:130px">Period Cr</th>
          <th style="text-align:right;width:130px">Closing Dr</th>
          <th style="text-align:right;width:130px">Closing Cr</th>
        </tr>
      </thead>
      <tbody>
        <?php
        // Group rows by account type for readability
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['account_type']][] = $r;
        }
        $group_order = ['asset', 'liability', 'equity', 'income', 'expense'];
        $group_labels = [
            'asset'     => 'Assets',
            'liability' => 'Liabilities',
            'equity'    => 'Equity',
            'income'    => 'Income / Revenue',
            'expense'   => 'Expenses',
        ];
        foreach ($group_order as $gtype):
            if (empty($groups[$gtype])) continue;
            $tc = $type_colors[$gtype] ?? '#888';
        ?>
          <tr><td colspan="8" class="tb-group-hdr" style="color:<?= $tc ?>"><?= $group_labels[$gtype] ?></td></tr>
          <?php foreach ($groups[$gtype] as $r): ?>
          <tr>
            <td style="font-weight:600;padding-left:20px"><?= htmlspecialchars($r['account_name']) ?></td>
            <td><span style="background:<?= $tc ?>;color:#fff;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:600"><?= ucfirst($r['account_type']) ?></span></td>
            <td style="text-align:right;color:<?= $r['opening_dr']>0?'#003087':'#ccc' ?>;font-weight:600"><?= $r['opening_dr']>0 ? rpt_currency($r['opening_dr']) : '—' ?></td>
            <td style="text-align:right;color:<?= $r['opening_cr']>0?'#c00':'#ccc' ?>;font-weight:600"><?= $r['opening_cr']>0 ? rpt_currency($r['opening_cr']) : '—' ?></td>
            <td style="text-align:right;color:<?= $r['period_dr']>0?'#003087':'#ccc' ?>;font-weight:600"><?= $r['period_dr']>0 ? rpt_currency($r['period_dr']) : '—' ?></td>
            <td style="text-align:right;color:<?= $r['period_cr']>0?'#c00':'#ccc' ?>;font-weight:600"><?= $r['period_cr']>0 ? rpt_currency($r['period_cr']) : '—' ?></td>
            <td style="text-align:right;color:<?= $r['closing_dr']>0?'#003087':'#ccc' ?>;font-weight:700"><?= $r['closing_dr']>0 ? rpt_currency($r['closing_dr']) : '—' ?></td>
            <td style="text-align:right;color:<?= $r['closing_cr']>0?'#c00':'#ccc' ?>;font-weight:700"><?= $r['closing_cr']>0 ? rpt_currency($r['closing_cr']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <!-- Period totals row -->
        <tr style="background:#f1f5f9;font-weight:700;font-size:12px;color:#64748b">
          <td colspan="2" style="padding:10px 16px">Period Subtotals</td>
          <td style="text-align:right;padding:10px 16px"><?= rpt_currency($totals['opening_dr']) ?></td>
          <td style="text-align:right;padding:10px 16px"><?= rpt_currency($totals['opening_cr']) ?></td>
          <td style="text-align:right;padding:10px 16px"><?= rpt_currency($totals['period_dr']) ?></td>
          <td style="text-align:right;padding:10px 16px"><?= rpt_currency($totals['period_cr']) ?></td>
          <td style="text-align:right;padding:10px 16px"><?= rpt_currency($totals['closing_dr']) ?></td>
          <td style="text-align:right;padding:10px 16px"><?= rpt_currency($totals['closing_cr']) ?></td>
        </tr>
        <!-- Grand total row -->
        <tr style="background:#003087;color:#fff;font-weight:900;font-size:14px">
          <td colspan="6" style="padding:12px 16px">CLOSING TOTALS</td>
          <td style="text-align:right;padding:12px 16px"><?= rpt_currency($totals['closing_dr']) ?></td>
          <td style="text-align:right;padding:12px 16px"><?= rpt_currency($totals['closing_cr']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php if ($is_balanced): ?>
  <div class="tb-balance-ok"><i class="fas fa-check-circle"></i> Trial Balance is BALANCED — Total Debits = Total Credits (<?= rpt_currency($totals['closing_dr']) ?>)</div>
<?php else:
  $diff = abs($totals['closing_dr'] - $totals['closing_cr']);
?>
  <div class="tb-balance-err">
    <i class="fas fa-exclamation-triangle"></i>
    TRIAL BALANCE OUT OF BALANCE — Discrepancy of <?= rpt_currency($diff) ?>.
    Check for unposted or incomplete journal entries.
  </div>
<?php endif; ?>