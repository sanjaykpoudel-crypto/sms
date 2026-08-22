<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

$id = 174;
$pdo->beginTransaction();

// 1. Delete existing JE and lines for 174
$old_je_ids = $db->fetchAll("SELECT je_id FROM journal_entries WHERE transaction_id = ?", [$id]);
foreach ($old_je_ids as $oje) {
    $db->execute("DELETE FROM journal_lines WHERE je_id = ?", [$oje['je_id']]);
    $db->execute("DELETE FROM journal_entries WHERE je_id = ?", [$oje['je_id']]);
}

// 2. Insert new JE header
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-01 00:00:00', 'JOURNAL', 'Opening receivable and payable', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id = $pdo->lastInsertId();

// 3. Insert specific lines with preserved IDs for payment linking
// Delete any conflicts on those jl_ids first if they exist in other non-used records
$pdo->exec("DELETE FROM journal_lines WHERE jl_id IN (469, 587, 117, 882)");

$stmtL = $pdo->prepare("INSERT INTO journal_lines (jl_id, je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");

// Line 469: Krishna Lomus Opening Receivable (Rs. 2,900.00)
$stmtL->execute([469, $je_id, 6, 2900.00, 0.00, 'CUSTOMER', 21]);

// Line 587: Prabesh Khanal Opening Receivable (Rs. 5,000.00)
$stmtL->execute([587, $je_id, 6, 5000.00, 0.00, 'CUSTOMER', 20]);

// Line 117: Friendship Suppliers Opening Payable (Rs. 22,825.00)
$stmtL->execute([117, $je_id, 12, 0.00, 22825.00, 'VENDOR', 5]);

// Line 882: Friendship Suppliers Debit Note / Adj (Rs. 7,300.00)
$stmtL->execute([882, $je_id, 12, 7300.00, 0.00, 'VENDOR', 5]);

// Other general opening receivables (Rs. 421,115.00)
$stmtL->execute([null, $je_id, 6, 421115.00, 0.00, 'NONE', null]);

// Opening Balance Equity balancing line (Rs. 413,490.00)
$stmtL->execute([null, $je_id, 38, 0.00, 413490.00, 'NONE', null]);

// Update header net_amount
$pdo->prepare("UPDATE transaction_headers SET net_amount = 429015.00 WHERE id = ?")->execute([$id]);

$pdo->commit();

echo "Successfully reconstructed JV-00002 with 6 detailed lines!\n";

$jls = $db->fetchAll("
    SELECT jl.*, a.account_name, 
           CASE jl.entity_type 
               WHEN 'CUSTOMER' THEN (SELECT full_name FROM customers WHERE id = jl.entity_id)
               WHEN 'VENDOR' THEN (SELECT company_name FROM vendors WHERE id = jl.entity_id)
               ELSE ''
           END as entity_name
    FROM journal_lines jl
    JOIN accounts a ON jl.account_id = a.id
    WHERE jl.je_id = ?
", [$je_id]);

print_r($jls);
