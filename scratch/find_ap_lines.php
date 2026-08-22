<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$lines = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, jl.debit, jl.credit, jl.entity_id
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 12 AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ORDER BY th.txn_date ASC
");

foreach ($lines as $l) {
    echo "Txn {$l['txn_number']} (ID: {$l['id']}) | Date: {$l['txn_date']} | Dr: {$l['debit']} | Cr: {$l['credit']} | Entity: {$l['entity_id']}\n";
}
