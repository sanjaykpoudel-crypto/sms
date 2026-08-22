<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}

$db = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $id = $_POST['id'] ?? null;
    $txn_type = 'Expense';
    $payee_input = trim($_POST['party_id'] ?? ($_POST['payee'] ?? ''));
    $party_id = null;
    $party_type = null;

    if (!empty($payee_input) && is_numeric($payee_input)) {
        $party_id = (int)$payee_input;
        $party_type = 'vendor';
    }

    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');

    // Check closed fiscal year lock
    if ($id) {
        $old_header = $db->fetchOne("SELECT txn_date FROM transaction_headers WHERE id = ?", [$id]);
        if ($old_header) {
            check_fiscal_year_lock($old_header['txn_date']);
        }
    }
    check_fiscal_year_lock($txn_date);
    $memo = trim($_POST['memo'] ?? '');
    if (!empty($payee_input) && !is_numeric($payee_input)) {
        if (empty($memo)) {
            $memo = 'Payee: ' . $payee_input;
        } else if (stripos($memo, $payee_input) === false) {
            $memo = 'Payee: ' . $payee_input . ' - ' . $memo;
        }
    }
    $ref_number = $_POST['ref_number'] ?? '';
    $net_amount = (float)($_POST['net_amount'] ?? 0);
    
    $expense_account_id = $_POST['expense_account_id'] ?? null;
    $paid_from_account_id = $_POST['paid_from_account_id'] ?? null;
    $expense_category = $_POST['expense_category'] ?? 'other';
    $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : get_user_default_location_id();

    if (!$expense_account_id || !$paid_from_account_id || $net_amount <= 0) {
        throw new Exception("Account selection and positive amount are required.");
    }

    $fiscal = calculate_fiscal_info($txn_date);

    $existing_hdr = null;
    if (empty($id)) {
        $id = generate_uuid();
        $txn_number = getNextTransactionNumber('expense', $location_id);
        
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, party_id, party_type, net_amount, created_by, location_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $id, $txn_number, $txn_type, $txn_date, 
            $fiscal['year'], $fiscal['month'], $fiscal['period'], 
            'posted', $ref_number, $memo, $party_id, 'user', $net_amount, $_SESSION['user_id'], $location_id
        ]);
        incrementTransactionNumber('expense');
    } else {
        // Update
        $existing_hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
        $txn_number = $existing_hdr['txn_number'] ?? 'EXP-Unknown';
        $db->execute("UPDATE transaction_headers SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, reference_number = ?, memo = ?, party_id = ?, net_amount = ?, location_id = ?, updated_by = ? WHERE id = ?", [
            $txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $ref_number, $memo, $party_id, $net_amount, $location_id, $_SESSION['user_id'], $id
        ]);
        
        // Clean up old entries
        $db->execute("DELETE FROM expenses WHERE header_id = ?", [$id]);
        AccountingEngine::getInstance()->deleteJournalForTransaction($id);
    }

    // Insert into expenses table
    $db->execute("INSERT INTO expenses (id, header_id, expense_account_id, paid_from_account_id, description, amount, tax_amount, expense_category, expense_date) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $expense_account_id, $paid_from_account_id, $memo, $net_amount, 0.00, $expense_category, $txn_date
    ]);

    // GL Entries
    $gl_lines = [
        [
            'account_id'  => $expense_account_id,
            'debit'       => $net_amount,
            'credit'      => 0.00,
            'entity_type' => 'NONE',
            'location_id' => $location_id,
        ],
        [
            'account_id'  => $paid_from_account_id,
            'debit'       => 0.00,
            'credit'      => $net_amount,
            'entity_type' => 'NONE',
            'location_id' => $location_id,
        ],
    ];
    AccountingEngine::getInstance()->postJournalEntry($id, 'EXPENSE', $gl_lines, $txn_date, 'Expense ' . $txn_number . ': ' . $memo);

    log_audit('transaction_headers', !empty($existing_hdr) ? 'update' : 'create', $id, $existing_hdr ?? null, ['txn_number' => $txn_number, 'amount' => $net_amount, 'memo' => $memo, 'status' => 'posted']);

    $pdo->commit();
    clear_dashboard_cache();
    ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'code' => 200, 'message' => 'Expense has been recorded successfully.', 'id' => $id]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'code' => 400, 'message' => $e->getMessage()]);
    exit;
}
