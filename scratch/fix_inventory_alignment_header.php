<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

$id = 266457;
$pdo->beginTransaction();

// 1. Transaction header
$db->execute("DELETE FROM transaction_headers WHERE id = ?", [$id]);
$stmtH = $pdo->prepare("
    INSERT INTO transaction_headers 
    (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, net_amount, created_by, location_id)
    VALUES (?, 'JV-GOKA-00015', 'Journal', '2026-08-09', 2026, 8, '2026-08', 'posted', 'Automated Inventory Subledger GL Alignment', 63868.48, 2, 1)
");
$stmtH->execute([$id]);

// 2. Journal Entry
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);

$stmtJE = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-09 00:00:00', 'JOURNAL', 'Automated Inventory Subledger GL Alignment', 'POSTED', 8)");
$stmtJE->execute([$id]);
$je_id = $pdo->lastInsertId();

// 3. Journal Lines
$stmtL = $pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, ?, ?, ?, 'NONE', NULL, 1)");
$stmtL->execute([$je_id, 7, 63868.48, 0.00]);
$stmtL->execute([$je_id, 38, 0.00, 63868.48]);

$pdo->commit();
echo "Successfully created header and lines for JV-GOKA-00015 (ID 266457)!\n";
