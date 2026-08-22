<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$jv_headers = $db->fetchAll("SELECT * FROM transaction_headers WHERE txn_number LIKE '%JV%' ORDER BY id DESC");
foreach ($jv_headers as $h) {
    echo "==================================================\n";
    echo "Header ID: {$h['id']} | Txn: {$h['txn_number']} | Date: {$h['txn_date']} | Party: {$h['party_id']} ({$h['party_type']}) | Memo: {$h['memo']}\n";
    
    $je = $db->fetchAll("SELECT * FROM journal_entries WHERE transaction_id = ?", [$h['id']]);
    echo "  journal_entries count: " . count($je) . "\n";
    foreach ($je as $e) {
        echo "    JE ID: {$e['je_id']} | Date: {$e['je_date']} | Memo: {$e['memo']}\n";
        $lines = $db->fetchAll("SELECT jl.*, a.account_name FROM journal_lines jl JOIN accounts a ON jl.account_id = a.id WHERE jl.je_id = ?", [$e['je_id']]);
        foreach ($lines as $l) {
            echo "      Line: ID: {$l['jl_id']} | Acc: {$l['account_name']} ({$l['account_id']}) | Dr: {$l['debit']} | Cr: {$l['credit']} | Entity: {$l['entity_type']} / {$l['entity_id']}\n";
        }
    }
}
