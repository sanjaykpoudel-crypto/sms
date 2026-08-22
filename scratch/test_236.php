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

$hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
$res = $engine->postJournalEntry($id, 'JOURNAL', $lines, $hdr['txn_date'], 'System Opening Balances');
echo "Result of postJournalEntry: ";
var_dump($res);

$jes = $db->fetchAll("
    SELECT jl.* 
    FROM journal_lines jl 
    JOIN journal_entries je ON jl.je_id = je.je_id 
    WHERE je.transaction_id = ?
", [$id]);
echo "Lines count: " . count($jes) . "\n";
