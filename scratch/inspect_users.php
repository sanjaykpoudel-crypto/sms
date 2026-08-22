<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== USERS TABLE ===\n";
$users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
