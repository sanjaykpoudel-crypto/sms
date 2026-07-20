<?php
/**
 * Statement of Owner Equity Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? $today;

// Resolve aggregation boundary start date to prevent double-counting closed years
$fy_start_date = get_report_start_date($date_from);

// 1. Fetch beginning balances of all equity accounts as of date_from (from boundary start date)
$beginning_balances = $db->fetchAll("
    SELECT a.account_code, a.account_name,
           -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_date >= ? AND h.txn_date < ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY a.id, a.account_code, a.account_name
", [$fy_start_date, $date_from]);

// 2. Fetch changes during the period (date_from to date_to)
$period_changes = $db->fetchAll("
    SELECT a.id, a.account_code, a.account_name,
           -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL -- Exclude closing journal to see movements
    GROUP BY a.id, a.account_code, a.account_name
", [$date_from, $date_to]);

// 3. Fetch net profit for the period
// Revenues in period
$revenue = -(float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'income' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
", [$date_from, $date_to])['v'] ?? 0);

// Expenses in period
$expenses = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
", [$date_from, $date_to])['v'] ?? 0);

$net_profit = $revenue - $expenses;

// Organize balances
$equity_map = [];

// Initialize map with accounts
$all_eq = $db->fetchAll("SELECT account_code, account_name FROM accounts WHERE account_type = 'equity' AND is_active=1 AND is_deleted=0");
foreach ($all_eq as $ae) {
    $equity_map[$ae['account_code']] = [
        'name' => $ae['account_name'],
        'beginning' => 0.0,
        'changes' => 0.0,
        'ending' => 0.0
    ];
}

// Add beginning balances
foreach ($beginning_balances as $bb) {
    if (isset($equity_map[$bb['account_code']])) {
        $equity_map[$bb['account_code']]['beginning'] = (float)$bb['bal'];
    }
}

// Add period changes
foreach ($period_changes as $pc) {
    if (isset($equity_map[$pc['account_code']])) {
        $equity_map[$pc['account_code']]['changes'] = (float)$pc['bal'];
    }
}

// Inject Net Profit to Retained Earnings (acc-3200) changes
if (isset($equity_map['3200'])) {
    $equity_map['3200']['changes'] += $net_profit;
}

// Calculate ending balances
$tot_beg = 0.0;
$tot_chg = 0.0;
$tot_end = 0.0;

foreach ($equity_map as $code => &$val) {
    $val['ending'] = $val['beginning'] + $val['changes'];
    $tot_beg += $val['beginning'];
    $tot_chg += $val['changes'];
    $tot_end += $val['ending'];
}
unset($val);
?>

<?php rpt_filter_bar('Statement of Owner Equity', [
    ['name'=>'date_from', 'label'=>'From',    'type'=>'date',   'default'=>$date_from],
    ['name'=>'date_to',   'label'=>'To',      'type'=>'date',   'default'=>$date_to],
], ''); ?>

<div class="ns-portlet" style="max-width: 800px; margin: 0 auto;">
  <div class="ns-portlet-content" style="padding:0">
    <div style="text-align:center;padding:20px;border-bottom:2px solid #003087;background:#f8f9fa">
      <div style="font-size:18px;font-weight:800;color:#003087">STATEMENT OF OWNER'S EQUITY</div>
      <div style="font-size:12px;color:#666;margin-top:4px">For the Period: <?= rpt_date($date_from) ?> to <?= rpt_date($date_to) ?></div>
    </div>

    <table class="ns-table">
      <thead>
        <tr>
          <th>Account Code</th>
          <th>Account Name</th>
          <th style="text-align:right">Beginning Balance</th>
          <th style="text-align:right">Net Period Changes</th>
          <th style="text-align:right">Ending Balance</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($equity_map as $code => $eq): if ($eq['beginning'] != 0 || $eq['changes'] != 0 || $eq['ending'] != 0): ?>
          <tr>
            <td style="font-weight:700; color:#888;"><?= $code ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($eq['name']) ?></td>
            <td style="text-align:right;"><?= rpt_currency($eq['beginning']) ?></td>
            <td style="text-align:right; color:<?= $eq['changes'] >= 0 ? '#1a7f37' : '#c00' ?>; font-weight:600;">
              <?= $eq['changes'] >= 0 ? '+' : '' ?><?= rpt_currency($eq['changes']) ?>
            </td>
            <td style="text-align:right; font-weight:700; color:#003087;"><?= rpt_currency($eq['ending']) ?></td>
          </tr>
        <?php endif; endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#003087; color:#fff; font-weight:900; font-size:14px">
          <td colspan="2" style="padding:12px 16px">TOTAL OWNER'S EQUITY</td>
          <td style="text-align:right; padding:12px 16px"><?= rpt_currency($tot_beg) ?></td>
          <td style="text-align:right; padding:12px 16px"><?= $tot_chg >= 0 ? '+' : '' ?><?= rpt_currency($tot_chg) ?></td>
          <td style="text-align:right; padding:12px 16px"><?= rpt_currency($tot_end) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
