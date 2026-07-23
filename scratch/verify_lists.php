<?php
$_SESSION['user_id'] = 'usr-admin-001';

// Customer List test
ob_start();
include __DIR__ . '/../forms/modules/master/customer/customer_list.php';
$cust_html = ob_get_clean();

// Vendor List test
ob_start();
include __DIR__ . '/../forms/modules/master/vendor/vendor_list.php';
$vend_html = ob_get_clean();

if (strpos($cust_html, 'Gurkha Cafe') !== false && strpos($cust_html, '167,775.00') !== false) {
    echo "SUCCESS: Customer List includes tagged Journal total sales and due for Gurkha Cafe (167,775.00)!\n";
} else {
    echo "FAILED: Customer List missing tagged Journal totals.\n";
}

if (strpos($vend_html, 'Friendship suppliers pvt ltd') !== false && strpos($vend_html, '22,825.00') !== false) {
    echo "SUCCESS: Vendor List includes tagged Journal total purchase and remaining for Friendship suppliers pvt ltd (22,825.00)!\n";
} else {
    echo "FAILED: Vendor List missing tagged Journal totals.\n";
}
