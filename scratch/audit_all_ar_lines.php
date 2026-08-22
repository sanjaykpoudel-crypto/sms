<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$lines = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, jl.debit, jl.credit, jl.entity_id
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 6 AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ORDER BY th.txn_date ASC, th.id ASC
");

$cust_totals = [];
foreach ($lines as $l) {
    $cid = $l['entity_id'] ?? 0;
    $net = $l['debit'] - $l['credit'];
    $cust_totals[$cid] = ($cust_totals[$cid] ?? 0) + $net;
}

echo "GL AR Breakdown by entity_id:\n";
foreach ($cust_totals as $cid => $tot) {
    $cname = $db->fetchOne("SELECT full_name FROM customers WHERE id = ?", [$cid])['full_name'] ?? 'Unknown/None';
    echo "  Customer $cid ($cname): Rs. " . number_format($tot, 2) . "\n";
}
