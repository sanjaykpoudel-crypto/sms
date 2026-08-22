<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/AccountingEngine.php';
$db = db();
$engine = AccountingEngine::getInstance();

$id = 236;
$lines = [
    ['account_id' => 2, 'debit' => 120000.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
    ['account_id' => 3, 'debit' => 254671.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
    ['account_id' => 38, 'debit' => 0.00, 'credit' => 374671.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
];

$engine->deleteJournalForTransaction($id);
$je_id = $engine->postJournalEntry($id, 'JOURNAL', $lines, '2026-07-17', 'System Opening Balances');
echo "Posted JE ID: $je_id for Txn $id\n";
