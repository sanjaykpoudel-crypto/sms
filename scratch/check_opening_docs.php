<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "Customer invoices with opening in notes/memo:\n";
print_r($db->fetchAll("SELECT id, invoice_number, customer_id, total_amount, amount_paid, balance_due, notes FROM customer_invoices WHERE notes LIKE '%opening%' OR invoice_number LIKE '%OP%' OR invoice_number LIKE '%OB%'"));

echo "\nVendor bills with opening:\n";
print_r($db->fetchAll("SELECT id, bill_number, vendor_id, total_amount, amount_paid, balance_due, notes FROM vendor_bills WHERE notes LIKE '%opening%' OR bill_number LIKE '%OP%' OR bill_number LIKE '%OB%'"));
