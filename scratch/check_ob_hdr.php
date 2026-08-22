<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$r = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number = 'OPENING-BALANCES'");
print_r($r);
