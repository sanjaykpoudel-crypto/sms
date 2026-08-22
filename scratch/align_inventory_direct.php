<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

$id = 266457;
$pdo->beginTransaction();

$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);

$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-09 00:00:00', 'JOURNAL', 'Automated Inventory Subledger GL Alignment', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id = $pdo->lastInsertId();

$stmtL = $pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, ?, ?, ?, 'NONE', NULL, 1)");
$stmtL->execute([$je_id, 7, 63868.48, 0.00]);
$stmtL->execute([$je_id, 38, 0.00, 63868.48]);

$pdo->prepare("UPDATE transaction_headers SET net_amount = 63868.48 WHERE id = ?")->execute([$id]);

$pdo->commit();
echo "Committed Inventory Alignment JE ID $je_id for Rs. 63,868.48\n";
