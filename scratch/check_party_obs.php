<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "=== Customers opening_balance ===\n";
$custs = $db->fetchAll("SELECT id, customer_code, full_name, opening_balance FROM customers WHERE opening_balance != 0 OR id IN (21, 20)");
print_r($custs);

echo "\n=== Vendors opening_balance ===\n";
$vends = $db->fetchAll("SELECT id, vendor_code, company_name, opening_balance FROM vendors WHERE opening_balance != 0 OR id IN (5)");
print_r($vends);
