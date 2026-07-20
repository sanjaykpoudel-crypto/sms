<?php
require_once 'database/DBConnection.php';
$db = db();
$entries = $db->fetchAll("
    SELECT j.*, a.account_name, a.account_type, a.account_subtype
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_number = 'ADJ-0001'
");

foreach ($entries as $e) {
    echo "Account: {$e['account_name']} ({$e['account_type']}/{$e['account_subtype']}) | Type: {$e['entry_type']} | Amount: {$e['amount']} | Memo: {$e['memo']}\n";
}
