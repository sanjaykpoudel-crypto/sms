<?php
require_once 'database/DBConnection.php';
$db = db();

$types = $db->fetchAll("
    SELECT h.txn_type, h.source, h.status,
           SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as net_bal,
           COUNT(*) as cnt
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    JOIN accounts a ON j.account_id = a.id
    WHERE a.account_subtype IN ('inventory', 'Inventory Asset', 'Inventory')
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY h.txn_type, h.source, h.status
");

echo "Full list of GL components on Inventory Asset Account:\n";
$all_total = 0;
foreach ($types as $t) {
    echo sprintf("Type: %-22s | Source: %-15s | Status: %-10s | Bal: %12.2f\n",
        $t['txn_type'], $t['source'] ?? 'NULL', $t['status'], $t['net_bal']
    );
    $all_total += $t['net_bal'];
}
echo "TOTAL GL: {$all_total}\n\n";

// Target GL is 212,923.05
$target_gl = 212923.05;
$target_diff = 74320.83; // 287243.88 - 212923.05

echo "Target GL to find: {$target_gl}\n";
echo "Target Difference to find: {$target_diff}\n\n";

// Let's test combinations of excluded types
$subledger = 287243.88;

// Test excluding each txn_type or source:
foreach (['pos_sync', 'manual', 'NULL'] as $src) {
    $src_sql = ($src === 'NULL') ? "AND h.source IS NULL" : "AND h.source = '{$src}'";
    $src_bal = (float)($db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END)
        FROM journal_entries j JOIN transaction_headers h ON j.header_id = h.id JOIN accounts a ON j.account_id = a.id
        WHERE a.account_subtype IN ('inventory', 'Inventory Asset', 'Inventory')
          AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$src_sql}
    ")[0] ?? 0);
    echo "Source '{$src}' net balance: {$src_bal}\n";
    echo "GL excluding '{$src}': " . ($all_total - $src_bal) . " | Diff from Subledger: " . ($subledger - ($all_total - $src_bal)) . "\n";
}

echo "\nChecking transaction lines vs journal entries...\n";
// Check if there are vendor bills or invoices where inventory transaction lines exist BUT no journal entry on account 7 was generated!
$missing_gl_txns = $db->fetchAll("
    SELECT h.id, h.txn_number, h.txn_type, h.txn_date, h.net_amount,
           (SELECT SUM(COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1)) * l.unit_price) FROM transaction_lines l WHERE l.header_id = h.id) as line_val,
           (SELECT SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) FROM journal_entries j JOIN accounts a ON j.account_id=a.id WHERE j.header_id = h.id AND a.account_subtype IN ('inventory', 'Inventory Asset', 'Inventory')) as gl_val
    FROM transaction_headers h
    WHERE h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_type IN ('vendor_bill', 'customer_invoice', 'inventory_adjustment', 'credit_memo', 'vendor_credit')
    HAVING (line_val IS NOT NULL AND (gl_val IS NULL OR ABS(line_val - ABS(gl_val)) > 0.01))
");

echo "Transactions with line_val != gl_val count: " . count($missing_gl_txns) . "\n";
$tot_missing = 0;
foreach ($missing_gl_txns as $m) {
    $diff = (float)$m['line_val'] - (float)($m['gl_val'] ?? 0);
    echo "Txn #: {$m['txn_number']} | Type: {$m['txn_type']} | Date: {$m['txn_date']} | Line Val: {$m['line_val']} | GL Val: {$m['gl_val']} | Diff: {$diff}\n";
    $tot_missing += $diff;
}
echo "Total Line Val vs GL Val mismatch sum: {$tot_missing}\n";
