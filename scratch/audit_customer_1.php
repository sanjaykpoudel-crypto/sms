<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "=== All Customer 1 transactions in GL ===\n";
$lines = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 6 AND jl.entity_type = 'CUSTOMER' AND jl.entity_id = 1
");
print_r($lines);

echo "\n=== All Customer 1 in get_customer_net_balance ===\n";
require_once __DIR__ . '/../api/reference_helper.php';
echo "Net balance: " . get_customer_net_balance($db, 1, '2026-08-22') . "\n";
