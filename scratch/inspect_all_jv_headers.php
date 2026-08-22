<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$jvs = $db->fetchAll("SELECT * FROM transaction_headers WHERE txn_type = 'Journal' ORDER BY id ASC");
echo "Total JV headers: " . count($jvs) . "\n";
foreach ($jvs as $j) {
    echo "ID: {$j['id']} | Txn: {$j['txn_number']} | Date: {$j['txn_date']} | Net Amount: {$j['net_amount']} | Memo: {$j['memo']} | Party: {$j['party_id']} ({$j['party_type']})\n";
}
