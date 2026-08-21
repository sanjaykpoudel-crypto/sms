<?php
require_once 'database/DBConnection.php';
$db = db();

$header = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = 280820 OR txn_number = 'ADJ-GOKA-00010'");
print_r($header);

$lines = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$header['id']]);
echo "--- TRANSACTION LINES ---\n";
print_r($lines);

$gl = $db->fetchAll("SELECT j.*, a.account_name FROM journal_entries j JOIN accounts a ON j.account_id=a.id WHERE j.header_id = ?", [$header['id']]);
echo "--- GL IMPACT ---\n";
foreach ($gl as $g) {
    echo sprintf("Line %d | %-25s | Entry: %-6s | Amount: %10.2f | Memo: %s\n",
        $g['id'], $g['account_name'], $g['entry_type'], $g['amount'], $g['memo']
    );
}
