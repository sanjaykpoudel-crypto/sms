<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
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
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $memo = $_POST['memo'] ?? '';
    $ref_number = $_POST['ref_number'] ?? '';

    // Line data
    $account_ids     = $_POST['account_id']       ?? [];
    $debits          = $_POST['debit']             ?? [];
    $credits         = $_POST['credit']            ?? [];
    $line_party_types = $_POST['line_party_type']  ?? [];
    $line_party_ids  = $_POST['line_party_id']     ?? [];
    $line_memos      = $_POST['line_memo']         ?? [];

    $total_debit = 0;
    foreach ($debits as $d) $total_debit += (float)$d;

    $fiscal = calculate_fiscal_info($txn_date);

    if (!$id) {
        $id = generate_uuid();
        $db->execute(
            "INSERT INTO transaction_headers
                (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, net_amount, created_by)
             VALUES (?, ?, 'Journal', ?, ?, ?, ?, 'posted', ?, ?, ?, ?)",
            [$id, $txn_number, $txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $ref_number, $memo, $total_debit, $_SESSION['user_id']]
        );
        incrementTransactionNumber('journal_entry');
    } else {
        $db->execute(
            "UPDATE transaction_headers SET txn_date = ?, reference_number = ?, memo = ?, net_amount = ? WHERE id = ?",
            [$txn_date, $ref_number, $memo, $total_debit, $id]
        );
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    foreach ($account_ids as $idx => $acc_id) {
        if (empty($acc_id)) continue;

        $debit  = (float)($debits[$idx]  ?? 0);
        $credit = (float)($credits[$idx] ?? 0);
        $amount = $debit > 0 ? $debit : $credit;
        $type   = $debit > 0 ? 'debit' : 'credit';

        if ($amount == 0) continue;

        $db->execute(
            "INSERT INTO journal_entries
                (id, header_id, account_id, entry_type, amount, memo, party_type, party_id, entry_date, fiscal_period, fiscal_year, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                generate_uuid(), $id, $acc_id, $type, $amount,
                $line_memos[$idx]      ?? $memo,
                $line_party_types[$idx] ?? null,
                $line_party_ids[$idx]  ?? null,
                $txn_date, $fiscal['period'], $fiscal['year'],
                $_SESSION['user_id']
            ]
        );
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Journal Entry saved successfully.', 'id' => $id]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
