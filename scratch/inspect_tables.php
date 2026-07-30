<?php
require_once 'database/DBConnection.php';
$db = db();

echo "--- CUSTOMERS BY LOCATION ---\n";
print_r($db->fetchAll("SELECT location_id, COUNT(*) as cnt FROM customers WHERE is_deleted = 0 GROUP BY location_id"));

echo "\n--- VENDORS BY LOCATION ---\n";
print_r($db->fetchAll("SELECT location_id, COUNT(*) as cnt FROM vendors WHERE is_deleted = 0 GROUP BY location_id"));
