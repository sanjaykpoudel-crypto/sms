<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
$_GET = ['id' => 174];
ob_start();
include __DIR__ . '/../forms/modules/transactions/view.php';
$html = ob_get_clean();

echo "Rendered ID 174: " . strlen($html) . " bytes\n";
echo "Contains Edit & Add Journal Lines: " . (str_contains($html, 'Edit & Add Journal Lines') ? 'YES' : 'NO') . "\n";
