<?php
require_once __DIR__ . '/database/DBConnection.php';
$db = db();

echo "=== FISCAL YEARS TABLE ===\n";
$fy_list = $db->fetchAll("SELECT id, name, status, closing_journal_id, opening_journal_id FROM fiscal_years");
foreach ($fy_list as $fy) {
    echo "ID: {$fy['id']} | Name: {$fy['name']} | Status: {$fy['status']} | Closing Journal ID: {$fy['closing_journal_id']} | Opening Journal ID: {$fy['opening_journal_id']}\n";
}

echo "\n=== TRANSACTION HEADERS FOR CLOSING ENTRIES ===\n";
$headers = $db->fetchAll("SELECT id, txn_number, txn_type, txn_date, is_deleted FROM transaction_headers WHERE txn_number LIKE 'JE-CLOSE-%'");
foreach ($headers as $h) {
    echo "Header ID: {$h['id']} | Txn Number: {$h['txn_number']} | Date: {$h['txn_date']} | Deleted: {$h['is_deleted']}\n";
}
