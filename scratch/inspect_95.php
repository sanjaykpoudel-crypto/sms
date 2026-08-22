<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== HEADER 95 (VI-00010) ===\n";
print_r($pdo->query("SELECT * FROM transaction_headers WHERE id = 95")->fetch(PDO::FETCH_ASSOC));

echo "=== VENDOR BILL 95 ===\n";
print_r($pdo->query("SELECT * FROM vendor_bills WHERE header_id = 95")->fetch(PDO::FETCH_ASSOC));

echo "=== TRANSACTION LINES FOR HEADER 95 ===\n";
print_r($pdo->query("SELECT * FROM transaction_lines WHERE header_id = 95")->fetchAll(PDO::FETCH_ASSOC));
