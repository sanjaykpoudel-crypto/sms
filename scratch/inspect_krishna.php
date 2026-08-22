<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- All transaction_headers for Krishna Lomus (party_id = 21) ---\n";
$txns = $db->fetchAll("SELECT * FROM transaction_headers WHERE party_id = 21 OR memo LIKE '%Krishna%' ORDER BY txn_date ASC, id ASC");
print_r($txns);

echo "\n--- All customer_invoices for Krishna Lomus ---\n";
$invs = $db->fetchAll("SELECT * FROM customer_invoices WHERE customer_id = 21");
print_r($invs);

echo "\n--- All payments for Krishna Lomus ---\n";
$pays = $db->fetchAll("SELECT * FROM payments WHERE customer_id = 21");
print_r($pays);
