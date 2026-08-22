<?php
session_start();
$_SESSION['user_id'] = 2;

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'id' => 397548,
    'txn_number' => 'JV-GOKA-00027',
    'txn_date' => '2026-08-22',
    'location_id' => 1,
    'ref_number' => '',
    'memo' => 'Office Expense & Stationery',
    'account_id' => [27, 2], // 27 = Office Expenses, 2 = Cash
    'debit' => [77.00, 0.00],
    'credit' => [0.00, 77.00],
    'line_memo' => ['Stationery purchased', 'Paid from cash'],
    'line_party_type' => ['', ''],
    'line_party_id' => ['', '']
];

ob_start();
include __DIR__ . '/../api/save_journal.php';
$res = ob_get_clean();

echo "Response from save_journal.php:\n$res\n\n";

echo "=== Verification in DB for ID 397548 ===\n";
$th = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = 397548");
echo "Transaction Header: Net Amount = {$th['net_amount']} | Status = {$th['status']} | Memo = {$th['memo']}\n";

$jes = $db->fetchAll("
    SELECT jl.*, a.account_name 
    FROM journal_lines jl 
    JOIN journal_entries je ON jl.je_id = je.je_id 
    JOIN accounts a ON jl.account_id = a.id 
    WHERE je.transaction_id = 397548
");
echo "GL Lines: " . count($jes) . "\n";
foreach ($jes as $j) {
    echo "  Account: {$j['account_name']} | Dr: {$j['debit']} | Cr: {$j['credit']} | Memo: {$j['memo']}\n";
}
