<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== SHOW COLUMNS FROM journal_entries ===\n";
print_r($pdo->query("SHOW COLUMNS FROM journal_entries")->fetchAll(PDO::FETCH_ASSOC));

echo "=== SHOW COLUMNS FROM journal_lines ===\n";
print_r($pdo->query("SHOW COLUMNS FROM journal_lines")->fetchAll(PDO::FETCH_ASSOC));

echo "=== FIRST 5 ROWS FROM journal_entries ===\n";
print_r($pdo->query("SELECT * FROM journal_entries LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));

echo "=== FIRST 5 ROWS FROM journal_lines ===\n";
print_r($pdo->query("SELECT * FROM journal_lines LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));
