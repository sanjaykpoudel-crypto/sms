<?php
require_once 'database/DBConnection.php';
$db = db();

echo "transaction_links columns:\n";
print_r($db->fetchAll("DESCRIBE transaction_links"));
