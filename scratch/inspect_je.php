<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== JOURNAL ENTRIES COLUMNS ===\n";
$columns = $pdo->query("DESCRIBE journal_entries")->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "  {$col['Field']} ({$col['Type']})\n";
}
