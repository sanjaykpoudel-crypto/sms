<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$db = db();
$pdo = $db->getConnection();

$balances = $_POST['balances'] ?? [];
$opening_date = $_POST['opening_balance_date'] ?? null;

if (empty($opening_date)) {
    echo json_encode(['status' => 'error', 'message' => 'Opening balance date is required.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $opening_date) || !strtotime($opening_date)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid opening balance date format.']);
    exit;
}

try {
    check_fiscal_year_lock($opening_date);

    $pdo->beginTransaction();

    foreach ($balances as $account_id => $amount) {
        $amount = (float)$amount;
        
        // Verify account exists and is subtype bank or cash
        $acc = $db->fetchOne("SELECT id FROM accounts WHERE id = ? AND account_subtype IN ('bank', 'cash') AND is_deleted = 0", [$account_id]);
        if (!$acc) {
            continue;
        }

        $db->execute("UPDATE accounts SET opening_balance = ? WHERE id = ?", [$amount, $account_id]);
    }

    // Synchronize the opening balances to a balanced journal entry
    sync_opening_balance_journal_entries($pdo, $opening_date);

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Opening balances updated and journal entry synchronized successfully.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
