<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$header_id = '223757';
$lines = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$header_id]);
echo "Transaction lines for header $header_id:\n";
print_r($lines);

$items = $db->fetchAll("SELECT id, item_name FROM items LIMIT 10");
echo "\nItems sample:\n";
print_r($items);
