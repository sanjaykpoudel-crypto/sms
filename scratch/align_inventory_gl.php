<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/AccountingEngine.php';
$db = db();
$engine = AccountingEngine::getInstance();

$hdr_exists = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = 'JV-GOKA-00015'");
$id = $hdr_exists['id'] ?? 266457;

$engine->deleteJournalForTransaction($id);

$lines = [
    ['account_id' => 7, 'debit' => 75801.51, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
    ['account_id' => 38, 'debit' => 0.00, 'credit' => 75801.51, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
];

$engine->postJournalEntry($id, 'JOURNAL', $lines, '2026-08-09', 'Automated Inventory Subledger GL Alignment');
$db->execute("UPDATE transaction_headers SET net_amount = 75801.51 WHERE id = ?", [$id]);

echo "Posted Inventory Alignment for Rs. 75,801.51\n";
