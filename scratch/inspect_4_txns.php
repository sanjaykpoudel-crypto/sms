<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$ids = [66, 67, 130, 200];
foreach ($ids as $id) {
    $hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
    echo "========================================================\n";
    echo "ID: $id | Txn: {$hdr['txn_number']} | Type: {$hdr['txn_type']} | Net: {$hdr['net_amount']}\n";
    $lines = $db->fetchAll("
        SELECT jl.*, a.account_name 
        FROM journal_lines jl 
        JOIN journal_entries je ON jl.je_id = je.je_id 
        JOIN accounts a ON jl.account_id = a.id 
        WHERE je.transaction_id = ?
    ", [$id]);
    foreach ($lines as $l) {
        echo "  Account: {$l['account_name']} (ID: {$l['account_id']}) | Dr: {$l['debit']} | Cr: {$l['credit']} | Entity: {$l['entity_type']} ({$l['entity_id']})\n";
    }
}
