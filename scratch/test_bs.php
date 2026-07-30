<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';

$_GET['location_id'] = 'loc-main-wh'; // House location
$db = db();
$loc_sql = rpt_location_sql('h');

echo "loc_sql: " . $loc_sql . "\n";

$start_date = '2026-06-01';
$as_of = '2026-07-28';

// Equity WITHOUT loc_sql:
$equity_no_loc = $db->fetchAll("
    SELECT a.account_name, -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity'
      AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY a.id, a.account_name
    HAVING bal != 0
", [$start_date, $as_of]);

echo "Equity WITHOUT loc_sql (House location selected):\n";
print_r($equity_no_loc);

// Equity WITH loc_sql:
$equity_with_loc = $db->fetchAll("
    SELECT a.account_name, -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity'
      AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    GROUP BY a.id, a.account_name
    HAVING bal != 0
", [$start_date, $as_of]);

echo "Equity WITH loc_sql (House location selected):\n";
print_r($equity_with_loc);
