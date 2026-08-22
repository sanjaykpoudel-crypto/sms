<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$imbalances = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, th.net_amount, th.memo,
           SUM(jl.debit) as tot_debit,
           SUM(jl.credit) as tot_credit,
           (SUM(jl.debit) - SUM(jl.credit)) as diff
    FROM transaction_headers th
    JOIN journal_entries je ON je.transaction_id = th.id
    JOIN journal_lines jl ON jl.je_id = je.je_id
    WHERE th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    GROUP BY th.id, th.txn_number, th.txn_type, th.txn_date, th.net_amount, th.memo
    HAVING ABS(SUM(jl.debit) - SUM(jl.credit)) > 0.01
");

echo "Imbalanced transactions in DB: " . count($imbalances) . "\n";
foreach ($imbalances as $imb) {
    echo "ID: {$imb['id']} | Txn: {$imb['txn_number']} | Type: {$imb['txn_type']} | Debit: {$imb['tot_debit']} | Credit: {$imb['tot_credit']} | Diff: {$imb['diff']} | Memo: {$imb['memo']}\n";
}
