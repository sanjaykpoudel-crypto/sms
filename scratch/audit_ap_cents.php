<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$vends = $db->fetchAll("
    SELECT v.id, v.company_name,
           SUM(vb.balance_due) as subledger_due
    FROM vendors v
    LEFT JOIN vendor_bills vb ON v.id = vb.vendor_id AND vb.payment_status != 'paid'
    GROUP BY v.id, v.company_name
    HAVING subledger_due > 0
");
print_r($vends);

$gl_ap = $db->fetchOne("
    SELECT SUM(jl.credit - jl.debit) as gl_ap
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 12 AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
");
echo "GL AP: " . $gl_ap['gl_ap'] . "\n";
