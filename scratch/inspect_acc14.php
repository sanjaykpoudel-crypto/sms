<?php
require_once 'database/DBConnection.php';
$db = db();

$entries = $db->fetchAll("
    SELECT j.*, a.account_name, h.txn_number, h.txn_type
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = '14' AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
");

echo "--- JOURNAL ENTRIES FOR EXCISE DUTY PAYABLE (Account ID 14) ---\n";
foreach ($entries as $e) {
    echo sprintf("Txn #: %-18s | Type: %-15s | Date: %s | Entry: %-6s | Amount: %10.2f | Memo: %s\n",
        $e['txn_number'], $e['txn_type'], $e['entry_date'], $e['entry_type'], $e['amount'], $e['memo']
    );
}
