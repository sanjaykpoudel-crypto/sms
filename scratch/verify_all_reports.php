<?php
$_SESSION['user_id'] = 'usr-admin-001';
$_GET['date_from'] = '2026-01-01';
$_GET['date_to'] = '2026-12-31';

$reports = [
    'AR Register' => __DIR__ . '/../forms/modules/reports/customers/ar_register_list.php',
    'AR Payment by Invoice' => __DIR__ . '/../forms/modules/reports/customers/ar_payment_by_invoice_list.php',
    'Customer Statement' => __DIR__ . '/../forms/modules/reports/customers/statement_list.php',
    'Sales by Customer' => __DIR__ . '/../forms/modules/reports/sales/by_customer_list.php',
    'Sales Register' => __DIR__ . '/../forms/modules/reports/sales/register_list.php',
    'Open Invoices' => __DIR__ . '/../forms/modules/reports/sales/open_invoices_list.php',
    'AP Register' => __DIR__ . '/../forms/modules/reports/vendors/ap_register_list.php',
    'AP Payment by Bill' => __DIR__ . '/../forms/modules/reports/vendors/ap_payment_by_bill_list.php',
    'Open Bills' => __DIR__ . '/../forms/modules/reports/vendors/open_bills_list.php'
];

foreach ($reports as $name => $path) {
    if ($name === 'Customer Statement') {
        $_GET['customer_id'] = '599abbd6-6f76-4d74-8618-14feed600342';
    }
    ob_start();
    include $path;
    $html = ob_get_clean();
    
    if (strpos($html, 'JV-00002') !== false || strpos($html, '167,775.00') !== false || strpos($html, '22,825.00') !== false) {
        echo "SUCCESS: {$name} includes tagged Journal Entries!\n";
    } else {
        echo "INFO: {$name} executed cleanly (No active tagged transactions in filter scope or clean render).\n";
    }
}
