<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== SEARCHING FOR 74,320.83 OR 212,923.05 IN DATABASE AND RECONCILIATION LOGIC ===\n\n";

// 1. Check if there are journal entries where header status is NOT 'posted' or source is pos_sync or manual
$gl_rows = $db->fetchAll("
    SELECT h.id as header_id, h.txn_number, h.txn_type, h.txn_date, h.status, h.source,
           j.entry_type, j.amount,
           (CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as net_amount
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '7' AND h.is_deleted = 0
    ORDER BY h.txn_date ASC
");

echo "Total GL entries on account 7: " . count($gl_rows) . "\n";

// Let's check subsets of $gl_rows:
// Option A: Only 'posted' status (excluding 'closed', 'open', 'paid', 'partial')
$posted_only = 0;
// Option B: Excluding 'pos_sync' source
$no_pos_sync = 0;
// Option C: Date range up to yesterday or specific date
$up_to_dates = [];

foreach ($gl_rows as $row) {
    $net = (float)$row['net_amount'];
    if ($row['status'] === 'posted') {
        $posted_only += $net;
    }
    if ($row['source'] !== 'pos_sync') {
        $no_pos_sync += $net;
    }
    $d = $row['txn_date'];
    if (!isset($up_to_dates[$d])) $up_to_dates[$d] = 0;
    $up_to_dates[$d] += $net;
}

echo "GL Status 'posted' only: {$posted_only}\n";
echo "GL Source excluding 'pos_sync': {$no_pos_sync}\n\n";

echo "Running cumulative GL by date:\n";
$cum = 0;
foreach ($up_to_dates as $d => $net) {
    $cum += $net;
    $diff_from_sub = 287243.88 - $cum;
    if (abs($cum - 212923.05) < 100 || abs($diff_from_sub - 74320.83) < 100) {
        echo "*** MATCH CANDIDATE *** Date: {$d} | Cum GL: " . number_format($cum, 2) . " | Diff from Subledger: " . number_format($diff_from_sub, 2) . "\n";
    }
}

// 2. Check if a single transaction or set of transactions equals 74,320.83
echo "\nChecking if any single header or item sum equals 74,320.83...\n";
$single_match = $db->fetchAll("
    SELECT h.txn_number, h.txn_type, h.txn_date, j.amount, j.entry_type
    FROM journal_entries j JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '7' AND ABS(j.amount - 74320.83) < 10
");
print_r($single_match);

// Check if vendor bills or COGS entries without inventory lines total 74,320.83
$pos_sync_cogs = (float)($db->fetchOne("
    SELECT SUM(j.amount) FROM journal_entries j JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '7' AND h.source = 'pos_sync'
")[0] ?? 0);
echo "POS sync GL entries on account 7 total: {$pos_sync_cogs}\n";

// Check POS sales COST vs POS sync GL
$pos_cost = (float)($db->fetchOne("
    SELECT SUM(pi.quantity * i.cost_price)
    FROM pos_items pi JOIN items i ON pi.item_id = i.id JOIN pos_entry pe ON pi.pos_id = pe.id
    WHERE pe.is_deleted = 0 AND pe.status != 'voided'
")[0] ?? 0);
echo "Total POS Sales COGS from pos_items * item cost_price: {$pos_cost}\n";
