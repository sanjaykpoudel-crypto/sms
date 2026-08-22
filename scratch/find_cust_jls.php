<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$journals_by_customer = $db->fetchAll("
    SELECT c.id as customer_id, c.full_name, th.id as txn_id, th.txn_number, th.txn_type, th.txn_date, jl.debit, jl.credit, je.memo
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    JOIN customers c ON (jl.entity_id = c.id OR th.party_id = c.id)
    WHERE (UPPER(jl.entity_type) = 'CUSTOMER' OR LOWER(th.party_type) = 'customer' OR jl.entity_type IS NULL)
      AND LOWER(th.txn_type) IN ('journal', 'journal_entry', 'opening balance', 'opening_balance', 'debit_note', 'credit_note')
");

echo "Total customer-linked journal lines: " . count($journals_by_customer) . "\n";
print_r($journals_by_customer);
