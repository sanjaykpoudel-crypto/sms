<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$journals = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, th.party_id, th.party_type, th.net_amount, th.memo, th.location_id,
           jl.jl_id, jl.account_id, a.account_name, jl.debit, jl.credit, jl.entity_type, jl.entity_id
    FROM transaction_headers th
    JOIN journal_entries je ON je.transaction_id = th.id
    JOIN journal_lines jl ON jl.je_id = je.je_id
    JOIN accounts a ON jl.account_id = a.id
    WHERE th.txn_type IN ('Journal', 'journal_entry', 'Opening Balance', 'opening_balance')
    ORDER BY th.id DESC
");

echo "Total Journal Lines found: " . count($journals) . "\n";
print_r($journals);
