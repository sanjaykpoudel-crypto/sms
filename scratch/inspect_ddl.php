<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== SHOW CREATE TABLE journal_entries ===\n";
$row = $pdo->query("SHOW CREATE TABLE journal_entries")->fetch(PDO::FETCH_ASSOC);
echo $row['Create Table'] . "\n\n";

echo "=== SHOW CREATE TABLE journal_lines ===\n";
$row2 = $pdo->query("SHOW CREATE TABLE journal_lines")->fetch(PDO::FETCH_ASSOC);
echo $row2['Create Table'] . "\n\n";
