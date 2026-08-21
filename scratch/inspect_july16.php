<?php
require_once 'database/DBConnection.php';
$db = db();

$headers = $db->fetchAll("
    SELECT id, txn_number, txn_type, txn_date, status, memo
    FROM transaction_headers
    WHERE txn_date <= '2026-07-16' AND is_deleted = 0
    ORDER BY txn_date ASC
");

echo "=== ALL TRANSACTIONS ON OR BEFORE 2026-07-16 (Count: " . count($headers) . ") ===\n";
foreach ($headers as $h) {
    echo sprintf("ID: %-6d | Txn #: %-25s | Type: %-20s | Date: %s | Status: %s | Memo: %s\n",
        $h['id'], $h['txn_number'], $h['txn_type'], $h['txn_date'], $h['status'], $h['memo']
    );
}

$gl_acc7 = $db->fetchAll("
    SELECT j.*, h.txn_number, h.txn_type
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '7' AND j.entry_date <= '2026-07-16' AND h.is_deleted = 0
");

echo "\n=== GL ACCOUNT #7 (INVENTORY ASSET) ENTRIES ON OR BEFORE 2026-07-16 (Count: " . count($gl_acc7) . ") ===\n";
foreach ($gl_acc7 as $g) {
    echo sprintf("Txn #: %-25s | Type: %-20s | Entry: %-6s | Amount: %10.2f | Memo: %s\n",
        $g['txn_number'], $g['txn_type'], $g['entry_type'], $g['amount'], $g['memo']
    );
}
