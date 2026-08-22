<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

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

echo "\n=== Testing view.php for 397548 ===\n";
$_GET = ['id' => 397548];
ob_start();
include __DIR__ . '/../forms/modules/transactions/view.php';
$view_html = ob_get_clean();
echo "View rendered: " . strlen($view_html) . " bytes\n";
echo "Contains Journal Lines (2): " . (str_contains($view_html, 'Journal Lines (2)') ? 'YES' : 'NO') . "\n";
echo "Contains Office Expenses: " . (str_contains($view_html, 'Office Expenses') || str_contains($view_html, '77.00') ? 'YES' : 'NO') . "\n";

echo "\n=== Testing journal_manage.php (Edit) for 397548 ===\n";
$_GET = ['id' => 397548];
ob_start();
include __DIR__ . '/../forms/modules/transactions/journal/journal_manage.php';
$manage_html = ob_get_clean();
echo "Edit form rendered: " . strlen($manage_html) . " bytes\n";
echo "Contains 77.00: " . (str_contains($manage_html, '77.00') ? 'YES' : 'NO') . "\n";
