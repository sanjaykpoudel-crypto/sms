<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$jvs = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_date, th.net_amount, th.memo, th.reference_number, th.location_id, th.status,
           COUNT(jl.jl_id) as line_count
    FROM transaction_headers th
    LEFT JOIN journal_entries je ON je.transaction_id = th.id
    LEFT JOIN journal_lines jl ON jl.je_id = je.je_id
    WHERE th.txn_type = 'Journal'
    GROUP BY th.id, th.txn_number, th.txn_date, th.net_amount, th.memo, th.reference_number, th.location_id, th.status
    ORDER BY th.id ASC
");

foreach ($jvs as $j) {
    echo "ID: {$j['id']} | Txn: {$j['txn_number']} | Date: {$j['txn_date']} | Amt: {$j['net_amount']} | Lines: {$j['line_count']} | Loc: {$j['location_id']} | Memo: {$j['memo']}\n";
}
