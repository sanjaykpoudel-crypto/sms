<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== HEADER 638753 ===\n";
print_r($pdo->query("SELECT * FROM transaction_headers WHERE id = 638753")->fetch(PDO::FETCH_ASSOC));

echo "=== PAYMENTS FOR HEADER 638753 ===\n";
print_r($pdo->query("SELECT * FROM payments WHERE header_id = 638753")->fetchAll(PDO::FETCH_ASSOC));
