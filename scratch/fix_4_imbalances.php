<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

$pdo->beginTransaction();

// 1. Fix ID 66: Add Cash 5950.00 debit line
$je_66 = $db->fetchOne("SELECT je_id FROM journal_entries WHERE transaction_id = 66")['je_id'];
$pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, 2, 5950.00, 0.00, 'NONE', NULL, 1)")->execute([$je_66]);

// 2. Fix ID 67: Add missing Inventory 11925.00 debit line
$je_67 = $db->fetchOne("SELECT je_id FROM journal_entries WHERE transaction_id = 67")['je_id'];
$pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, 7, 11925.00, 0.00, 'ITEM', 40, 1)")->execute([$je_67]);

// 3. Fix ID 130: Add missing Inventory 408.00 credit line for item 35
$je_130 = $db->fetchOne("SELECT je_id FROM journal_entries WHERE transaction_id = 130")['je_id'];
$pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, 7, 0.00, 408.00, 'ITEM', 35, 1)")->execute([$je_130]);

// 4. Fix ID 200: Add Prabhu Bank 5500.00 credit line
$je_200 = $db->fetchOne("SELECT je_id FROM journal_entries WHERE transaction_id = 200")['je_id'];
$pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, 3, 0.00, 5500.00, 'NONE', NULL, 1)")->execute([$je_200]);

$pdo->commit();
echo "Successfully balanced the 4 transactions!\n";
