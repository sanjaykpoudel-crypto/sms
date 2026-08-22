<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== GL BALANCES BY ACCOUNT ID ===\n";
$rows = $pdo->query("
    SELECT a.id, a.account_name, a.account_type, a.account_subtype, a.normal_balance,
           SUM(jl.debit) as total_dr, SUM(jl.credit) as total_cr,
           SUM(jl.debit - jl.credit) as net_dr
    FROM accounts a
    JOIN journal_lines jl ON jl.account_id = a.id
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY a.id, a.account_name, a.account_type, a.account_subtype, a.normal_balance
    ORDER BY a.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    echo sprintf("ID: %-3d | Name: %-35s | Type: %-10s | Sub: %-20s | Dr: %10.2f | Cr: %10.2f | Net: %10.2f\n",
        $r['id'], $r['account_name'], $r['account_type'], $r['account_subtype'], $r['total_dr'], $r['total_cr'], $r['net_dr']
    );
}
