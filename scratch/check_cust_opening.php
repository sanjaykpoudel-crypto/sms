<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- Customer 21 (Krishna Lomus) full record ---\n";
$c = $db->fetchOne("SELECT * FROM customers WHERE id = 21");
print_r($c);

echo "\n--- All customers with opening_balance != 0 ---\n";
$all_c = $db->fetchAll("SELECT id, full_name, opening_balance FROM customers WHERE opening_balance != 0");
print_r($all_c);
