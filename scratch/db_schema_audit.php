<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== DATABASE SCHEMA AUDIT ===\n\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Total Tables: " . count($tables) . "\n\n";

foreach ($tables as $table) {
    echo "TABLE: {$table}\n";
    $columns = $pdo->query("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']}) " . ($col['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . " Key: {$col['Key']} Default: {$col['Default']}\n";
    }
    $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    echo "  Row Count: {$count}\n\n";
}
