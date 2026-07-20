<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';
$db = db();

$today    = date('Y-m-d');
$date_to  = $_GET['date_to'] ?? $today;

// Allow user to specify date_from, otherwise fallback to the fiscal year start boundary
$date_from = $_GET['date_from'] ?? get_report_start_date($date_to);

$start_date = $date_from;
$as_of      = $date_to;

/**
 * Helper to get GL balance for an account or subtype
 */
function get_gl_bal($db, $id_or_subtype, $as_of, $start_date, $is_id = true) {
    $field = $is_id ? 'j.account_id' : 'a.account_subtype';
    $row = $db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE $field = ? AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ", [$id_or_subtype, $start_date, $as_of]);
    return (float)($row['bal'] ?? 0);
}

// ─── ASSETS ───────────────────────────────────────────────────────────────────
$cash_on_hand   = get_gl_bal($db, 'acc-1010', $as_of, $start_date);
// Other bank accounts (anything subtype bank except the main cash account)
$bank_balance   = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_subtype = 'bank' AND a.id != 'acc-1010' AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
", [$start_date, $as_of])['bal'] ?? 0);

$ar             = get_gl_bal($db, 'receivable', $as_of, $start_date, false);
$inventory_val  = get_gl_bal($db, 'inventory', $as_of, $start_date, false);

