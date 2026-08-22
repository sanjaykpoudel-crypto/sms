<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';

echo "=== Testing view.php for 174 ===\n";
$_GET = ['id' => 174];
ob_start();
include __DIR__ . '/../forms/modules/transactions/view.php';
$view_html = ob_get_clean();
echo "View rendered: " . strlen($view_html) . " bytes\n";
echo "Contains Krishna Lomus: " . (str_contains($view_html, 'Krishna Lomus') ? 'YES' : 'NO') . "\n";
echo "Contains Friendship suppliers: " . (str_contains($view_html, 'Friendship suppliers') ? 'YES' : 'NO') . "\n";
echo "Contains prabesh khanal: " . (str_contains($view_html, 'prabesh khanal') ? 'YES' : 'NO') . "\n";
echo "Contains Related Records: " . (str_contains($view_html, 'VPAY-00017') || str_contains($view_html, 'CPAY-00005') ? 'YES' : 'NO') . "\n";

echo "\n=== Testing journal_manage.php for 174 ===\n";
$_GET = ['id' => 174];
ob_start();
include __DIR__ . '/../forms/modules/transactions/journal/journal_manage.php';
$manage_html = ob_get_clean();
echo "Manage rendered: " . strlen($manage_html) . " bytes\n";
echo "Contains Krishna Lomus selected: " . (str_contains($manage_html, 'Krishna Lomus') ? 'YES' : 'NO') . "\n";
echo "Contains Friendship suppliers selected: " . (str_contains($manage_html, 'Friendship suppliers') ? 'YES' : 'NO') . "\n";
