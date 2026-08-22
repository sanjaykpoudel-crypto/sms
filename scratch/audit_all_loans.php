<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "====================================================================\n";
echo " AUDITING ALL LOANS AND LIABILITIES\n";
echo "====================================================================\n";

$loans = $db->fetchAll("
    SELECT a.id, a.account_name, a.account_type, a.account_subtype,
           COALESCE(SUM(jl.debit), 0) as dr,
           COALESCE(SUM(jl.credit), 0) as cr,
           COALESCE(SUM(jl.credit - jl.debit), 0) as net_liability
    FROM accounts a
    LEFT JOIN journal_lines jl ON jl.account_id = a.id
    LEFT JOIN journal_entries je ON jl.je_id = je.je_id
    LEFT JOIN transaction_headers th ON je.transaction_id = th.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    WHERE a.account_type = 'liability'
    GROUP BY a.id, a.account_name, a.account_type, a.account_subtype
    ORDER BY a.id ASC
");

foreach ($loans as $l) {
    printf("Account %-4d: %-30s | Dr: %10.2f | Cr: %10.2f | Net Liability: Rs. %10.2f\n",
        $l['id'], substr($l['account_name'], 0, 30), $l['dr'], $l['cr'], $l['net_liability']
    );
}

echo "\n=== All Transactions on Loan Accounts ===\n";
$txns = $db->fetchAll("
    SELECT th.id, th.txn_number, th.txn_type, th.txn_date, th.memo, a.account_name, jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN accounts a ON jl.account_id = a.id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE a.account_type = 'liability' AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ORDER BY th.txn_date ASC
");

foreach ($txns as $t) {
    printf("Txn %-18s (ID %-6d) | %-10s | %-25s | Dr: %10.2f | Cr: %10.2f | Memo: %s\n",
        $t['txn_number'], $t['id'], $t['txn_date'], substr($t['account_name'], 0, 25), $t['debit'], $t['credit'], substr($t['memo'] ?? '', 0, 35)
    );
}
