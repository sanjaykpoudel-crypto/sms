<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$invs = $db->fetchAll("SELECT * FROM customer_invoices WHERE customer_id = 6");
print_r($invs);

$th = $db->fetchAll("SELECT * FROM transaction_headers WHERE party_id = 6 OR party_id = '6'");
print_r($th);
