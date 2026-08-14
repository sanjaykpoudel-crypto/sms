<?php
/**
 * Income Statement (Profit & Loss) Report
 * ─────────────────────────────────────────────────────────────
 * AUTHORITATIVE SOURCE: ReportingEngine.php → re_get_pnl()
 *
 * STRICTLY period-based:
 *   - Revenue, COGS, and Expenses are taken ONLY from the selected date range.
 *   - Prior fiscal year income/expense balances are NEVER carried forward.
 *   - Net Profit here reconciles with the Current Period Net Income line on the Balance Sheet.
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
$pnl = re_get_pnl($db, $date_from, $date_to, $location_id);
?>
<style>
.is-section  { background:#003087;color:#fff;padding:8px 16px;font-weight:700;font-size:13px;letter-spacing:.5px }
.is-row      { display:flex;justify-content:space-between;padding:8px 16px;border-bottom:1px solid #f0f0f0;font-size:13px }
.is-row:hover{ background:#f8f9fa }
.is-subtotal { display:flex;justify-content:space-between;padding:10px 16px;font-weight:700;background:#eef2ff;font-size:13px;border-top:2px solid #003087 }
.is-total    { display:flex;justify-content:space-between;padding:14px 16px;font-weight:900;font-size:15px;border-top:3px double #003087 }
.is-recon    { text-align:center;padding:10px 20px;border-radius:6px;font-weight:700;font-size:12px;margin-top:12px }
</style>

<?php rpt_filter_bar('Income Statement (P&L)', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to',   'label' => 'To',   'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], ''); ?>

<!-- Summary Cards -->
<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap">
  <div class="rpt-summary-card" style="flex:1;min-width:140px">
    <div class="val"><?= rpt_currency($pnl['total_revenue']) ?></div>
    <div class="lbl">Total Revenue</div>
  </div>
  <div class="rpt-summary-card" style="flex:1;min-width:140px">
    <div class="val" style="color:#9a6700"><?= rpt_currency($pnl['total_cogs']) ?></div>
    <div class="lbl">Total COGS</div>
  </div>
  <div class="rpt-summary-card" style="flex:1;min-width:140px">
    <div class="val" style="color:<?= $pnl['gross_profit']>=0?'#1a7f37':'#c00' ?>"><?= rpt_currency($pnl['gross_profit']) ?></div>
    <div class="lbl">Gross Profit</div>
  </div>
  <div class="rpt-summary-card" style="flex:1;min-width:140px">
    <div class="val" style="color:#c00"><?= rpt_currency($pnl['total_expenses']) ?></div>
    <div class="lbl">Total Expenses</div>
  </div>
  <div class="rpt-summary-card" style="flex:1;min-width:140px;border-color:<?= $pnl['is_profit']?'#1a7f37':'#c00' ?>">
    <div class="val" style="color:<?= $pnl['is_profit']?'#1a7f37':'#c00' ?>"><?= rpt_currency(abs($pnl['net_profit'])) ?></div>
    <div class="lbl"><?= $pnl['is_profit'] ? 'Net Profit' : 'Net Loss' ?></div>
  </div>
</div>

<div class="ns-portlet" style="max-width:760px;margin:0 auto">
  <div class="ns-portlet-content" style="padding:0">

    <!-- REVENUE -->
    <div class="is-section"><i class="fas fa-arrow-trend-up" style="margin-right:8px"></i>REVENUE</div>
    <?php if (empty($pnl['revenue_rows'])): ?>
      <div class="is-row"><span style="color:#888;font-style:italic">No revenue recorded for this period.</span><span>Rs 0.00</span></div>
    <?php else: foreach ($pnl['revenue_rows'] as $r): ?>
      <div class="is-row"><span><?= htmlspecialchars($r['account_name']) ?></span><span><?= rpt_currency($r['amount']) ?></span></div>
    <?php endforeach; endif; ?>
    <div class="is-subtotal"><span>Total Revenue</span><span><?= rpt_currency($pnl['total_revenue']) ?></span></div>

    <!-- COGS -->
    <div class="is-section"><i class="fas fa-box" style="margin-right:8px"></i>COST OF GOODS SOLD</div>
    <?php if (empty($pnl['cogs_rows'])): ?>
      <div class="is-row"><span style="color:#888;font-style:italic">No COGS recorded for this period.</span><span>Rs 0.00</span></div>
    <?php else: foreach ($pnl['cogs_rows'] as $r): ?>
      <div class="is-row"><span><?= htmlspecialchars($r['account_name']) ?></span><span style="color:#9a6700"><?= rpt_currency($r['amount']) ?></span></div>
    <?php endforeach; endif; ?>
    <div class="is-subtotal"><span>Total COGS</span><span style="color:#9a6700"><?= rpt_currency($pnl['total_cogs']) ?></span></div>

    <!-- GROSS PROFIT -->
    <div class="is-subtotal" style="background:#d1ecf1;color:#0c5460;font-size:15px;border-color:#bee5eb">
      <span>GROSS PROFIT</span>
      <span style="color:<?= $pnl['gross_profit']>=0?'#1a7f37':'#c00' ?>"><?= rpt_currency($pnl['gross_profit']) ?></span>
    </div>

    <!-- OPERATING EXPENSES -->
    <div class="is-section"><i class="fas fa-minus-circle" style="margin-right:8px"></i>OPERATING EXPENSES</div>
    <?php if (empty($pnl['expense_rows'])): ?>
      <div class="is-row"><span style="color:#888;font-style:italic">No expenses recorded for this period.</span><span>Rs 0.00</span></div>
    <?php else: foreach ($pnl['expense_rows'] as $r): ?>
      <div class="is-row"><span><?= htmlspecialchars($r['account_name']) ?></span><span style="color:#c00"><?= rpt_currency($r['amount']) ?></span></div>
    <?php endforeach; endif; ?>
    <div class="is-subtotal"><span>Total Operating Expenses</span><span style="color:#c00"><?= rpt_currency($pnl['total_expenses']) ?></span></div>

    <!-- NET PROFIT / LOSS -->
    <div class="is-total" style="background:<?= $pnl['is_profit']?'#d4edda':'#f8d7da' ?>">
      <span style="font-size:16px"><?= $pnl['is_profit'] ? '✓ NET PROFIT' : '✗ NET LOSS' ?></span>
      <span style="color:<?= $pnl['is_profit']?'#1a7f37':'#c00' ?>;font-size:20px"><?= rpt_currency(abs($pnl['net_profit'])) ?></span>
    </div>

  </div>
</div>

<div class="is-recon" style="background:#f0f4ff;color:#003087;max-width:760px;margin:10px auto 0 auto">
  <i class="fas fa-info-circle"></i>
  This report is period-based (<?= rpt_date($date_from) ?> – <?= rpt_date($date_to) ?>).
  Net <?= $pnl['is_profit'] ? 'Profit' : 'Loss' ?> of <?= rpt_currency(abs($pnl['net_profit'])) ?> reconciles with <strong>Current Period Net Income</strong> on the Balance Sheet.
  Prior fiscal year revenue/expenses are not included.
</div>
