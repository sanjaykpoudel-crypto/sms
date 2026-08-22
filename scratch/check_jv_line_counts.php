<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$jvs = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_date, th.net_amount, th.memo, th.status,
           COUNT(jl.jl_id) as line_count
    FROM transaction_headers th
    LEFT JOIN journal_entries je ON je.transaction_id = th.id
    LEFT JOIN journal_lines jl ON jl.je_id = je.je_id
    WHERE th.txn_type = 'Journal'
    GROUP BY th.id, th.txn_number, th.txn_date, th.net_amount, th.memo, th.status
    ORDER BY th.id ASC
");

echo "Total Journal Transactions: " . count($jvs) . "\n";
print_r($jvs);
