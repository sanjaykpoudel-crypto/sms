<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$item_id = '84'; // or whatever item id
$logs = $db->fetchAll("SELECT * FROM audit_logs WHERE table_name = 'items' OR record_id = ?", [$item_id]);
echo "Count of audit logs for item 84: " . count($logs) . "\n";
print_r($logs);

$all_item_logs = $db->fetchAll("SELECT id, table_name, action, record_id, created_at FROM audit_logs WHERE table_name = 'items'");
echo "Count of all items audit logs: " . count($all_item_logs) . "\n";
print_r($all_item_logs);

$sample_logs = $db->fetchAll("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 10");
echo "Sample 10 recent audit logs:\n";
print_r($sample_logs);
