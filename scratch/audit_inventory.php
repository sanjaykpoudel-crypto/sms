<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
$db = db();

echo "====================================================================\n";
echo " INVENTORY RECONCILIATION AUDIT\n";
echo "====================================================================\n";

$inv_sub = re_get_inventory_valuation($db, date('Y-m-d'));
$inv_gl = re_get_inventory_gl_balance($db, date('Y-m-d'));

echo "Inventory Subledger (Items Valuation): Rs. " . number_format($inv_sub, 2) . "\n";
echo "Inventory GL (Account #7 Balance): Rs. " . number_format($inv_gl, 2) . "\n";
echo "Difference: Rs. " . number_format($inv_sub - $inv_gl, 2) . "\n\n";

echo "=== Transactions affecting Inventory Account #7 in GL ===\n";
$txns = $db->fetchAll("
    SELECT 
        th.id, th.txn_number, th.txn_type, th.txn_date, th.net_amount, th.memo,
        SUM(jl.debit) as dr, SUM(jl.credit) as cr
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 7 AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    GROUP BY th.id, th.txn_number, th.txn_type, th.txn_date, th.net_amount, th.memo
    ORDER BY th.txn_date ASC, th.id ASC
");

foreach ($txns as $t) {
    printf("ID: %-7d | Date: %-10s | %-16s | %-20s | Dr: %10.2f | Cr: %10.2f | Memo: %s\n",
        $t['id'], $t['txn_date'], $t['txn_type'], $t['txn_number'], $t['dr'], $t['cr'], substr($t['memo'] ?? '', 0, 40)
    );
}
