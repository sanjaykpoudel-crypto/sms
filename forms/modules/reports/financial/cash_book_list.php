<?php
/**
 * Cash Book / Bank Book Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;
$account_id = $_GET['account_id'] ?? '';

// Fetch cash & bank accounts
$cash_bank_accounts = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE account_subtype IN ('cash', 'bank') AND is_active=1 AND is_deleted=0 ORDER BY account_code ASC");
$acct_options = ['' => 'All Cash & Bank Accounts'];
foreach ($cash_bank_accounts as $a) {
    $acct_options[$a['id']] = $a['account_code'].' - '.$a['account_name'];
}

// 1. Resolve aggregation boundary start date to prevent double-counting closed years
$fy_start_date = get_report_start_date($date_from);

// Calculate Opening Balance: Sum of all journals prior to date_from, starting from the boundary start date
$op_params = [$fy_start_date, $date_from];
$op_where = "j.entry_date >= ? AND j.entry_date < ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')";
if ($account_id) {
    $op_where .= " AND j.account_id = ?";
    $op_params[] = $account_id;
} else {
    $op_where .= " AND a.account_subtype IN ('cash', 'bank')";
}

$opening_bal = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    JOIN accounts a ON j.account_id = a.id
    WHERE $op_where
", $op_params)['bal'] ?? 0);

// 2. Fetch ledger details in date range
$params = [$date_from, $date_to];
$where = "j.entry_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')";
if ($account_id) {
    $where .= " AND j.account_id = ?";
    $params[] = $account_id;
} else {
    $where .= " AND a.account_subtype IN ('cash', 'bank')";
}

$sql = "
    SELECT 
        j.entry_date,
        h.txn_number,
        h.txn_type,
        j.memo,
        a.account_name,
        a.account_code,
        j.entry_type,
        j.amount
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    JOIN accounts a ON j.account_id = a.id
    WHERE $where
    ORDER BY j.entry_date ASC, h.created_at ASC
";

$rows = $db->fetchAll($sql, $params);

// Calculate totals and running balances
$running_bal = $opening_bal;
$total_in = 0.0;
$total_out = 0.0;
$table_rows = [];

foreach ($rows as $r) {
    $in = 0.0;
    $out = 0.0;
    if ($r['entry_type'] === 'debit') {
        $in = (float)$r['amount'];
        $total_in += $in;
        $running_bal += $in;
    } else {
        $out = (float)$r['amount'];
        $total_out += $out;
        $running_bal -= $out;
    }
    
    $table_rows[] = [
        'date' => $r['entry_date'],
        'txn_number' => $r['txn_number'],
        'txn_type' => $r['txn_type'],
        'account' => $r['account_code'] . ' - ' . $r['account_name'],
        'memo' => $r['memo'] ?? '',
        'in' => $in,
        'out' => $out,
        'balance' => $running_bal
    ];
}
?>

<?php rpt_filter_bar('Cash Book / Bank Book', [
    ['name'=>'date_from', 'label'=>'From',    'type'=>'date',   'default'=>$date_from],
    ['name'=>'date_to',   'label'=>'To',      'type'=>'date',   'default'=>$date_to],
    ['name'=>'account_id','label'=>'Account', 'type'=>'select', 'default'=>$account_id, 'options'=>$acct_options],
], 'tbl-cashbook'); ?>

<div class="rpt-summary">
  <div class="rpt-summary-card"><div class="val"><?= rpt_currency($opening_bal) ?></div><div class="lbl">Opening Balance</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_in) ?></div><div class="lbl">Total Cash In (Dr)</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_out) ?></div><div class="lbl">Total Cash Out (Cr)</div></div>
  <div class="rpt-summary-card"><div class="val" style="font-weight:900; color:#1a7f37;"><?= rpt_currency($running_bal) ?></div><div class="lbl">Closing Balance</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-cashbook">
      <thead>
        <tr>
          <th>Date</th>
          <th>Reference #</th>
          <th>Type</th>
          <th>Account</th>
          <th>Memo / Particulars</th>
          <th style="text-align:right">In (Dr)</th>
          <th style="text-align:right">Out (Cr)</th>
          <th style="text-align:right">Running Balance</th>
        </tr>
      </thead>
      <tbody>
        <tr style="background: #f8fafc; font-weight: 700;">
          <td>OPENING BALANCE</td>
          <td></td>
          <td></td>
          <td></td>
          <td></td>
          <td style="text-align:right">—</td>
          <td style="text-align:right">—</td>
          <td style="text-align:right"><?= rpt_currency($opening_bal) ?></td>
        </tr>
        <?php if (empty($table_rows)): ?>
          <tr>
            <td style="text-align:center; padding:15px; color:#888;">No transactions in selected period.</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
          </tr>
        <?php else: foreach ($table_rows as $row): ?>
          <tr>
            <td><?= $row['date'] ?></td>
            <td style="font-weight:700"><?= htmlspecialchars($row['txn_number']) ?></td>
            <td><span class="ns-badge" style="background:#eef2ff;color:#003087;font-size:10px"><?= strtoupper($row['txn_type']) ?></span></td>
            <td style="font-size:12px"><?= htmlspecialchars($row['account']) ?></td>
            <td style="font-size:11px;color:#666;"><?= htmlspecialchars($row['memo']) ?></td>
            <td style="text-align:right;color:#003087;font-weight:700"><?= $row['in'] > 0 ? rpt_currency($row['in']) : '—' ?></td>
            <td style="text-align:right;color:#c00;font-weight:700"><?= $row['out'] > 0 ? rpt_currency($row['out']) : '—' ?></td>
            <td style="text-align:right;font-weight:700"><?= rpt_currency($row['balance']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:900;background:#003087;color:#fff">
          <th colspan="5">PERIOD TOTALS</th>
          <th style="text-align:right"><?= rpt_currency($total_in) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_out) ?></th>
          <th style="text-align:right"><?= rpt_currency($running_bal) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
