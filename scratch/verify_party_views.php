<?php
$_SESSION['user_id'] = 'usr-admin-001';

// Customer संजय test
$_GET['id'] = '599abbd6-6f76-4d74-8618-14feed600342';
ob_start();
include __DIR__ . '/../forms/modules/master/customer/view.php';
$cust_html = ob_get_clean();

// Vendor Friendship test
$_GET['id'] = '53566186-b9c3-434f-a272-69a46a765c00';
ob_start();
include __DIR__ . '/../forms/modules/master/vendor/view.php';
$vend_html = ob_get_clean();

if (strpos($cust_html, 'JOURNAL') !== false && strpos($cust_html, 'JV-00002') !== false) {
    echo "SUCCESS: Customer view renders tagged Journal JV-00002 under Related Invoices & Journals!\n";
} else {
    echo "FAILED: Customer view missing tagged journals.\n";
}

if (strpos($vend_html, 'JOURNAL') !== false && strpos($vend_html, 'JV-00002') !== false) {
    echo "SUCCESS: Vendor view renders tagged Journal JV-00002 under Related Bills & Journals!\n";
} else {
    echo "FAILED: Vendor view missing tagged journals.\n";
}
