<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$_GET = [
    'vendor_id' => 5,
    'date_from' => '2026-07-01',
    'date_to' => '2026-08-31'
];

ob_start();
include __DIR__ . '/../forms/modules/reports/vendors/ap_register_list.php';
$ap_html = ob_get_clean();

echo "AP Register rendered: " . strlen($ap_html) . " bytes\n";
echo "Contains JV-00002: " . (str_contains($ap_html, 'JV-00002') ? 'YES' : 'NO') . "\n";
echo "Contains 22,825.00 or 7,300.00: " . (str_contains($ap_html, '22,825.00') || str_contains($ap_html, '7,300.00') ? 'YES' : 'NO') . "\n";
echo "Contains VPAY-00017: " . (str_contains($ap_html, 'VPAY-00017') ? 'YES' : 'NO') . "\n";
