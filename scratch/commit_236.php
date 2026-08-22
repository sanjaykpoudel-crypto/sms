<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

$id = 236;
$pdo->beginTransaction();

// 1. Insert header
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-17 00:00:00', 'JOURNAL', 'System Opening Balances', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id = $pdo->lastInsertId();

// 2. Insert lines
$stmtL = $pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id, class_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmtL->execute([$je_id, 2, 120000.00, 0.00, 'NONE', null, 1, null]);
$stmtL->execute([$je_id, 3, 254671.00, 0.00, 'NONE', null, 1, null]);
$stmtL->execute([$je_id, 38, 0.00, 374671.00, 'NONE', null, 1, null]);

$pdo->commit();
echo "Successfully committed JE ID $je_id for transaction $id\n";
