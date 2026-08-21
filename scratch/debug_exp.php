<?php
require_once 'database/DBConnection.php';
$db = db();

$r1 = $db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) AS exp
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
");

echo "Expense Query (account_type = 'expense'): " . $r1['exp'] . "\n";

$r2 = $db->fetchAll("
    SELECT a.id, a.account_name, a.account_type, SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) AS bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
    GROUP BY a.id, a.account_name, a.account_type
");

$tot = 0;
foreach ($r2 as $row) {
    echo "ID: {$row['id']} | Type: {$row['account_type']} | Name: {$row['account_name']} | Bal: {$row['bal']}\n";
    $tot += $row['bal'];
}
echo "Sum of individual expense accounts: {$tot}\n";
