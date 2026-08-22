<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

$_GET = ['date_to' => '2026-08-22'];
ob_start();
include __DIR__ . '/../forms/modules/reports/financial/balance_sheet_list.php';
$html_bs = ob_get_clean();

echo "Standard Balance Sheet Rendered: " . strlen($html_bs) . " bytes\n";
echo "Has 'Fixed Deposit Account': " . (str_contains($html_bs, 'Fixed Deposit Account') ? 'YES' : 'NO') . "\n";
echo "Has 'Balance Sheet is BALANCED': " . (str_contains($html_bs, 'Balance Sheet is BALANCED') ? 'YES' : 'NO') . "\n";
echo "Has 'RECONCILIATION ERROR': " . (str_contains($html_bs, 'RECONCILIATION ERROR') ? 'YES' : 'NO') . "\n";

ob_start();
include __DIR__ . '/../forms/modules/reports/financial/comparative_balance_sheet_list.php';
$html_comp = ob_get_clean();

echo "\nComparative Balance Sheet Rendered: " . strlen($html_comp) . " bytes\n";
echo "Has 'Fixed Deposit Account': " . (str_contains($html_comp, 'Fixed Deposit Account') ? 'YES' : 'NO') . "\n";
echo "Has 'Balanced ✓': " . (str_contains($html_comp, 'Balanced ✓') ? 'YES' : 'NO') . "\n";
