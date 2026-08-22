<?php
/**
 * Bank Book Report
 * ─────────────────────────────────────────────────────────────
 * AUTHORITATIVE SOURCE: journal_entries + accounts (account_subtype = 'Bank')
 *
 * Rules:
 *  - Calculates Opening Bank Balance (j.entry_date < $date_from)
 *  - Lists all posted Bank deposits (Debits) and Bank withdrawals/payments (Credits) between $date_from and $date_to
 *  - Calculates Running Balance and Closing Bank Balance
 *  - Strictly respects Location and Date range filters
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/ReportingEngine.php';

$db = db();
$fy       = rpt_get_current_fiscal_year_dates();
$today    = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;

$user_loc = function_exists('get_user_default_location_id') ? get_user_default_location_id() : '';
$location_id = $_GET['location_id'] ?? ($user_loc ?: ($_SESSION['location_id'] ?? null));

$excluded = implode("','", RE_EXCLUDED_STATUSES);

// Location SQL Filter
$loc_sql = "";
$params = [];
if (!empty($location_id) && $location_id !== 'all') {
    $loc_sql = " AND h.location_id = ? ";
    $params[] = $location_id;
}

// 1. Resolve Bank Accounts from COA
$bank_acct_rows = $db->fetchAll("
    SELECT id, account_name FROM accounts
    WHERE (account_subtype = 'Bank' OR LOWER(account_name) LIKE '%bank%')
      AND is_deleted = 0
");
$bank_acct_ids = array_column($bank_acct_rows, 'id');

$opening_balance = 0.0;
$entries = [];

if (!empty($bank_acct_ids)) {
    $acct_placeholders = implode(',', array_fill(0, count($bank_acct_ids), '?'));

    // 2. Calculate Opening Balance (je_date < $date_from)
    $op_params = array_merge($bank_acct_ids, [$date_from], $params);
    $op_row = $db->fetchOne("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) as op_bal
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE jl.account_id IN ({$acct_placeholders})
          AND je.je_date < ?
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          {$loc_sql}
    ", $op_params);
    $opening_balance = (float)($op_row['op_bal'] ?? 0);

    // 3. Fetch Period Bank Transactions (je_date BETWEEN $date_from AND $date_to)
    $period_params = array_merge($bank_acct_ids, [$date_from, $date_to], $params);
    $entries = $db->fetchAll("
        SELECT jl.jl_id as id, je.je_date as entry_date, h.txn_number, h.txn_type, je.memo,
               a.account_name, 
               IF(jl.debit > 0, 'debit', 'credit') as entry_type,
               IF(jl.debit > 0, jl.debit, jl.credit) as amount,
               jl.debit, jl.credit
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN accounts a ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE jl.account_id IN ({$acct_placeholders})
          AND je.je_date BETWEEN ? AND ?
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          {$loc_sql}
        ORDER BY je.je_date ASC, jl.jl_id ASC
    ", $period_params);
}

$tot_deposits = 0.0;
$tot_withdrawals = 0.0;
foreach ($entries as $e) {
    if ($e['entry_type'] === 'debit') {
        $tot_deposits += (float)$e['amount'];
    } else {
        $tot_withdrawals += (float)$e['amount'];
    }
}
$closing_balance = $opening_balance + $tot_deposits - $tot_withdrawals;
?>

<style>
.bb-card { background:#fff;border:1px solid #dde2e8;border-radius:8px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.06); }
.bb-summary { display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px; }
.bb-stat { background:#f8f9fa;border:1px solid #e9ecef;padding:12px 16px;border-radius:6px;text-align:center; }
.bb-stat-title { font-size:11px;color:#6c757d;text-transform:uppercase;font-weight:700;letter-spacing:.5px; }
.bb-stat-val { font-size:16px;font-weight:800;margin-top:4px;color:#003087; }
.bb-table { width:100%;border-collapse:collapse;font-size:13px; }
.bb-table th { background:#003087;color:#fff;padding:10px 12px;text-align:left;font-weight:600; }
.bb-table td { padding:9px 12px;border-bottom:1px solid #e9ecef; }
.bb-table tr:hover { background:#f8f9fa; }
.bb-table .num { text-align:right; }
</style>

<?php rpt_filter_bar('Bank Book', [
    ['name' => 'date_from', 'label' => 'From Date', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to',   'label' => 'To Date',   'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], ''); ?>

<div class="bb-card">
  <div class="bb-summary">
    <div class="bb-stat">
      <div class="bb-stat-title">Opening Bank</div>
      <div class="bb-stat-val"><?= rpt_currency($opening_balance) ?></div>
    </div>
    <div class="bb-stat">
      <div class="bb-stat-title">Total Deposits (In)</div>
      <div class="bb-stat-val" style="color:#1a7f37"><?= rpt_currency($tot_deposits) ?></div>
    </div>
    <div class="bb-stat">
      <div class="bb-stat-title">Total Withdrawals (Out)</div>
      <div class="bb-stat-val" style="color:#c00"><?= rpt_currency($tot_withdrawals) ?></div>
    </div>
    <div class="bb-stat">
      <div class="bb-stat-title">Closing Bank</div>
      <div class="bb-stat-val" style="color:#003087"><?= rpt_currency($closing_balance) ?></div>
    </div>
  </div>

  <table class="bb-table">
    <thead>
      <tr>
        <th>Date</th>
        <th>Ref #</th>
        <th>Type</th>
        <th>Bank Account</th>
        <th>Memo</th>
        <th class="num">Deposit (Dr)</th>
        <th class="num">Withdrawal (Cr)</th>
        <th class="num">Balance</th>
      </tr>
    </thead>
    <tbody>
      <tr style="background:#f8f9fa;font-weight:700">
        <td colspan="7">Opening Balance as of <?= rpt_date($date_from) ?></td>
        <td class="num"><?= rpt_currency($opening_balance) ?></td>
      </tr>
      <?php
      $running = $opening_balance;
      if (empty($entries)):
      ?>
      <tr><td colspan="8" style="text-align:center;color:#888;padding:20px">No bank transactions recorded during this period.</td></tr>
      <?php else:
        foreach ($entries as $e):
          $deposit = ($e['entry_type'] === 'debit') ? (float)$e['amount'] : 0.0;
          $withdrawal = ($e['entry_type'] === 'credit') ? (float)$e['amount'] : 0.0;
          $running += ($deposit - $withdrawal);
      ?>
      <tr>
        <td><?= rpt_date($e['entry_date']) ?></td>
        <td><strong><?= htmlspecialchars($e['txn_number']) ?></strong></td>
        <td><?= htmlspecialchars($e['txn_type']) ?></td>
        <td><?= htmlspecialchars($e['account_name']) ?></td>
        <td><?= htmlspecialchars($e['memo'] ?: '-') ?></td>
        <td class="num" style="color:<?= $deposit > 0 ? '#1a7f37' : '#888' ?>"><?= $deposit > 0 ? rpt_currency($deposit) : '-' ?></td>
        <td class="num" style="color:<?= $withdrawal > 0 ? '#c00' : '#888' ?>"><?= $withdrawal > 0 ? rpt_currency($withdrawal) : '-' ?></td>
        <td class="num" style="font-weight:600"><?= rpt_currency($running) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr style="background:#003087;color:#fff;font-weight:800">
        <td colspan="5">Closing Balance as of <?= rpt_date($date_to) ?></td>
        <td class="num"><?= rpt_currency($tot_deposits) ?></td>
        <td class="num"><?= rpt_currency($tot_withdrawals) ?></td>
        <td class="num"><?= rpt_currency($closing_balance) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
