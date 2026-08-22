<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "Customers columns:\n";
print_r($db->fetchAll("DESCRIBE customers"));

echo "\nVendors columns:\n";
print_r($db->fetchAll("DESCRIBE vendors"));
