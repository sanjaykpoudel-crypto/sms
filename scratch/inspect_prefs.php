<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== ACCOUNTING PREFERENCES ===\n";
$prefs = $pdo->query("SELECT * FROM system_info WHERE meta_field LIKE '%account%' OR meta_field LIKE 'default%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($prefs as $p) {
    echo sprintf("Field: %-30s | Value: %s\n", $p['meta_field'], $p['meta_value']);
}

echo "\n=== RESOLVED SYSTEM DEFAULTS ===\n";
echo "AR Account:        " . get_effective_account(null, 'receivable') . "\n";
echo "AP Account:        " . get_effective_account(null, 'payable') . "\n";
echo "Inventory Account: " . get_effective_account(null, 'inventory') . "\n";
echo "Income Account:    " . get_effective_account(null, 'income') . "\n";
echo "COGS Account:      " . get_effective_account(null, 'cogs') . "\n";
