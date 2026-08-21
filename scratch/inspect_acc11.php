<?php
require_once 'database/DBConnection.php';
$db = db();

$account = $db->fetchOne("SELECT * FROM accounts WHERE id = 11");
echo "--- ACCOUNT DETAILS ---\n";
print_r($account);

$entries = $db->fetchAll("
    SELECT j.id, j.header_id, j.entry_type, j.amount, j.entry_date, j.memo,
           h.txn_number, h.txn_type, h.memo as header_memo
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '11' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ORDER BY j.entry_date DESC
");

echo "\n--- JOURNAL ENTRIES ON ACCOUNT ID 11 (Total: " . count($entries) . ") ---\n";
foreach ($entries as $e) {
    echo sprintf("Txn #: %-18s | Type: %-15s | Date: %s | Entry: %-6s | Amount: %10.2f | Memo: %s\n",
        $e['txn_number'], $e['txn_type'], $e['entry_date'], $e['entry_type'], $e['amount'], $e['memo'] ?: $e['header_memo']
    );
}
