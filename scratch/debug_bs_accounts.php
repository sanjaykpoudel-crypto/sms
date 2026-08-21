<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/ReportingEngine.php';

$db = db();
$today = date('Y-m-d');
$excluded = implode("','", RE_EXCLUDED_STATUSES);

$acct_rows = $db->fetchAll("
    SELECT
        a.id, a.account_name, a.account_type, a.account_subtype, a.normal_balance,
        SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE 0 END) AS dr_sum,
        SUM(CASE WHEN j.entry_type='credit' THEN j.amount ELSE 0 END) AS cr_sum,
        SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) AS net_dr
    FROM journal_entries j
    JOIN accounts a          ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.is_deleted = 0
      AND h.status NOT IN ('{$excluded}')
      AND a.is_deleted = 0
      AND j.entry_date <= ?
    GROUP BY a.id, a.account_name, a.account_type, a.account_subtype, a.normal_balance
    HAVING net_dr != 0
    ORDER BY a.account_type, a.id
", [$today]);

echo "--- ACCOUNT BALANCES AS OF {$today} ---\n";
$sum_assets = 0;
$sum_liab = 0;
$sum_eq = 0;
$sum_inc = 0;
$sum_exp = 0;

foreach ($acct_rows as $r) {
    $bal = ($r['normal_balance'] === 'debit') ? $r['net_dr'] : -$r['net_dr'];
    echo sprintf("%-10s | %-10s | %-35s | Dr: %12.2f | Cr: %12.2f | Bal (%s): %12.2f\n",
        $r['id'], $r['account_type'], substr($r['account_name'], 0, 35),
        $r['dr_sum'], $r['cr_sum'], $r['normal_balance'], $bal
    );

    if ($r['account_type'] === 'asset') $sum_assets += $bal;
    if ($r['account_type'] === 'liability') $sum_liab += $bal;
    if ($r['account_type'] === 'equity') $sum_eq += $bal;
    if ($r['account_type'] === 'income') $sum_inc += $bal;
    if ($r['account_type'] === 'expense') $sum_exp += $bal;
}

echo "\n--- SUMMARY BY TYPE ---\n";
echo "Total Assets: " . number_format($sum_assets, 2) . "\n";
echo "Total Liabilities: " . number_format($sum_liab, 2) . "\n";
echo "Total Equity Accounts: " . number_format($sum_eq, 2) . "\n";
echo "Total Income Accounts: " . number_format($sum_inc, 2) . "\n";
echo "Total Expense Accounts: " . number_format($sum_exp, 2) . "\n";

$net_profit = $sum_inc - $sum_exp;
echo "Net Profit (Income - Expense): " . number_format($net_profit, 2) . "\n";
echo "Total Equity + Net Profit: " . number_format($sum_eq + $net_profit, 2) . "\n";
echo "Total Liabilities + Equity + Net Profit: " . number_format($sum_liab + $sum_eq + $net_profit, 2) . "\n";
echo "Assets - (Liabilities + Equity + Net Profit): " . number_format($sum_assets - ($sum_liab + $sum_eq + $net_profit), 2) . "\n";
