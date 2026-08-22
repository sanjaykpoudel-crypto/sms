<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$ar_lines = $db->fetchAll("
    SELECT jl.*, je.transaction_id, je.memo as je_memo, th.txn_number, th.txn_type, th.txn_date, th.party_id, th.memo as th_memo
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 6
    ORDER BY th.txn_date DESC
    LIMIT 30
");

echo "Count of lines touching Accounts Receivable (account 6): " . count($ar_lines) . "\n";
print_r($ar_lines);
