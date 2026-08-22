<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== JOURNAL LINES FOR ACCOUNTS 6, 7, 12 ===\n";
$rows = $pdo->query("
    SELECT jl.account_id, a.account_name, je.je_type, h.txn_number, h.txn_type, jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    JOIN accounts a ON jl.account_id = a.id
    WHERE jl.account_id IN (6, 7, 12) AND h.is_deleted = 0
    ORDER BY jl.account_id, je.je_date
")->fetchAll(PDO::FETCH_ASSOC);

echo "Total Lines Found: " . count($rows) . "\n";
foreach (array_slice($rows, 0, 30) as $r) {
    echo sprintf("Acc: %-2d (%-20s) | Type: %-15s | Txn #: %-18s | Dr: %10.2f | Cr: %10.2f\n",
        $r['account_id'], $r['account_name'], $r['je_type'], $r['txn_number'], $r['debit'], $r['credit']
    );
}
