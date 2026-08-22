<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$_GET = [
    'customer_id' => 21,
    'from_date' => '2026-07-01',
    'to_date' => '2026-08-31'
];

ob_start();
include __DIR__ . '/../forms/modules/reports/customers/statement_list.php';
$stmt_html = ob_get_clean();

echo "Customer Statement rendered: " . strlen($stmt_html) . " bytes\n";
echo "Contains JV-00002: " . (str_contains($stmt_html, 'JV-00002') ? 'YES' : 'NO') . "\n";
echo "Contains 2,900.00: " . (str_contains($stmt_html, '2,900.00') ? 'YES' : 'NO') . "\n";
echo "Contains CPAY-00005 (2,500.00): " . (str_contains($stmt_html, 'CPAY-00005') ? 'YES' : 'NO') . "\n";
echo "Contains CPAY-GOKA-00042 (400.00 / 580.00): " . (str_contains($stmt_html, 'CPAY-GOKA-00042') ? 'YES' : 'NO') . "\n";
