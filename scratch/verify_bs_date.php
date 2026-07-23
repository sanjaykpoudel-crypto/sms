<?php
$_SESSION['user_id'] = 'usr-admin-001';
unset($_GET['date_from'], $_GET['date_to']);

ob_start();
include __DIR__ . '/../forms/modules/reports/financial/balance_sheet_list.php';
$html = ob_get_clean();

$today = date('Y-m-d');
$expected_from = date('Y-m-d', strtotime('-1 month'));

if (strpos($html, 'value="' . $expected_from . '"') !== false) {
    echo "SUCCESS: Balance Sheet default From Date is set to 1 month prior to today ({$expected_from})!\n";
} else {
    echo "FAILED: Expected date {$expected_from} not found in output HTML.\n";
}
