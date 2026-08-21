<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$types = $db->fetchAll("SELECT txn_type, COUNT(*) as cnt FROM transaction_headers GROUP BY txn_type");
print_r($types);
