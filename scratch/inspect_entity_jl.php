<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$lines = $db->fetchAll("SELECT jl.*, je.transaction_id, th.txn_number, th.txn_type, th.party_id, th.party_type, c.full_name as cust_name, v.company_name as vend_name
FROM journal_lines jl
JOIN journal_entries je ON jl.je_id = je.je_id
JOIN transaction_headers th ON je.transaction_id = th.id
LEFT JOIN customers c ON jl.entity_id = c.id
LEFT JOIN vendors v ON jl.entity_id = v.id
WHERE jl.entity_type IN ('CUSTOMER', 'VENDOR') OR jl.entity_id IS NOT NULL OR th.party_id IS NOT NULL
LIMIT 50");

echo "Count: " . count($lines) . "\n";
print_r($lines);
