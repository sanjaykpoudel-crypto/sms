<?php
require_once 'database/DBConnection.php';
$db = db();

echo "--- POS_ENTRY SCHEMA ---\n";
print_r($db->fetchAll("DESCRIBE pos_entry"));

echo "\n--- EXPENSES SCHEMA ---\n";
print_r($db->fetchAll("DESCRIBE expenses"));

echo "\n--- TRANSACTION HEADERS SCHEMA ---\n";
print_r($db->fetchAll("DESCRIBE transaction_headers"));
