<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$_GET = [
    'customer_id' => '21',
    'from_date' => '2026-06-01',
    'to_date' => '2026-08-22'
];

ob_start();
include __DIR__ . '/../forms/modules/reports/customers/statement_list.php';
$html = ob_get_clean();

echo "Statement Rendered: " . strlen($html) . " bytes\n";
echo "Number of statement rows: " . substr_count($html, '<span class="ns-badge') . "\n";
