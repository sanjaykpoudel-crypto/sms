<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$logs = $db->fetchAll("
    SELECT * FROM audit_logs 
    WHERE (record_type LIKE '%journal%' OR description LIKE '%JV-%' OR table_name LIKE '%journal%' OR new_values LIKE '%JV-%' OR new_values LIKE '%Opening receivable%')
    ORDER BY id DESC
    LIMIT 30
");

echo "Found " . count($logs) . " audit logs:\n";
foreach ($logs as $l) {
    echo "ID: {$l['id']} | Action: {$l['action']} | Record: {$l['record_type']} / {$l['record_id']} | Desc: {$l['description']}\n";
    if (!empty($l['new_values'])) {
        echo "  New Values: " . substr($l['new_values'], 0, 300) . "\n";
    }
}
