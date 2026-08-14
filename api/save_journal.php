<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
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
    $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : get_user_default_location_id();
    $id = $_POST['id'] ?? null;
    $txn_number = $_POST['txn_number'] ?? '';
    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('journal_entry', $location_id);
    }
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $memo = $_POST['memo'] ?? '';
    $ref_number = $_POST['ref_number'] ?? '';

    // Check closed fiscal year lock
    if ($id) {
        $old_header = $db->fetchOne("SELECT txn_date FROM transaction_headers WHERE id = ?", [$id]);
        if ($old_header) {
            check_fiscal_year_lock($old_header['txn_date']);
        }
    }
    check_fiscal_year_lock($txn_date);

    // Line data
    $account_ids = $_POST['account_id'] ?? [];
    $debits = $_POST['debit'] ?? [];
    $credits = $_POST['credit'] ?? [];
    $line_party_types = $_POST['line_party_type'] ?? [];
    $line_party_ids = $_POST['line_party_id'] ?? [];
    $line_memos = $_POST['line_memo'] ?? [];

    $total_debit = 0;
    foreach ($debits as $d)
        $total_debit += (float) $d;

    $total_credit = 0;
    foreach ($credits as $c)
        $total_credit += (float) $c;

    if (abs($total_debit - $total_credit) > 0.01 || $total_debit <= 0) {
        throw new Exception("Journal entry out of balance. Total Debit (Rs " . number_format($total_debit, 2) . ") must equal Total Credit (Rs " . number_format($total_credit, 2) . ").");
    }

    $fiscal = calculate_fiscal_info($txn_date);

    $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : get_user_default_location_id();

    $existing_hdr = null;
    if (!$id) {
        $id = generate_uuid();
        $txn_number = getNextTransactionNumber('journal_entry', $location_id);

        $db->execute(
            "INSERT INTO transaction_headers
                (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, net_amount, created_by, location_id)
             VALUES (?, ?, 'Journal', ?, ?, ?, ?, 'posted', ?, ?, ?, ?, ?)",
            [$id, $txn_number, $txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $ref_number, $memo, $total_debit, $_SESSION['user_id'], $location_id]
        );
        incrementTransactionNumber('journal_entry');
    } else {
        $existing_hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
        $db->execute(
            "UPDATE transaction_headers SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, reference_number = ?, memo = ?, net_amount = ?, location_id = ?, updated_by = ? WHERE id = ?",
            [$txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $ref_number, $memo, $total_debit, $location_id, $_SESSION['user_id'], $id]
        );
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    foreach ($account_ids as $idx => $acc_id) {
        if (empty($acc_id))
            continue;

        $debit = (float) ($debits[$idx] ?? 0);
        $credit = (float) ($credits[$idx] ?? 0);
        $amount = $debit > 0 ? $debit : $credit;
        $type = $debit > 0 ? 'debit' : 'credit';

        if ($amount == 0)
            continue;

        $raw_ptype = !empty($line_party_types[$idx]) ? trim($line_party_types[$idx]) : null;
        $ptype = in_array($raw_ptype, ['customer', 'vendor', 'user'], true) ? $raw_ptype : null;
        $pid = !empty($line_party_ids[$idx]) ? trim($line_party_ids[$idx]) : null;

        $db->execute(
            "INSERT INTO journal_entries
                (id, header_id, account_id, entry_type, amount, memo, party_type, party_id, entry_date, fiscal_period, fiscal_year, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                generate_uuid(),
                $id,
                $acc_id,
                $type,
                $amount,
                $line_memos[$idx] ?? $memo,
                $ptype,
                $pid,
                $txn_date,
                $fiscal['period'],
                $fiscal['year'],
                $_SESSION['user_id']
            ]
        );
    }

    // Sync to Bank Opening Balances if this is the OPENING-BALANCES journal entry
    if ($txn_number === 'OPENING-BALANCES') {
        // Reset all bank and cash opening balances to 0
        $db->execute("UPDATE accounts SET opening_balance = 0.00 WHERE account_subtype IN ('Bank')");

        // Fetch the saved journal entries for this transaction
        $saved_entries = $db->fetchAll("SELECT account_id, entry_type, amount FROM journal_entries WHERE header_id = ?", [$id]);

        // Group by account_id and calculate the net balance
        $balances = [];
        foreach ($saved_entries as $entry) {
            $acc_id = $entry['account_id'];
            $entry_type = $entry['entry_type'];
            $amount = (float) $entry['amount'];

            // Check if this account is cash/bank
            $acc = $db->fetchOne("SELECT account_subtype FROM accounts WHERE id = ?", [$acc_id]);
            if ($acc && in_array($acc['account_subtype'], ['Bank'])) {
                if (!isset($balances[$acc_id])) {
                    $balances[$acc_id] = 0.00;
                }
                if ($entry_type === 'debit') {
                    $balances[$acc_id] += $amount;
                } else {
                    $balances[$acc_id] -= $amount;
                }
            }
        }

        // Update the accounts table with the new opening balances
        foreach ($balances as $acc_id => $net_bal) {
            $db->execute("UPDATE accounts SET opening_balance = ? WHERE id = ?", [$net_bal, $acc_id]);
        }
    }

    log_audit('transaction_headers', !empty($existing_hdr) ? 'update' : 'create', $id, $existing_hdr ?? null, ['txn_number' => $txn_number, 'amount' => $total_debit, 'memo' => $memo, 'status' => 'posted']);

    $pdo->commit();
    clear_dashboard_cache();
    http_response_code(200);
    echo json_encode(['status' => 'success', 'code' => 200, 'message' => 'Journal Entry saved successfully.', 'id' => $id]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 400, 'message' => $e->getMessage()]);
    exit;
}
