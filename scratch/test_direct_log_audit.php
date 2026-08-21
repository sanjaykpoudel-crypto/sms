<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_SESSION['user_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';
require_once __DIR__ . '/../api/InventoryEngine.php';

$db = db();
$item_id = '84';

log_audit('items', 'update', $item_id, ['cost_price' => 336.25], ['cost_price' => 337.75, 'case_purchase_price' => 4053.00, 'reason' => 'Test log']);

$logs = $db->fetchAll("SELECT * FROM audit_logs WHERE record_id = ? AND table_name = 'items'", [$item_id]);
echo "Audit logs count for item 84: " . count($logs) . "\n";
print_r($logs);
