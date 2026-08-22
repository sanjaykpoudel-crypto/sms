<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "=== Customer 6 status in DB ===\n";
print_r($db->fetchOne("SELECT * FROM customers WHERE id = 6"));

echo "=== Customer 11 transactions in GL ===\n";
$lines = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 6 AND jl.entity_type = 'CUSTOMER' AND jl.entity_id = 11
");
print_r($lines);
