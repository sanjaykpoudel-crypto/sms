<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- Find all journal entries with entity_type = 'CUSTOMER' or where customer is tagged ---\n";
$cust_journals = $db->fetchAll("
    SELECT jl.*, je.transaction_id, th.txn_number, th.txn_type, th.txn_date, th.memo, c.id as cust_id, c.full_name
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    JOIN customers c ON jl.entity_id = c.id
    WHERE jl.entity_type = 'CUSTOMER' OR jl.entity_type = 'customer'
");
echo "Found " . count($cust_journals) . " lines:\n";
print_r($cust_journals);

echo "\n--- Find all transactions with txn_type = 'Journal' or 'journal_entry' or 'Opening Balance' ---\n";
$all_journals = $db->fetchAll("
    SELECT th.*, je.je_id, je.je_type, je.memo as je_memo
    FROM transaction_headers th
    LEFT JOIN journal_entries je ON je.transaction_id = th.id
    WHERE LOWER(th.txn_type) IN ('journal', 'journal_entry', 'opening balance', 'opening_balance')
       OR th.txn_number LIKE '%JV%'
");
print_r($all_journals);
