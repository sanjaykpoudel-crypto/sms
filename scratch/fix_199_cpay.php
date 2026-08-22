<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/AccountingEngine.php';
$db = db();
$pdo = $db->getConnection();

$id = 199;
$pdo->beginTransaction();

$pdo->prepare("UPDATE transaction_headers SET net_amount = 2100.00, status = 'posted' WHERE id = ?")->execute([$id]);

$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);

$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-31 00:00:00', 'JOURNAL', 'Payment CPAY-00022', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id = $pdo->lastInsertId();

$stmtL = $pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, ?, ?, ?, ?, ?, 1)");
$stmtL->execute([$je_id, 2, 2100.00, 0.00, 'NONE', null]);
$stmtL->execute([$je_id, 6, 0.00, 2100.00, 'CUSTOMER', 6]);

$pdo->commit();
echo "Successfully posted journal for CPAY-00022 (ID 199)!\n";
