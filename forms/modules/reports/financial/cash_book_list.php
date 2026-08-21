<?php
/**
 * Cash Book Report
 * ─────────────────────────────────────────────────────────────
 * AUTHORITATIVE SOURCE: journal_entries + accounts (account_subtype = 'Cash')
 *
 * Rules:
 *  - Calculates Opening Cash Balance (j.entry_date < $date_from)
 *  - Lists all posted Cash receipts (Debits) and Cash payments (Credits) between $date_from and $date_to
 *  - Calculates Running Balance and Closing Cash Balance
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

// 1. Resolve Cash Accounts from COA
$cash_acct_rows = $db->fetchAll("
    SELECT id, account_name FROM accounts
    WHERE (account_subtype = 'Cash' OR LOWER(account_name) LIKE '%cash%')
      AND is_deleted = 0
");
$cash_acct_ids = array_column($cash_acct_rows, 'id');

$opening_balance = 0.0;
$entries = [];

if (!empty($cash_acct_ids)) {
    $acct_placeholders = implode(',', array_fill(0, count($cash_acct_ids), '?'));

    // 2. Calculate Opening Balance (entry_date < $date_from)
    $op_params = array_merge($cash_acct_ids, [$date_from], $params);
    $op_row = $db->fetchOne("
        SELECT COALESCE(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END), 0) as op_bal
        FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE j.account_id IN ({$acct_placeholders})
          AND j.entry_date < ?
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          {$loc_sql}
    ", $op_params);
    $opening_balance = (float)($op_row['op_bal'] ?? 0);

    // 3. Fetch Period Cash Transactions (entry_date BETWEEN $date_from AND $date_to)
    $period_params = array_merge($cash_acct_ids, [$date_from, $date_to], $params);
    $entries = $db->fetchAll("
        SELECT j.id, j.entry_date, h.txn_number, h.txn_type, j.memo,
               a.account_name, j.entry_type, j.amount
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE j.account_id IN ({$acct_placeholders})
          AND j.entry_date BETWEEN ? AND ?
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          {$loc_sql}
        ORDER BY j.entry_date ASC, j.id ASC
    ", $period_params);
}

$tot_receipts = 0.0;
$tot_payments = 0.0;
foreach ($entries as $e) {
    if ($e['entry_type'] === 'debit') {
        $tot_receipts += (float)$e['amount'];
    } else {
        $tot_payments += (float)$e['amount'];
    }
}
$closing_balance = $opening_balance + $tot_receipts - $tot_payments;
?>

<style>
.cb-card { background:#fff;border:1px solid #dde2e8;border-radius:8px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.06); }
.cb-summary { display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px; }
.cb-stat { background:#f8f9fa;border:1px solid #e9ecef;padding:12px 16px;border-radius:6px;text-align:center; }
.cb-stat-title { font-size:11px;color:#6c757d;text-transform:uppercase;font-weight:700;letter-spacing:.5px; }
.cb-stat-val { font-size:16px;font-weight:800;margin-top:4px;color:#003087; }
.cb-table { width:100%;border-collapse:collapse;font-size:13px; }
.cb-table th { background:#003087;color:#fff;padding:10px 12px;text-align:left;font-weight:600; }
.cb-table td { padding:9px 12px;border-bottom:1px solid #e9ecef; }
.cb-table tr:hover { background:#f8f9fa; }
.cb-table .num { text-align:right; }
</style>

<?php rpt_filter_bar('Cash Book', [
    ['name' => 'date_from', 'label' => 'From Date', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to',   'label' => 'To Date',   'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], ''); ?>

<div class="cb-card">
  <div class="cb-summary">
    <div class="cb-stat">
      <div class="cb-stat-title">Opening Cash</div>
      <div class="cb-stat-val"><?= rpt_currency($opening_balance) ?></div>
    </div>
    <div class="cb-stat">
      <div class="cb-stat-title">Total Receipts (In)</div>
      <div class="cb-stat-val" style="color:#1a7f37"><?= rpt_currency($tot_receipts) ?></div>
    </div>
    <div class="cb-stat">
      <div class="cb-stat-title">Total Payments (Out)</div>
      <div class="cb-stat-val" style="color:#c00"><?= rpt_currency($tot_payments) ?></div>
    </div>
    <div class="cb-stat">
      <div class="cb-stat-title">Closing Cash</div>
      <div class="cb-stat-val" style="color:#003087"><?= rpt_currency($closing_balance) ?></div>
    </div>
  </div>

  <table class="cb-table">
    <thead>
      <tr>
        <th>Date</th>
        <th>Ref #</th>
        <th>Type</th>
        <th>Account</th>
        <th>Memo</th>
        <th class="num">Receipt (Dr)</th>
        <th class="num">Payment (Cr)</th>
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
      <tr><td colspan="8" style="text-align:center;color:#888;padding:20px">No cash transactions recorded during this period.</td></tr>
      <?php else:
        foreach ($entries as $e):
          $receipt = ($e['entry_type'] === 'debit') ? (float)$e['amount'] : 0.0;
          $payment = ($e['entry_type'] === 'credit') ? (float)$e['amount'] : 0.0;
          $running += ($receipt - $payment);
      ?>
      <tr>
        <td><?= rpt_date($e['entry_date']) ?></td>
        <td><strong><?= htmlspecialchars($e['txn_number']) ?></strong></td>
        <td><?= htmlspecialchars($e['txn_type']) ?></td>
        <td><?= htmlspecialchars($e['account_name']) ?></td>
        <td><?= htmlspecialchars($e['memo'] ?: '-') ?></td>
        <td class="num" style="color:<?= $receipt > 0 ? '#1a7f37' : '#888' ?>"><?= $receipt > 0 ? rpt_currency($receipt) : '-' ?></td>
        <td class="num" style="color:<?= $payment > 0 ? '#c00' : '#888' ?>"><?= $payment > 0 ? rpt_currency($payment) : '-' ?></td>
        <td class="num" style="font-weight:600"><?= rpt_currency($running) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr style="background:#003087;color:#fff;font-weight:800">
        <td colspan="5">Closing Balance as of <?= rpt_date($date_to) ?></td>
        <td class="num"><?= rpt_currency($tot_receipts) ?></td>
        <td class="num"><?= rpt_currency($tot_payments) ?></td>
        <td class="num"><?= rpt_currency($closing_balance) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
