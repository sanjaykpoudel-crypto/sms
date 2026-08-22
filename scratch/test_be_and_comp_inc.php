<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

$_GET = ['date_from' => '2026-07-01', 'date_to' => '2026-08-22'];

ob_start();
include __DIR__ . '/../forms/modules/reports/financial/break_even_payback_list.php';
$html_be = ob_get_clean();
echo "Break Even Payback Report Rendered: " . strlen($html_be) . " bytes\n";
echo "Has error: " . (stripos($html_be, 'Fatal error') !== false || stripos($html_be, 'PDOException') !== false ? 'YES' : 'NO') . "\n";

ob_start();
include __DIR__ . '/../forms/modules/reports/financial/comparative_income_list.php';
$html_ci = ob_get_clean();
echo "Comparative Income Report Rendered: " . strlen($html_ci) . " bytes\n";
echo "Has error: " . (stripos($html_ci, 'Fatal error') !== false || stripos($html_ci, 'PDOException') !== false ? 'YES' : 'NO') . "\n";
