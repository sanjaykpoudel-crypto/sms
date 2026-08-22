<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$lines = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, jl.debit, jl.credit, jl.entity_type, jl.entity_id
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 6 AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
      AND (jl.entity_type != 'CUSTOMER' OR jl.entity_id IS NULL OR jl.entity_id = 0)
");
echo "AR lines without customer entity:\n";
print_r($lines);
