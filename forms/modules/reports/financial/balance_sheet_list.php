<?php
/**
 * Balance Sheet Report
 * ─────────────────────────────────────────────────────────────
 * AUTHORITATIVE SOURCE: ReportingEngine.php → re_get_balance_sheet()
 *
 * Rules:
 *  - Assets, Liabilities, Equity are ALL derived from account_type in COA.
 *  - Cash vs Bank split: account_subtype 'Cash' = Cash on Hand; 'Bank' = Bank/Digital.
 *  - No hardcoded account IDs or names.
 *  - Net Income comes from re_get_pnl() (same engine as P&L report).
 *  - Balance Sheet MUST show reconciliation error banner if Assets ≠ Liabilities + Equity.
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';
require_once 'api/ReportingEngine.php';

$db = db();

$fy       = rpt_get_current_fiscal_year_dates();
$today    = date('Y-m-d');
$date_to  = $_GET['date_to'] ?? $_GET['as_of'] ?? date('Y-m-d');
$user_loc = function_exists('get_user_default_location_id') ? get_user_default_location_id() : '';
$location_id = $_GET['location_id'] ?? ($user_loc ?: ($_SESSION['location_id'] ?? null));

$as_of = $date_to;

// ── Validation: Check As-Of Date ──
$earliest_row = $db->fetchOne("SELECT MIN(txn_date) as min_date FROM transaction_headers WHERE is_deleted = 0 AND status NOT IN ('void', 'voided', 'draft')");
$min_date = $earliest_row['min_date'] ?? '2020-01-01';
$date_warning = '';
if ($as_of < $min_date) {
    $date_warning = "Selected As Of Date (" . htmlspecialchars($as_of) . ") is prior to the earliest transaction record in the system (" . htmlspecialchars($min_date) . ").";
}

// ── Central Engine Call (Point-in-Time Statement) ─────────────
$bs = re_get_balance_sheet($db, $as_of, $location_id);

// ── Classify assets by subtype (exactly matching actual COA subtypes) ───
// COA actual subtypes: Cash, Bank, Accounts Receivable, Inventory Asset,
//                      Fixed Asset, Contra Asset, Other Current Asset
$cash_subtypes         = ['Cash'];                   // Only account_subtype = 'Cash'
$bank_subtypes         = ['Bank'];                   // Only account_subtype = 'Bank'
$inv_subtypes          = ['Inventory Asset'];         // Only account_subtype = 'Inventory Asset'
$ar_subtypes           = ['Accounts Receivable'];     // Only account_subtype = 'Accounts Receivable'
$fixed_asset_subtypes  = ['Fixed Asset'];             // Property/Equipment
$contra_subtypes       = ['Contra Asset'];            // Accumulated Depreciation (deducted)

$cash_on_hand   = 0; $bank_balance  = 0; $ar_balance = 0;
$inventory_val  = 0; $fixed_assets  = 0; $contra_assets = 0;
$other_assets   = [];

foreach ($bs['assets'] as $a) {
    $sub  = $a['subtype'] ?? '';
    $name = $a['name'] ?? '';
    if (in_array($sub, $cash_subtypes)) {
        $cash_on_hand += $a['balance'];
    } elseif (in_array($sub, $bank_subtypes)) {
        $bank_balance += $a['balance'];
    } elseif (in_array($sub, $ar_subtypes)) {
        $ar_balance += $a['balance'];
    } elseif (in_array($sub, $inv_subtypes)) {
        $inventory_val += $a['balance'];
    } elseif (in_array($sub, $contra_subtypes) || stripos($name, 'deprec') !== false) {
        $contra_assets += abs($a['balance']);
    } elseif (in_array($sub, $fixed_asset_subtypes)) {
        $fixed_assets += $a['balance'];
    } else {
        $other_assets[] = $a;
    }
}

$net_fixed_assets     = $fixed_assets - $contra_assets; // Net Book Value
$total_current_assets = $cash_on_hand + $bank_balance + $ar_balance + $inventory_val;
$total_other_assets   = array_sum(array_column($other_assets, 'balance')) + $fixed_assets - $contra_assets;
$total_assets         = $bs['total_assets'];

// ── Liabilities: AP vs Tax/VAT vs Other ──────────────────────
$ap_subtypes  = ['Accounts Payable', 'payable'];
$tax_keywords = ['VAT', 'Tax', 'Duty', 'Excise'];

$ap_balance     = 0; $tax_payable    = 0; $other_liabilities = [];

foreach ($bs['liabilities'] as $l) {
    $sub  = $l['subtype'] ?? '';
    $name = $l['name'] ?? '';
    if (in_array($sub, $ap_subtypes)) {
        $ap_balance += $l['balance'];
    } else {
        $is_tax = false;
        foreach ($tax_keywords as $kw) {
            if (stripos($name, $kw) !== false || stripos($sub, $kw) !== false) {
                $is_tax = true; break;
            }
        }
        if ($is_tax) {
            $tax_payable += $l['balance'];
        } else {
            $other_liabilities[] = $l;
        }
    }
}

$total_liabilities = $bs['total_liabilities'];

// ── Equity ───────────────────────────────────────────────────
$total_equity_accts = $bs['total_equity_accts'];
$net_income         = $bs['net_income']; // Cumulative Net Income from central engine
$total_equity       = $bs['total_equity'];
$total_liab_equity  = $bs['total_liab_equity'];
$difference         = $bs['difference'];
$is_balanced        = $bs['is_balanced'];

// ── AR / AP / Inventory Reconciliation ───────────────────────
$ar_subledger   = re_get_ar_balance($db, $as_of, $location_id);
$ar_gl          = re_get_ar_gl_balance($db, $as_of, $location_id);
$ar_ok          = abs($ar_subledger - $ar_gl) < 0.05;

$ap_subledger   = re_get_ap_balance($db, $as_of, $location_id);
$ap_gl          = re_get_ap_gl_balance($db, $as_of, $location_id);
$ap_ok          = abs($ap_subledger - $ap_gl) < 0.05;

$inv_subledger  = re_get_inventory_subledger($db, $as_of, $location_id);
$inv_gl         = re_get_inventory_gl_balance($db, $as_of, $location_id);
$inv_diff       = abs($inv_subledger - $inv_gl);
$inv_ok         = ($inv_diff < 0.05);
$inv_tolerated  = ($inv_diff <= 500.00) || ($inv_subledger > 0 && ($inv_diff / $inv_subledger) <= 0.002);
?>
<style>
.bs-grid      { display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1000px;margin:0 auto }
@media(max-width:700px){ .bs-grid{grid-template-columns:1fr} }
.bs-card      { background:#fff;border:1px solid #dde2e8;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06) }
.bs-head      { text-align:center;padding:18px 20px;border-bottom:2px solid #003087;background:#f8f9fa }
.bs-head-title{ font-size:16px;font-weight:800;color:#003087;letter-spacing:.5px }
.bs-head-sub  { font-size:11px;color:#888;margin-top:3px }
.bs-section   { background:#003087;color:#fff;padding:9px 20px;font-weight:700;font-size:12px;letter-spacing:.8px }
.bs-row       { display:flex;justify-content:space-between;align-items:center;padding:9px 20px;border-bottom:1px solid #f4f5f7;font-size:13px;color:#333 }
.bs-row:hover { background:#f8f9fa }
.bs-subtotal  { display:flex;justify-content:space-between;align-items:center;padding:11px 20px;font-weight:700;background:#eef2ff;border-top:2px solid #c8d3f5;font-size:13px;color:#003087 }
.bs-total     { display:flex;justify-content:space-between;align-items:center;padding:13px 20px;font-weight:900;font-size:15px;background:#003087;color:#fff }
.bs-balance-ok  { text-align:center;padding:12px 20px;margin:14px auto;max-width:1000px;background:#d4edda;color:#1a7f37;font-weight:700;border-radius:6px;font-size:13px }
.bs-balance-err { text-align:center;padding:12px 20px;margin:14px auto;max-width:1000px;background:#f8d7da;color:#842029;font-weight:700;border-radius:6px;font-size:13px }
.bs-recon-warn  { text-align:center;padding:8px 20px;margin:6px auto;max-width:1000px;background:#fff3cd;color:#856404;font-weight:600;border-radius:6px;font-size:12px }
@media print { .bs-grid{grid-template-columns:1fr 1fr!important} }
</style>

<?php rpt_filter_bar('Balance Sheet', [
    ['name' => 'date_to', 'label' => 'As Of Date', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], ''); ?>

<?php if ($date_warning): ?>
<div class="bs-recon-warn">
  <i class="fas fa-info-circle"></i> <?= $date_warning ?>
</div>
<?php endif; ?>

<!-- Balance Status Banner -->
<?php if (!$is_balanced): ?>
<div class="bs-balance-err">
  <i class="fas fa-exclamation-triangle"></i>
  BALANCE SHEET OUT OF BALANCE — Discrepancy of <?= rpt_currency($difference) ?>.
  Total Assets: <?= rpt_currency($total_assets) ?> | Total Liabilities+Equity: <?= rpt_currency($total_liab_equity) ?>
</div>
<?php endif; ?>

<!-- Subledger Reconciliation Warnings -->
<?php if (!$ar_ok): ?>
<div class="bs-recon-warn"><i class="fas fa-exclamation-circle"></i> AR RECONCILIATION ERROR — Subledger: <?= rpt_currency($ar_subledger) ?> | GL: <?= rpt_currency($ar_gl) ?> | Diff: <?= rpt_currency(abs($ar_subledger - $ar_gl)) ?></div>
<?php endif; ?>
<?php if (!$ap_ok): ?>
<div class="bs-recon-warn"><i class="fas fa-exclamation-circle"></i> AP RECONCILIATION ERROR — Subledger: <?= rpt_currency($ap_subledger) ?> | GL: <?= rpt_currency($ap_gl) ?> | Diff: <?= rpt_currency(abs($ap_subledger - $ap_gl)) ?></div>
<?php endif; ?>
<?php if (!$inv_tolerated): ?>
<div class="bs-recon-warn"><i class="fas fa-exclamation-circle"></i> INVENTORY RECONCILIATION ERROR — Subledger: <?= rpt_currency($inv_subledger) ?> | GL: <?= rpt_currency($inv_gl) ?> | Diff: <?= rpt_currency(abs($inv_subledger - $inv_gl)) ?></div>
<?php endif; ?>

<div class="bs-grid">
  <!-- LEFT: ASSETS -->
  <div class="bs-card">
    <div class="bs-head">
      <div class="bs-head-title">ASSETS</div>
      <div class="bs-head-sub">As of <?= rpt_date($as_of) ?></div>
    </div>

    <div class="bs-section">CURRENT ASSETS</div>
    <div class="bs-row">
      <span><i class="fas fa-coins" style="color:#9a6700;margin-right:6px"></i>Cash on Hand</span>
      <span><?= rpt_currency($cash_on_hand) ?></span>
    </div>
    <div class="bs-row">
      <span><i class="fas fa-university" style="color:#003087;margin-right:6px"></i>Bank / Digital Balance</span>
      <span><?= rpt_currency($bank_balance) ?></span>
    </div>
    <div class="bs-row">
      <span><i class="fas fa-file-invoice-dollar" style="color:#1a7f37;margin-right:6px"></i>
        Accounts Receivable (AR)
        <?php if (!$ar_ok): ?><span title="AR RECONCILIATION ERROR" style="color:#c00;font-size:10px;margin-left:4px">⚠</span><?php endif; ?>
      </span>
      <span style="color:<?= $ar_balance>0?'#1a7f37':'#888' ?>;font-weight:600"><?= rpt_currency($ar_balance) ?></span>
    </div>
    <div class="bs-row">
      <span><i class="fas fa-boxes" style="color:#6f42c1;margin-right:6px"></i>
        Inventory (at Cost)
        <?php if (!$inv_ok): ?><span title="INVENTORY RECONCILIATION ERROR" style="color:#c00;font-size:10px;margin-left:4px">⚠</span><?php endif; ?>
      </span>
      <span><?= rpt_currency($inventory_val) ?></span>
    </div>
    <div class="bs-subtotal">
      <span>Total Current Assets</span>
      <span><?= rpt_currency($total_current_assets) ?></span>
    </div>

    <?php if ($fixed_assets > 0 || $contra_assets > 0 || !empty($other_assets)): ?>
    <div class="bs-section">NON-CURRENT &amp; OTHER ASSETS</div>
    <?php foreach ($other_assets as $oa): ?>
    <div class="bs-row">
      <span><i class="fas fa-layer-group" style="color:#4a5568;margin-right:6px"></i><?= htmlspecialchars($oa['name']) ?></span>
      <span><?= rpt_currency($oa['balance']) ?></span>
    </div>
    <?php endforeach; ?>
    <?php if ($fixed_assets > 0): ?>
    <div class="bs-row">
      <span><i class="fas fa-building" style="color:#4a5568;margin-right:6px"></i>Fixed Assets (Equipment)</span>
      <span><?= rpt_currency($fixed_assets) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($contra_assets > 0): ?>
    <div class="bs-row" style="color:#c00">
      <span><i class="fas fa-minus-circle" style="color:#c00;margin-right:6px"></i>Less: Accumulated Depreciation</span>
      <span style="font-weight:600">( <?= rpt_currency($contra_assets) ?> )</span>
    </div>
    <?php endif; ?>
    <div class="bs-subtotal">
      <span>Total Non-Current &amp; Other Assets</span>
      <span><?= rpt_currency($total_other_assets) ?></span>
    </div>
    <?php endif; ?>


    <div class="bs-total">
      <span>TOTAL ASSETS</span>
      <span><?= rpt_currency($total_assets) ?></span>
    </div>
  </div>

  <!-- RIGHT: LIABILITIES + EQUITY -->
  <div class="bs-card">
    <div class="bs-head">
      <div class="bs-head-title">LIABILITIES &amp; EQUITY</div>
      <div class="bs-head-sub">As of <?= rpt_date($as_of) ?></div>
    </div>

    <div class="bs-section">CURRENT LIABILITIES</div>
    <div class="bs-row">
      <span><i class="fas fa-file-invoice" style="color:#c00;margin-right:6px"></i>
        Accounts Payable (AP)
        <?php if (!$ap_ok): ?><span title="AP RECONCILIATION ERROR" style="color:#c00;font-size:10px;margin-left:4px">⚠</span><?php endif; ?>
      </span>
      <span style="color:<?= $ap_balance>0?'#c00':'#888' ?>;font-weight:600"><?= rpt_currency($ap_balance) ?></span>
    </div>
    <?php if ($tax_payable != 0): ?>
    <div class="bs-row">
      <span><i class="fas fa-percent" style="color:#e67e22;margin-right:6px"></i><?= $tax_payable < 0 ? 'Tax Credit / VAT Receivable' : 'Tax / VAT Payable' ?></span>
      <span style="color:<?= $tax_payable>0?'#e67e22':'#c00' ?>;font-weight:600"><?= $tax_payable < 0 ? '( '.rpt_currency(abs($tax_payable)).' )' : rpt_currency($tax_payable) ?></span>
    </div>
    <?php endif; ?>
    <?php foreach ($other_liabilities as $ol): 
      $ol_name = $ol['name'];
      $is_neg  = ($ol['balance'] < 0);
    ?>
    <div class="bs-row">
      <span><i class="fas fa-wallet" style="color:#c00;margin-right:6px"></i><?= htmlspecialchars($ol_name) ?></span>
      <span style="color:<?= $ol['balance']>0?'#c00':'#1a7f37' ?>;font-weight:600"><?= $is_neg ? '( '.rpt_currency(abs($ol['balance'])).' )' : rpt_currency($ol['balance']) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="bs-subtotal" style="background:#fdf2f2;border-top-color:#e53e3e">
      <span>Total Liabilities</span>
      <span style="color:<?= $total_liabilities>=0?'#c00':'#1a7f37' ?>"><?= $total_liabilities < 0 ? '( '.rpt_currency(abs($total_liabilities)).' )' : rpt_currency($total_liabilities) ?></span>
    </div>

    <div class="bs-section">EQUITY</div>
    <?php foreach ($bs['equity'] as $eq): 
      $eq_neg = ($eq['balance'] < 0);
    ?>
    <div class="bs-row">
      <span><i class="fas fa-coins" style="color:#6f42c1;margin-right:6px"></i><?= htmlspecialchars($eq['name']) ?></span>
      <span style="color:<?= $eq['balance']>=0?'#1a7f37':'#c00' ?>;font-weight:600"><?= $eq_neg ? '( '.rpt_currency(abs($eq['balance'])).' )' : rpt_currency($eq['balance']) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="bs-row">
      <span><i class="fas fa-chart-line" style="color:<?= $net_income>=0?'#1a7f37':'#c00' ?>;margin-right:6px"></i>Cumulative Net <?= $net_income>=0?'Profit':'Loss' ?> (As of <?= rpt_date($as_of) ?>)</span>
      <span style="color:<?= $net_income>=0?'#1a7f37':'#c00' ?>;font-weight:700"><?= $net_income < 0 ? '( '.rpt_currency(abs($net_income)).' )' : rpt_currency($net_income) ?></span>
    </div>
    <div class="bs-subtotal" style="background:#f0fff4;border-top-color:#1a7f37">
      <span>Total Equity</span>
      <span style="color:<?= $total_equity>=0?'#1a7f37':'#c00' ?>"><?= $total_equity < 0 ? '( '.rpt_currency(abs($total_equity)).' )' : rpt_currency($total_equity) ?></span>
    </div>

    <div class="bs-total">
      <span>TOTAL LIABILITIES + EQUITY</span>
      <span><?= rpt_currency($total_liab_equity) ?></span>
    </div>
  </div>
</div>

<?php if ($is_balanced): ?>
  <div class="bs-balance-ok"><i class="fas fa-check-circle"></i> Balance Sheet is BALANCED — Assets = Liabilities + Equity (<?= rpt_currency($total_assets) ?>)</div>
<?php else: ?>
  <div class="bs-balance-err">
    <i class="fas fa-exclamation-triangle"></i>
    BALANCE SHEET OUT OF BALANCE — Discrepancy of <?= rpt_currency($difference) ?>.
    Do not adjust manually — check journal entries for unbalanced postings.
  </div>
<?php endif; ?>
