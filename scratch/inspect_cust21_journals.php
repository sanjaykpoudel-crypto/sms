<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- Customer 21 Details ---\n";
$c = $db->fetchOne("SELECT * FROM customers WHERE id = 21");
print_r($c);

echo "\n--- Transaction Headers where party_id = 21 or memo like Krishna ---\n";
$headers = $db->fetchAll("SELECT id, txn_number, txn_type, txn_date, party_id, party_type, net_amount, memo, location_id, is_deleted, status FROM transaction_headers WHERE party_id = 21 OR memo LIKE '%Krishna%' OR id IN (SELECT transaction_id FROM journal_entries je JOIN journal_lines jl ON je.je_id = jl.je_id WHERE jl.entity_id = 21)");
print_r($headers);

echo "\n--- Journal Lines where entity_id = 21 or entity_type = 'CUSTOMER' ---\n";
$lines = $db->fetchAll("
    SELECT jl.*, je.je_date, je.je_type, je.memo as je_memo, th.txn_number, th.txn_type, th.party_id, th.party_type, th.location_id
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.entity_id = 21 OR th.party_id = 21
");
print_r($lines);

echo "\n--- All Journal Entries in System ---\n";
$all_j = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, th.party_id, th.party_type, th.location_id, th.memo,
           jl.jl_id, jl.account_id, jl.debit, jl.credit, jl.entity_type, jl.entity_id
    FROM transaction_headers th
    JOIN journal_entries je ON je.transaction_id = th.id
    JOIN journal_lines jl ON jl.je_id = je.je_id
    ORDER BY th.id DESC
    LIMIT 30
");
print_r($all_j);
