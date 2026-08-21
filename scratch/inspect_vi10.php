<?php
require_once 'database/DBConnection.php';
$db = db();

$header = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number = 'VI-00010'");
print_r($header);

$entries = $db->fetchAll("
    SELECT j.*, a.account_name
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    WHERE j.header_id = ?
", [$header['id']]);

echo "\n--- JOURNAL ENTRIES FOR VI-00010 ---\n";
foreach ($entries as $e) {
    echo sprintf("Account: %-25s | Entry: %-6s | Amount: %10.2f | Memo: %s\n",
        $e['account_name'], $e['entry_type'], $e['amount'], $e['memo']
    );
}
