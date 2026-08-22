<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "========================================================================================\n";
echo " ALL TRANSACTIONS AFFECTING PRABHU BANK (ACC ID: 3)\n";
echo "========================================================================================\n";

$txns = $db->fetchAll("
    SELECT 
        th.id, th.txn_number, th.txn_type, th.txn_date, th.net_amount, th.memo,
        jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 3 AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ORDER BY th.txn_date ASC, th.id ASC
");

foreach ($txns as $t) {
    printf("ID: %-7d | Date: %-10s | %-16s | %-20s | Dr: %10.2f | Cr: %10.2f | Memo: %s\n",
        $t['id'], $t['txn_date'], $t['txn_type'], $t['txn_number'], $t['debit'], $t['credit'], substr($t['memo'] ?? '', 0, 40)
    );
}

echo "\n========================================================================================\n";
echo " ALL TRANSACTIONS AFFECTING ESEWA (ACC ID: 4)\n";
echo "========================================================================================\n";

$txns_esewa = $db->fetchAll("
    SELECT 
        th.id, th.txn_number, th.txn_type, th.txn_date, th.net_amount, th.memo,
        jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 4 AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ORDER BY th.txn_date ASC, th.id ASC
");

foreach ($txns_esewa as $t) {
    printf("ID: %-7d | Date: %-10s | %-16s | %-20s | Dr: %10.2f | Cr: %10.2f | Memo: %s\n",
        $t['id'], $t['txn_date'], $t['txn_type'], $t['txn_number'], $t['debit'], $t['credit'], substr($t['memo'] ?? '', 0, 40)
    );
}
