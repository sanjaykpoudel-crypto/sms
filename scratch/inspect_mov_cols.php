<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== INVENTORY MOVEMENTS COLUMNS ===\n";
print_r($pdo->query("DESCRIBE inventory_movements")->fetchAll(PDO::FETCH_ASSOC));
