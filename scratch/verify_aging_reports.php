<?php
$_SESSION['user_id'] = 'usr-admin-001';

// AR Aging test
ob_start();
include __DIR__ . '/../forms/modules/reports/customers/receivable_aging_list.php';
$ar_out = ob_get_clean();

// AP Aging test
ob_start();
include __DIR__ . '/../forms/modules/reports/vendors/payable_aging_list.php';
$ap_out = ob_get_clean();

if (strpos($ar_out, 'Gurkha Cafe') !== false && strpos($ar_out, '167,775.00') !== false) {
    echo "SUCCESS: AR Aging Report includes tagged Journal balance for Gurkha Cafe (167,775.00)!\n";
} else {
    echo "FAILED: AR Aging Report missing tagged Journal.\n";
}

if (strpos($ap_out, 'Friendship suppliers pvt ltd') !== false && strpos($ap_out, '22,825.00') !== false) {
    echo "SUCCESS: AP Aging Report includes tagged Journal balance for Friendship suppliers pvt ltd (22,825.00)!\n";
} else {
    echo "FAILED: AP Aging Report missing tagged Journal.\n";
}
