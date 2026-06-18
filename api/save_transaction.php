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
    $txn_type = $_POST['txn_type'] ?? 'Payment';
    $party_type = $_POST['party_type'] ?? 'customer';
    $party_id = $_POST['party_id'] ?? null;
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $memo = $_POST['memo'] ?? '';
    $reference_number = $_POST['reference_number'] ?? '';
    $net_amount = (float)($_POST['net_amount'] ?? 0);

    if (!$party_id) {
        throw new Exception("Party ID is required");
    }

    $header_txn_type = ($party_type === 'customer') ? 'customer_payment' : 'vendor_payment';
    $fiscal = calculate_fiscal_info($txn_date);

    if (!$id) {
        $id = generate_uuid();
        $txn_number = getNextTransactionNumber($header_txn_type);
        
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $id, $txn_number, $header_txn_type, $txn_date, 
            $fiscal['year'], $fiscal['month'], $fiscal['period'], 
            'posted', $reference_number, $memo, $_SESSION['user_id']
        ]);
        incrementTransactionNumber($header_txn_type);
    } else {
        $txn_number = $_POST['txn_number'] ?? '';
        if (empty($txn_number)) {
            $txn_number = $db->fetchOne("SELECT txn_number FROM transaction_headers WHERE id = ?", [$id])['txn_number'] ?? 'Unknown';
        }
        $db->execute("UPDATE transaction_headers SET txn_date = ?, reference_number = ?, memo = ? WHERE id = ?", [
            $txn_date, $reference_number, $memo, $id
        ]);
        
        // Reverse balance updates before deleting lines
        $old_links = $db->fetchAll("SELECT child_id as applied_to_id, link_type FROM transaction_links WHERE parent_id = ?", [$id]);
        foreach ($old_links as $link) {
            // Parse amount from link_type field (stored as "amount:XX.XX")
            $link_amount = (float)(explode(':', $link['link_type'])[1] ?? 0);
            if ($link_amount <= 0) continue;
            if ($party_type === 'customer') {
                $db->execute("UPDATE customer_invoices SET amount_paid = amount_paid - ?, balance_due = balance_due + ?, payment_status = CASE WHEN balance_due + ? >= total_amount THEN 'unpaid' ELSE 'partial' END WHERE header_id = ?", [$link_amount, $link_amount, $link_amount, $link['applied_to_id']]);
                $db->execute("UPDATE transaction_headers SET status = CASE WHEN (SELECT balance_due FROM customer_invoices WHERE header_id = ?) >= (SELECT total_amount FROM customer_invoices WHERE header_id = ?) THEN 'open' ELSE 'partial' END WHERE id = ?", [$link['applied_to_id'], $link['applied_to_id'], $link['applied_to_id']]);
            } else {
                $db->execute("UPDATE vendor_bills SET amount_paid = amount_paid - ?, balance_due = balance_due + ?, payment_status = CASE WHEN balance_due + ? >= total_amount THEN 'unpaid' ELSE 'partial' END WHERE header_id = ?", [$link_amount, $link_amount, $link_amount, $link['applied_to_id']]);
                $db->execute("UPDATE transaction_headers SET status = CASE WHEN (SELECT balance_due FROM vendor_bills WHERE header_id = ?) >= (SELECT total_amount FROM vendor_bills WHERE header_id = ?) THEN 'open' ELSE 'partial' END WHERE id = ?", [$link['applied_to_id'], $link['applied_to_id'], $link['applied_to_id']]);
            }
        }

        $db->execute("DELETE FROM payments WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?", [$id, $id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    $bank_account_ids = $_POST['bank_account_id'] ?? [];
    $line_amounts = $_POST['line_amount'] ?? [];
    
    $total_tendered = 0;

    foreach ($bank_account_ids as $index => $acc_id) {
        if (empty($acc_id)) continue;
        $line_amount = (float)($line_amounts[$index] ?? 0);
        if ($line_amount <= 0) continue;

        $total_tendered += $line_amount;
        
        // Dynamically resolve payment method based on the account name
        $acc_info = $db->fetchOne("SELECT account_name FROM accounts WHERE id = ?", [$acc_id]);
        $account_name = strtolower($acc_info['account_name'] ?? '');
        
        $mapped_method = 'bank_transfer';
        if (strpos($account_name, 'cash') !== false) {
            $mapped_method = 'cash';
        } elseif (strpos($account_name, 'esewa') !== false) {
            $mapped_method = 'esewa';
        } elseif (strpos($account_name, 'khalti') !== false) {
            $mapped_method = 'khalti';
        }
        
        $db->execute("INSERT INTO payments (id, header_id, payment_type, vendor_id, customer_id, payment_method, bank_account_id, amount, transaction_reference, payment_date) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $header_txn_type,
            ($party_type === 'vendor' ? $party_id : null),
            ($party_type === 'customer' ? $party_id : null),
            $mapped_method, $acc_id, $line_amount, $reference_number, $txn_date
        ]);

        // Dr Bank/Cash account
        $entry_type = ($party_type === 'customer') ? 'debit' : 'credit';
        $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $acc_id, $entry_type, $line_amount, 'Payment ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
        ]);
    }

    // Cr/Dr AR/AP Account
    $party_acc_type = ($party_type === 'customer') ? 'receivable' : 'payable';
    $party_account = get_effective_account($party_id, $party_acc_type);
    $party_entry_type = ($party_type === 'customer') ? 'credit' : 'debit';

    if ($total_tendered > 0) {
        $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $party_account, $party_entry_type, $total_tendered, 'Payment ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
        ]);
    }

    // Handle Application
    $apply_txn_ids = $_POST['apply_txn_id'] ?? [];
    $apply_amounts = $_POST['apply_amount'] ?? [];

    foreach ($apply_txn_ids as $index => $applied_to_id) {
        $apply_amt = 0.0;
        if (isset($apply_amounts[$applied_to_id])) {
            $apply_amt = (float)$apply_amounts[$applied_to_id];
        } elseif (isset($apply_amounts[$index])) {
            $apply_amt = (float)$apply_amounts[$index];
        }
        if ($apply_amt <= 0) continue;

        if ($party_type === 'customer') {
            $db->execute("UPDATE customer_invoices SET amount_paid = amount_paid + ?, balance_due = balance_due - ?, 
                          payment_status = CASE WHEN balance_due - ? <= 0 THEN 'paid' ELSE 'partial' END 
                          WHERE header_id = ?", [$apply_amt, $apply_amt, $apply_amt, $applied_to_id]);
            $db->execute("UPDATE transaction_headers SET status = CASE WHEN (SELECT balance_due FROM customer_invoices WHERE header_id = ?) <= 0 THEN 'paid' ELSE 'partial' END WHERE id = ?", [$applied_to_id, $applied_to_id]);
        } else {
            $db->execute("UPDATE vendor_bills SET amount_paid = amount_paid + ?, balance_due = balance_due - ?, 
                          payment_status = CASE WHEN balance_due - ? <= 0 THEN 'paid' ELSE 'partial' END 
                          WHERE header_id = ?", [$apply_amt, $apply_amt, $apply_amt, $applied_to_id]);
            $db->execute("UPDATE transaction_headers SET status = CASE WHEN (SELECT balance_due FROM vendor_bills WHERE header_id = ?) <= 0 THEN 'paid' ELSE 'partial' END WHERE id = ?", [$applied_to_id, $applied_to_id]);
        }

        // Record link (parent=payment, child=invoice/bill, link_type encodes the amount)
        $db->execute("INSERT INTO transaction_links (id, parent_id, child_id, link_type) VALUES (?, ?, ?, ?)", [
            generate_uuid(), $id, $applied_to_id, 'payment:' . $apply_amt
        ]);
    }

    $pdo->commit();
    ob_end_clean();
    echo json_encode(['status' => 'success', 'message' => 'Payment has been recorded successfully.', 'id' => $id]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}



