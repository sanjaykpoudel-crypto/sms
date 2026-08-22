<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$jv_headers = $db->fetchAll("SELECT * FROM transaction_headers WHERE txn_type = 'Journal' ORDER BY id ASC");
foreach ($jv_headers as $h) {
    echo "--------------------------------------------------------\n";
    echo "ID: {$h['id']} | Txn: {$h['txn_number']} | Date: {$h['txn_date']} | Net: {$h['net_amount']} | Memo: {$h['memo']}\n";
    
    // Check audit_logs
    $logs = $db->fetchAll("SELECT * FROM audit_logs WHERE record_id = ? OR new_values LIKE ?", [$h['id'], '%' . $h['txn_number'] . '%']);
    foreach ($logs as $l) {
        echo "  Audit Log [{$l['action']}]: " . $l['new_values'] . "\n";
    }
}
