<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';

echo "=== Testing view.php for 408318 ===\n";
$_GET = ['id' => 408318];
ob_start();
include __DIR__ . '/../forms/modules/transactions/view.php';
$view_html = ob_get_clean();
echo "View rendered: " . strlen($view_html) . " bytes\n";
echo "Contains Prabhu Bank: " . (str_contains($view_html, 'Prabhu Bank') ? 'YES' : 'NO') . "\n";
echo "Contains 1,000.00: " . (str_contains($view_html, '1,000.00') ? 'YES' : 'NO') . "\n";

echo "\n=== Testing payment_manage.php for 408318 ===\n";
$_GET = ['id' => 408318];
ob_start();
include __DIR__ . '/../forms/modules/transactions/payment/payment_manage.php';
$manage_html = ob_get_clean();
echo "Manage rendered: " . strlen($manage_html) . " bytes\n";
echo "Contains Upendra saha selected: " . (str_contains($manage_html, 'selected>Upendra saha') || str_contains($manage_html, 'value="19" selected') ? 'YES' : 'NO') . "\n";