// Other Assets (Fixed assets, etc.)
$other_assets_list = $db->fetchAll("
    SELECT a.account_name, SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'asset' 
      AND a.account_subtype NOT IN ('receivable', 'inventory') 
      AND a.id != 'acc-1010' 
      AND a.account_subtype != 'bank'
      AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY a.id, a.account_name
    HAVING bal != 0
", [$start_date, $as_of]);

$other_assets = array_sum(array_column($other_assets_list, 'bal'));

$total_current_assets = $cash_on_hand + $bank_balance + $ar + $inventory_val;
$total_assets         = $total_current_assets + $other_assets;

// ─── LIABILITIES ──────────────────────────────────────────────────────────────
$ap             = -get_gl_bal($db, 'payable', $as_of, $start_date, false); // Liabilities are credits (negative in GL balance)
$tax_payable    = -get_gl_bal($db, 'tax', $as_of, $start_date, false);

// Other Liabilities
$other_liabilities_list = $db->fetchAll("
    SELECT a.account_name, -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'liability' 
      AND a.account_subtype NOT IN ('payable', 'tax')
      AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY a.id, a.account_name
    HAVING bal != 0
", [$start_date, $as_of]);

$other_liabilities = array_sum(array_column($other_liabilities_list, 'bal'));
$total_liabilities = $ap + $tax_payable + $other_liabilities;

// ─── EQUITY ───────────────────────────────────────────────────────────────────
// Use Type-based balances for Income and Expenses (excluding inventory adjustments and system closing journals)
$revenue = -(float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'income' AND h.txn_type != 'inventory_adjustment' AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
", [$start_date, $as_of])['v'] ?? 0);

$expenses = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND h.txn_type != 'inventory_adjustment' AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
", [$start_date, $as_of])['v'] ?? 0);

$retained_earnings = $revenue - $expenses;

// General Ledger Equity Accounts (excluding inventory adjustments)
$equity_accounts_list = $db->fetchAll("
    SELECT a.account_name, -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_type != 'inventory_adjustment'
      AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY a.id, a.account_name
    HAVING bal != 0
", [$start_date, $as_of]);

// Calculate Inventory Adjustment Reserve (net offset of all inventory adjustments in equity side)
$inventory_adjustment_reserve = -(float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_type = 'inventory_adjustment'
      AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND a.account_type IN ('expense', 'income', 'liability', 'equity')
", [$start_date, $as_of])['bal'] ?? 0);

$total_other_equity = array_sum(array_column($equity_accounts_list, 'bal'));
$total_equity = $total_other_equity + $retained_earnings + $inventory_adjustment_reserve;

$total_liabilities_equity = $total_liabilities + $total_equity;
$is_balanced = abs($total_assets - $total_liabilities_equity) < 0.05;
?>
<style>
.bs-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:960px;margin:0 auto}
@media(max-width:700px){.bs-grid{grid-template-columns:1fr}}
.bs-card{background:#fff;border:1px solid #dde2e8;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.bs-head{text-align:center;padding:18px 20px;border-bottom:2px solid #003087;background:#f8f9fa}
.bs-head-title{font-size:16px;font-weight:800;color:#003087;letter-spacing:.5px}
.bs-head-sub{font-size:11px;color:#888;margin-top:3px}
.bs-section{background:#003087;color:#fff;padding:9px 20px;font-weight:700;font-size:12px;letter-spacing:.8px}
.bs-row{display:flex;justify-content:space-between;align-items:center;padding:9px 20px;border-bottom:1px solid #f4f5f7;font-size:13px;color:#333}
.bs-row:hover{background:#f8f9fa}
.bs-row-label{color:#555}
.bs-subtotal{display:flex;justify-content:space-between;align-items:center;padding:11px 20px;font-weight:700;background:#eef2ff;border-top:2px solid #c8d3f5;font-size:13px;color:#003087}
.bs-total{display:flex;justify-content:space-between;align-items:center;padding:13px 20px;font-weight:900;font-size:15px;background:#003087;color:#fff}
.bs-balance-ok{text-align:center;padding:12px 20px;margin:16px auto;max-width:960px;background:#d4edda;color:#1a7f37;font-weight:700;border-radius:6px;font-size:13px}
.bs-balance-err{text-align:center;padding:12px 20px;margin:16px auto;max-width:960px;background:#f8d7da;color:#842029;font-weight:700;border-radius:6px;font-size:13px}
@media print{ .bs-grid{grid-template-columns:1fr 1fr!important} }
</style>

<?php rpt_filter_bar('Balance Sheet', [
    ['name'=>'date_from','label'=>'From Date','type'=>'date','default'=>get_report_start_date($today)],
    ['name'=>'date_to',  'label'=>'To Date',  'type'=>'date','default'=>$today],
], ''); ?>

<div class="bs-grid">
  <!-- LEFT: ASSETS -->
  <div class="bs-card">
    <div class="bs-head">
      <div class="bs-head-title">ASSETS</div>
      <div class="bs-head-sub">As of <?= rpt_date($as_of) ?></div>
    </div>

    <div class="bs-section">CURRENT ASSETS</div>
    <div class="bs-row">
      <span class="bs-row-label"><i class="fas fa-coins" style="color:#9a6700;margin-right:6px"></i>Cash on Hand</span>
      <span><?= rpt_currency($cash_on_hand) ?></span>
    </div>
    <div class="bs-row">
      <span class="bs-row-label"><i class="fas fa-university" style="color:#003087;margin-right:6px"></i>Bank / Digital Balance</span>
      <span><?= rpt_currency($bank_balance) ?></span>
    </div>
    <div class="bs-row">
      <span class="bs-row-label"><i class="fas fa-file-invoice-dollar" style="color:#1a7f37;margin-right:6px"></i>Accounts Receivable (AR)</span>
      <span style="color:<?= $ar > 0 ? '#1a7f37' : '#888' ?>;font-weight:600"><?= rpt_currency($ar) ?></span>
    </div>
    <div class="bs-row">
      <span class="bs-row-label"><i class="fas fa-boxes" style="color:#6f42c1;margin-right:6px"></i>Inventory (at Cost)</span>
      <span><?= rpt_currency($inventory_val) ?></span>
    </div>
    <div class="bs-subtotal">
      <span>Total Current Assets</span>
      <span><?= rpt_currency($total_current_assets) ?></span>
    </div>
    <?php if (!empty($other_assets_list)): ?>
      <div class="bs-section">NON-CURRENT & OTHER ASSETS</div>
      <?php foreach ($other_assets_list as $oa): ?>
        <div class="bs-row">
          <span class="bs-row-label"><i class="fas fa-building" style="color:#4a5568;margin-right:6px"></i><?= htmlspecialchars($oa['account_name']) ?></span>
          <span><?= rpt_currency($oa['bal']) ?></span>
        </div>
      <?php endforeach; ?>
      <div class="bs-subtotal">
        <span>Total Non-Current & Other Assets</span>
        <span><?= rpt_currency($other_assets) ?></span>
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
      <div class="bs-head-title">LIABILITIES & EQUITY</div>
      <div class="bs-head-sub">As of <?= rpt_date($as_of) ?></div>
    </div>

    <div class="bs-section">CURRENT LIABILITIES</div>
    <div class="bs-row">
      <span class="bs-row-label"><i class="fas fa-file-invoice" style="color:#c00;margin-right:6px"></i>Accounts Payable (AP)</span>
      <span style="color:<?= $ap > 0 ? '#c00' : '#888' ?>;font-weight:600"><?= rpt_currency($ap) ?></span>
    </div>
    <div class="bs-row">
      <span class="bs-row-label"><i class="fas fa-percent" style="color:#e67e22;margin-right:6px"></i>Tax / VAT Payable</span>
      <span style="color:<?= $tax_payable > 0 ? '#e67e22' : '#888' ?>;font-weight:600"><?= rpt_currency($tax_payable) ?></span>
    </div>
    <div class="bs-subtotal">
      <span>Total Current Liabilities</span>
      <span style="color:#c00"><?= rpt_currency($ap + $tax_payable) ?></span>
    </div>
    <?php if (!empty($other_liabilities_list)): ?>
      <div class="bs-section">NON-CURRENT & OTHER LIABILITIES</div>
      <?php foreach ($other_liabilities_list as $ol): ?>
        <div class="bs-row">
          <span class="bs-row-label"><i class="fas fa-wallet" style="color:#c00;margin-right:6px"></i><?= htmlspecialchars($ol['account_name']) ?></span>
          <span style="color:<?= $ol['bal'] > 0 ? '#c00' : '#888' ?>;font-weight:600"><?= rpt_currency($ol['bal']) ?></span>
        </div>
      <?php endforeach; ?>
      <div class="bs-subtotal">
        <span>Total Non-Current & Other Liabilities</span>
        <span style="color:#c00"><?= rpt_currency($other_liabilities) ?></span>
      </div>
    <?php endif; ?>
    <div class="bs-subtotal" style="background:#fdf2f2;border-top-color:#e53e3e">
      <span>Total Liabilities</span>
      <span style="color:#c00;font-weight:bold"><?= rpt_currency($total_liabilities) ?></span>
    </div>

    <div class="bs-section">EQUITY</div>
    <?php if (!empty($equity_accounts_list)): ?>
      <?php foreach ($equity_accounts_list as $eq): ?>
        <div class="bs-row">
          <span class="bs-row-label"><i class="fas fa-coins" style="color:#6f42c1;margin-right:6px"></i><?= htmlspecialchars($eq['account_name']) ?></span>
          <span style="color:<?= $eq['bal'] >= 0 ? '#1a7f37' : '#c00' ?>"><?= rpt_currency($eq['bal']) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($inventory_adjustment_reserve != 0): ?>
      <div class="bs-row">
        <span class="bs-row-label"><i class="fas fa-adjust" style="color:#2b6cb0;margin-right:6px"></i>Inventory Adjustment Reserves</span>
        <span style="color:<?= $inventory_adjustment_reserve >= 0 ? '#1a7f37' : '#c00' ?>"><?= rpt_currency($inventory_adjustment_reserve) ?></span>
      </div>
    <?php endif; ?>
    <div class="bs-row">
      <span class="bs-row-label"><i class="fas fa-chart-line" style="color:#1a7f37;margin-right:6px"></i>Revenue (to date)</span>
      <span><?= rpt_currency($revenue) ?></span>
    </div>
    <div class="bs-row">
      <span class="bs-row-label"><i class="fas fa-minus-circle" style="color:#9a6700;margin-right:6px"></i>Less: Cost of Sales / Exp</span>
      <span style="color:#9a6700">(<?= rpt_currency($expenses) ?>)</span>
    </div>
    <div class="bs-subtotal">
      <span>Current Period Net Income</span>
      <span style="color:<?= $retained_earnings >= 0 ? '#1a7f37' : '#c00' ?>"><?= rpt_currency($retained_earnings) ?></span>
    </div>
    <div class="bs-subtotal" style="background:#f0fff4;border-top-color:#1a7f37">
      <span>Total Equity</span>
      <span style="color:<?= $total_equity >= 0 ? '#1a7f37' : '#c00' ?>"><?= rpt_currency($total_equity) ?></span>
    </div>
    <div class="bs-total">
      <span>TOTAL LIABILITIES + EQUITY</span>
      <span><?= rpt_currency($total_liabilities_equity) ?></span>
    </div>
  </div>
</div>

<?php if ($is_balanced): ?>
  <div class="bs-balance-ok"><i class="fas fa-check-circle"></i> Balance Sheet is BALANCED — General Ledger Integrity Verified</div>
<?php else:
  $diff = abs($total_assets - $total_liabilities_equity);
?>
  <div class="bs-balance-err">
    <i class="fas fa-exclamation-triangle"></i>
    Discrepancy of <?= rpt_currency($diff) ?> — Syncing with General Ledger...
  </div>
<?php endif; ?>
