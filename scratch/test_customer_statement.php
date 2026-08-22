<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$_GET = [
    'customer_id' => '1',
    'date_from' => '2024-01-01',
    'date_to' => date('Y-m-d')
];

require_once __DIR__ . '/../forms/modules/reports/rpt_helpers.php';

ob_start();
include __DIR__ . '/../forms/modules/reports/customers/statement_list.php';
$stmt_html = ob_get_clean();

echo "Customer Statement HTML Generated: " . strlen($stmt_html) . " bytes\n";
echo "Contains Opening Balance: " . (str_contains($stmt_html, 'Opening Balance') ? 'YES' : 'NO') . "\n";
echo "Contains Journal Voucher / Entries: " . (str_contains($stmt_html, 'Journal') || str_contains($stmt_html, 'JV') ? 'YES' : 'NO') . "\n";
