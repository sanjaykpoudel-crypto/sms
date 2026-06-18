<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}

$db = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $id = $_POST['id'] ?? null;
    $txn_type = 'Expense';
    $party_id = $_POST['party_id'] ?? null; // For expenses, this is often just a string name
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $memo = $_POST['memo'] ?? '';
    $ref_number = $_POST['ref_number'] ?? '';
    $net_amount = (float)($_POST['net_amount'] ?? 0);
    
    $expense_account_id = $_POST['expense_account_id'] ?? null;
    $paid_from_account_id = $_POST['paid_from_account_id'] ?? null;
    $expense_category = $_POST['expense_category'] ?? 'other';

    if (!$expense_account_id || !$paid_from_account_id || $net_amount <= 0) {
        throw new Exception("Account selection and positive amount are required.");
    }

    $fiscal = calculate_fiscal_info($txn_date);

    if (empty($id)) {
        $id = generate_uuid();
        $txn_number = getNextTransactionNumber('expense');
        
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, party_id, party_type, net_amount, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $id, $txn_number, $txn_type, $txn_date, 
            $fiscal['year'], $fiscal['month'], $fiscal['period'], 
            'posted', $ref_number, $memo, $party_id, 'user', $net_amount, $_SESSION['user_id']
        ]);
        incrementTransactionNumber('expense');
    } else {
        // Update
        $txn_number = $db->fetchOne("SELECT txn_number FROM transaction_headers WHERE id = ?", [$id])['txn_number'] ?? 'EXP-Unknown';
        $db->execute("UPDATE transaction_headers SET txn_date = ?, reference_number = ?, memo = ?, party_id = ?, net_amount = ? WHERE id = ?", [
            $txn_date, $ref_number, $memo, $party_id, $net_amount, $id
        ]);
        
        // Clean up old entries
        $db->execute("DELETE FROM expenses WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    // Insert into expenses table
    $db->execute("INSERT INTO expenses (id, header_id, expense_account_id, paid_from_account_id, description, amount, expense_category, expense_date) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $expense_account_id, $paid_from_account_id, $memo, $net_amount, $expense_category, $txn_date
    ]);

    // GL Entries
    // 1. Debit Expense Account
    $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year, party_id, party_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $expense_account_id, 'debit', $net_amount, 'Expense ' . $txn_number . ': ' . $memo, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year'], $party_id, 'user'
    ]);

    // 2. Credit Bank/Cash Account
    $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year, party_id, party_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $paid_from_account_id, 'credit', $net_amount, 'Expense ' . $txn_number . ': ' . $memo, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year'], $party_id, 'user'
    ]);

    $pdo->commit();
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Expense has been recorded successfully.', 'id' => $id]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
