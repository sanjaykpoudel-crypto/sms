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
    $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : get_user_default_location_id();

    if (!$party_id) {
        throw new Exception("Party ID is required");
    }

    $header_txn_type = ($party_type === 'customer') ? 'customer_payment' : 'vendor_payment';
    
    // Check fiscal year locks
    if ($id) {
        $old_txn_date = $db->fetchOne("SELECT txn_date FROM transaction_headers WHERE id = ?", [$id])['txn_date'] ?? null;
        if ($old_txn_date) {
            check_fiscal_year_lock($old_txn_date);
        }
    }
    check_fiscal_year_lock($txn_date);

    $fiscal = calculate_fiscal_info($txn_date);

    $affected_doc_ids = [];
    $is_new_txn = empty($id);

    if (!$id) {
        $id = generate_uuid();
        $txn_number = getNextTransactionNumber($header_txn_type, $location_id);
        
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, created_by, party_id, party_type, location_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $id, $txn_number, $header_txn_type, $txn_date, 
            $fiscal['year'], $fiscal['month'], $fiscal['period'], 
            'posted', $reference_number, $memo, $_SESSION['user_id'],
            $party_id, $party_type, $location_id
        ]);
        incrementTransactionNumber($header_txn_type);
    } else {
        $txn_number = $_POST['txn_number'] ?? '';
        if (empty($txn_number)) {
            $txn_number = $db->fetchOne("SELECT txn_number FROM transaction_headers WHERE id = ?", [$id])['txn_number'] ?? 'Unknown';
        }
        $db->execute("UPDATE transaction_headers SET txn_date = ?, reference_number = ?, memo = ?, party_id = ?, party_type = ?, location_id = ? WHERE id = ?", [
            $txn_date, $reference_number, $memo, $party_id, $party_type, $location_id, $id
        ]);
        
        $old_links = $db->fetchAll("SELECT child_id as applied_to_id FROM transaction_links WHERE parent_id = ?", [$id]);
        foreach ($old_links as $link) {
            if (!empty($link['applied_to_id'])) $affected_doc_ids[] = $link['applied_to_id'];
        }

        $db->execute("DELETE FROM payments WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?", [$id, $id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    $bank_account_ids = $_POST['bank_account_id'] ?? [];
    $line_amounts = $_POST['line_amount'] ?? [];
    
    $total_tendered = 0;

    // Check if any applied document is a Credit Memo or Vendor Credit (Refund scenario)
    $is_refund_payment = false;
    $apply_txn_ids_chk = $_POST['apply_txn_id'] ?? [];
    foreach ($apply_txn_ids_chk as $raw_chk_key) {
        $parts_chk = explode(':', $raw_chk_key);
        $chk_hdr_id = $parts_chk[0];
        $target_txn_type = $db->fetchOne("SELECT txn_type FROM transaction_headers WHERE id = ?", [$chk_hdr_id])['txn_type'] ?? '';
        if (in_array(strtolower($target_txn_type), ['credit_memo', 'vendor_credit', 'bill_credit'])) {
            $is_refund_payment = true;
            break;
        }
    }

    foreach ($bank_account_ids as $index => $acc_id) {
        if (empty($acc_id)) continue;
        $line_amount = (float)($line_amounts[$index] ?? 0);
        $total_tendered += $line_amount;
        
        // Dynamically resolve payment method based on the account name
        $acc_info = $db->fetchOne("SELECT account_name FROM accounts WHERE id = ?", [$acc_id]);
        $mapped_method = resolve_payment_method($acc_info['account_name'] ?? '');
        
        $db->execute("INSERT INTO payments (id, header_id, payment_type, vendor_id, customer_id, payment_method, bank_account_id, amount, transaction_reference, payment_date) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $header_txn_type,
            ($party_type === 'vendor' ? $party_id : null),
            ($party_type === 'customer' ? $party_id : null),
            $mapped_method, $acc_id, $line_amount, $reference_number, $txn_date
        ]);

        if ($line_amount > 0) {
            // For customer payments: Debit Bank (Money IN); For customer refunds: Credit Bank (Money OUT)
            if ($party_type === 'customer') {
                $entry_type = $is_refund_payment ? 'credit' : 'debit';
            } else {
                $entry_type = $is_refund_payment ? 'debit' : 'credit';
            }
            $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year, party_id, party_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                generate_uuid(), $id, $acc_id, $entry_type, $line_amount, ($is_refund_payment ? 'Refund ' : 'Payment ') . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year'], $party_id, $party_type
            ]);
        }
    }

    // Cr/Dr AR/AP Account
    $party_acc_type = ($party_type === 'customer') ? 'receivable' : 'payable';
    $party_account = get_effective_account($party_id, $party_acc_type);
    if ($party_type === 'customer') {
        $party_entry_type = $is_refund_payment ? 'debit' : 'credit';
    } else {
        $party_entry_type = $is_refund_payment ? 'credit' : 'debit';
    }

    if ($total_tendered > 0) {
        $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $party_account, $party_entry_type, $total_tendered, ($is_refund_payment ? 'Refund ' : 'Payment ') . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
        ]);
    }

    // Handle Application
    $apply_txn_ids = $_POST['apply_txn_id'] ?? [];
    $apply_amounts = $_POST['apply_amount'] ?? [];

    foreach ($apply_txn_ids as $index => $raw_key) {
        $apply_amt = 0.0;
        if (isset($apply_amounts[$raw_key])) {
            $apply_amt = (float)$apply_amounts[$raw_key];
        } elseif (isset($apply_amounts[$index])) {
            $apply_amt = (float)$apply_amounts[$index];
        }
        if (abs($apply_amt) <= 0.0001) continue;

        $parts = explode(':', $raw_key);
        $header_id = $parts[0];
        $line_id = $parts[1] ?? '';
        $affected_doc_ids[] = $header_id;

        $link_type_str = !empty($line_id) ? "payment:{$line_id}:{$apply_amt}" : "payment:{$apply_amt}";

        // Record link (parent=payment, child=invoice/bill/journal header, link_type encodes line_id and amount)
        $db->execute("INSERT INTO transaction_links (parent_id, child_id, link_type) VALUES (?, ?, ?)", [
            $id, $header_id, $link_type_str
        ]);
    }

    // Recalculate balances and statuses for all affected document IDs
    $affected_doc_ids = array_unique($affected_doc_ids);
    foreach ($affected_doc_ids as $doc_id) {
        recalculate_document_payment_status($doc_id, $pdo);
    }

    // Update total net_amount in transaction_headers
    $db->execute("UPDATE transaction_headers SET net_amount = ? WHERE id = ?", [$total_tendered, $id]);

    // Record audit log
    $user_id = $_SESSION['user_id'] ?? 'usr-admin-001';
    $audit_action = $is_new_txn ? 'create' : 'update';
    $new_log_val = json_encode(['amount' => $total_tendered, 'party_id' => $party_id, 'memo' => $memo, 'status' => 'posted']);
    $pdo->prepare("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES ('transaction_headers', ?, ?, NULL, ?, ?)")
        ->execute([$audit_action, $id, $new_log_val, $user_id]);

    $pdo->commit();
    clear_dashboard_cache();
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['status' => 'success', 'code' => 200, 'message' => 'Payment has been recorded successfully.', 'id' => $id]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 400, 'message' => $e->getMessage()]);
    exit;
}



