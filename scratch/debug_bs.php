<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/ReportingEngine.php';

$db = db();
$today = date('Y-m-d');
$excluded = implode("','", RE_EXCLUDED_STATUSES);
$close_src = implode("','", RE_CLOSE_SOURCES);

$inc_row = $db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type='credit' THEN j.amount ELSE -j.amount END), 0) AS rev
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'income' AND j.entry_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('{$excluded}')
      AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
", [$today]);

$exp_row = $db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END), 0) AS exp
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND j.entry_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('{$excluded}')
      AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
", [$today]);

$cum_net_income = round((float)$inc_row['rev'] - (float)$exp_row['exp'], 2);

$bs = re_get_balance_sheet($db, $today);
$total_assets = $bs['total_assets'];
$total_liab = $bs['total_liabilities'];
$total_eq_accts = $bs['total_equity_accts'];

$new_total_eq = $total_eq_accts + $cum_net_income;
$new_total_liab_eq = $total_liab + $new_total_eq;
$new_diff = round($total_assets - $new_total_liab_eq, 2);

echo "Assets: {$total_assets}\n";
echo "Liabilities: {$total_liab}\n";
echo "Equity Accts: {$total_eq_accts}\n";
echo "Cumulative Net Income: {$cum_net_income}\n";
echo "Total Equity: {$new_total_eq}\n";
echo "Total Liab + Equity: {$new_total_liab_eq}\n";
echo "NEW BALANCE SHEET DIFFERENCE: {$new_diff}\n";
