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
    $txn_number = $_POST['txn_number'] ?? '';
    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('account_transfer');
    }
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $from_account_id = $_POST['from_account_id'] ?? null;
    $to_account_id = $_POST['to_account_id'] ?? null;
    $amount = (float)($_POST['amount'] ?? 0);
    $memo = $_POST['memo'] ?? '';
    $status = 'posted';

    if (!$from_account_id || !$to_account_id) throw new Exception("Both From and To accounts are required");
    if ($from_account_id === $to_account_id) throw new Exception("Source and Destination accounts cannot be the same");
    if ($amount <= 0) throw new Exception("Amount must be greater than zero");

    $fiscal = calculate_fiscal_info($txn_date);

    // Determine Transfer Type dynamically
    $from_acc = $db->fetchOne("SELECT account_name, account_subtype FROM accounts WHERE id = ?", [$from_account_id]);
    $to_acc = $db->fetchOne("SELECT account_name, account_subtype FROM accounts WHERE id = ?", [$to_account_id]);
    
    $from_sub = ($from_account_id === 'acc-1010' || strpos(strtolower($from_acc['account_name'] ?? ''), 'cash') !== false) ? 'cash' : ($from_acc['account_subtype'] ?? '');
    $to_sub = ($to_account_id === 'acc-1010' || strpos(strtolower($to_acc['account_name'] ?? ''), 'cash') !== false) ? 'cash' : ($to_acc['account_subtype'] ?? '');

    $transfer_type = 'inter_account';
    if ($from_sub === 'bank' && $to_sub === 'bank') $transfer_type = 'bank_to_bank';
    else if ($from_sub === 'cash' && $to_sub === 'bank') $transfer_type = 'cash_to_bank';
    else if ($from_sub === 'bank' && $to_sub === 'cash') $transfer_type = 'bank_to_cash';

    if (!$id) {
        $id = generate_uuid();
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, net_amount, party_type, party_id, created_by) 
                      VALUES (?, ?, 'account_transfer', ?, ?, ?, ?, ?, ?, ?, ?, 'account', ?, ?)", [
            $id, $txn_number, $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $status, $txn_number, $memo, $amount, $from_account_id, $_SESSION['user_id']
        ]);
        incrementTransactionNumber('account_transfer');
    } else {
        $db->execute("UPDATE transaction_headers SET txn_date = ?, memo = ?, net_amount = ?, party_id = ? WHERE id = ?", [
            $txn_date, $memo, $amount, $from_account_id, $id
        ]);
        
        $db->execute("DELETE FROM account_transfers WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    // Insert into existing account_transfers table
    $db->execute("INSERT INTO account_transfers (id, header_id, from_account_id, to_account_id, amount, transfer_type, memo, transfer_date) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $from_account_id, $to_account_id, $amount, $transfer_type, $memo, $txn_date
    ]);

    // Insert Journal Entries (Double-Entry Impact)
    // Dr Destination Bank Account (Increase Asset)
    $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $to_account_id, $amount, 'Transfer IN - ' . $txn_number . ' ' . $memo, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
    ]);

    // Cr Source Bank Account (Decrease Asset)
    $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $from_account_id, $amount, 'Transfer OUT - ' . $txn_number . ' ' . $memo, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
    ]);

    $pdo->commit();
    ob_end_clean();
    echo json_encode(['status' => 'success', 'message' => 'Fund transfer recorded successfully.', 'id' => $id]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
