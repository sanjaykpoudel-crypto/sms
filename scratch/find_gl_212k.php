<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== SEARCHING FOR THE QUERY PRODUCING GL = 212,923.05 ===\n\n";

// Total GL on account 7
$total_gl = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END) 
    FROM journal_entries WHERE account_id = '7'
")[0] ?? 0);
echo "Total GL account 7 (all transactions including deleted/void): {$total_gl}\n";

// Let's list all transactions on account 7 grouped by transaction type, header status, source, or location_id
$by_type = $db->fetchAll("
    SELECT h.txn_type, h.status, h.source, h.location_id,
           SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as net_bal,
           COUNT(*) as entry_cnt
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '7'
    GROUP BY h.txn_type, h.status, h.source, h.location_id
");

echo "\n--- BREAKDOWN OF JOURNAL ENTRIES ON ACCOUNT 7 ---\n";
foreach ($by_type as $bt) {
    echo sprintf("Type: %-22s | Status: %-10s | Source: %-20s | Loc: %-15s | Net Bal: %12.2f | Count: %d\n",
        $bt['txn_type'], $bt['status'], $bt['source'] ?? 'NULL', $bt['location_id'] ?? 'NULL', $bt['net_bal'], $bt['entry_cnt']
    );
}

// Check transactions by date range
echo "\n--- BY DATE RANGE ---\n";
$by_date = $db->fetchAll("
    SELECT DATE_FORMAT(h.txn_date, '%Y-%m') as ym,
           SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as net_bal,
           COUNT(*) as entry_cnt
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '7' AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
    GROUP BY DATE_FORMAT(h.txn_date, '%Y-%m')
    ORDER BY ym ASC
");

$cum = 0;
foreach ($by_date as $bd) {
    $cum += $bd['net_bal'];
    echo "Month: {$bd['ym']} | Net: " . number_format($bd['net_bal'], 2) . " | Cum GL: " . number_format($cum, 2) . " | Count: {$bd['entry_cnt']}\n";
}

// Check if location_id string mismatch (e.g. 'loc-main-retail' vs '1') is in transaction_headers!
echo "\n--- LOCATION STRING VS INT CHECK IN TRANSACTION_HEADERS ---\n";
$loc_vals = $db->fetchAll("SELECT DISTINCT location_id FROM transaction_headers");
print_r($loc_vals);

// Check if there are journal entries where location_id is 'loc-main-retail' or string vs integer 1
$loc_str_check = $db->fetchAll("
    SELECT h.location_id, SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as net_bal
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '7' AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
    GROUP BY h.location_id
");
print_r($loc_str_check);
