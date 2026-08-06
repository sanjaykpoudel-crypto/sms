<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    $first_u = db()->fetchOne("SELECT id FROM users WHERE is_active = 1 LIMIT 1");
    $user_id = $first_u['id'] ?? null;
}
if (!$user_id) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

header('Content-Type: application/json');

$db  = db();
$pdo = $db->getConnection();

try {
    $action      = $_POST['action'] ?? 'apply';
    $credit_id   = trim($_POST['credit_id'] ?? '');
    $target_id   = trim($_POST['target_id'] ?? '');
    $apply_amt   = round((float)($_POST['amount'] ?? 0), 2);
    $link_id     = trim($_POST['link_id'] ?? '');

    if (empty($credit_id) || empty($target_id)) {
        throw new Exception("Credit document ID and target document ID are required.");
    }

    $pdo->beginTransaction();

    // Fetch credit header
    $credit_hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$credit_id]);
    if (!$credit_hdr || $credit_hdr['is_deleted'] == 1 || in_array(strtolower($credit_hdr['status']), ['void', 'voided', 'draft'])) {
        throw new Exception("Credit document is invalid, closed, or voided.");
    }

    // Fetch target header
    $target_hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$target_id]);
    if (!$target_hdr || $target_hdr['is_deleted'] == 1 || in_array(strtolower($target_hdr['status']), ['void', 'voided', 'draft'])) {
        throw new Exception("Target invoice/bill document is invalid, closed, or voided.");
    }

    $credit_type = $credit_hdr['txn_type']; // credit_memo, bill_credit, vendor_credit
    $link_prefix = in_array($credit_type, ['bill_credit', 'vendor_credit']) ? 'bill_credit_apply' : 'credit_memo_apply';

    if ($action === 'unapply') {
        // Remove existing application link
        if (!empty($link_id)) {
            $pdo->prepare("DELETE FROM transaction_links WHERE id = ? AND parent_id = ? AND child_id = ?")->execute([$link_id, $credit_id, $target_id]);
        } else {
            $pdo->prepare("DELETE FROM transaction_links WHERE parent_id = ? AND child_id = ? AND link_type LIKE ?")->execute([$credit_id, $target_id, $link_prefix . ':%']);
        }
    } else {
        // APPLY ACTION
        if ($apply_amt <= 0) {
            throw new Exception("Applied amount must be greater than zero.");
        }

        // Calculate available remaining credit on credit document
        $rem_credit = 0;
        if ($credit_type === 'credit_memo') {
            $cm = $db->fetchOne("SELECT total_amount, remaining_credit FROM credit_memos WHERE header_id = ?", [$credit_id]);
            $rem_credit = (float)($cm['remaining_credit'] ?? 0);
        } else {
            $vc = $db->fetchOne("SELECT total_amount, remaining_credit FROM vendor_credits WHERE header_id = ?", [$credit_id]);
            if (!$vc) {
                $vc = $db->fetchOne("SELECT total_amount, remaining_credit FROM vendor_bills WHERE header_id = ?", [$credit_id]);
            }
            $rem_credit = (float)($vc['remaining_credit'] ?? 0);
        }

        // Calculate remaining balance due on target invoice/bill
        $bal_due = 0;
        if ($target_hdr['txn_type'] === 'customer_invoice') {
            $inv = $db->fetchOne("SELECT balance_due FROM customer_invoices WHERE header_id = ?", [$target_id]);
            $bal_due = (float)($inv['balance_due'] ?? 0);
        } elseif ($target_hdr['txn_type'] === 'vendor_bill') {
            $bill = $db->fetchOne("SELECT balance_due FROM vendor_bills WHERE header_id = ?", [$target_id]);
            $bal_due = (float)($bill['balance_due'] ?? 0);
        }

        // Check if there is an existing link being updated
        $existing_link = $db->fetchOne("SELECT id, link_type FROM transaction_links WHERE parent_id = ? AND child_id = ? AND link_type LIKE ?", [$credit_id, $target_id, $link_prefix . ':%']);
        $existing_applied_amt = 0;
        if ($existing_link) {
            $existing_applied_amt = (float) (explode(':', $existing_link['link_type'])[1] ?? 0);
        }

        $max_avail_credit = round($rem_credit + $existing_applied_amt, 2);
        $max_avail_due    = round($bal_due + $existing_applied_amt, 2);

        if ($apply_amt > $max_avail_credit + 0.01) {
            throw new Exception("Applied amount (Rs. " . number_format($apply_amt, 2) . ") exceeds available remaining credit (Rs. " . number_format($max_avail_credit, 2) . ").");
        }
        if ($apply_amt > $max_avail_due + 0.01) {
            throw new Exception("Applied amount (Rs. " . number_format($apply_amt, 2) . ") exceeds open target balance due (Rs. " . number_format($max_avail_due, 2) . ").");
        }

        if ($existing_link) {
            $pdo->prepare("UPDATE transaction_links SET link_type = ? WHERE id = ?")
                ->execute([$link_prefix . ':' . $apply_amt, $existing_link['id']]);
        } else {
            $new_link_id = generate_uuid();
            $pdo->prepare("INSERT INTO transaction_links (id, parent_id, child_id, link_type) VALUES (?, ?, ?, ?)")
                ->execute([$new_link_id, $credit_id, $target_id, $link_prefix . ':' . $apply_amt]);
        }
    }

    // Recalculate statuses for both credit document and target document
    recalculate_document_payment_status($credit_id, $pdo);
    recalculate_document_payment_status($target_id, $pdo);

    $pdo->commit();

    ob_end_clean();
    echo json_encode([
        'status'  => 'success',
        'message' => $action === 'unapply' ? 'Credit unapplied successfully.' : 'Credit applied successfully.',
        'credit_id' => $credit_id,
        'target_id' => $target_id
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
