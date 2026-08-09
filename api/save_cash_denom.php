<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$db = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $id = $data['id'] ?? null;
    $txn_date = $data['txn_date'] ?? date('Y-m-d');
    $shift = $data['party_id'] ?? $data['reference_number'] ?? 'Main';
    $party_id = (isset($data['party_id']) && is_numeric($data['party_id'])) ? (int)$data['party_id'] : null;
    $ref_no = $shift;
    $fiscal = calculate_fiscal_info($txn_date);

    $note_1000 = (int)($data['note_1000'] ?? 0);
    $note_500  = (int)($data['note_500']  ?? 0);
    $note_100  = (int)($data['note_100']  ?? 0);
    $note_50   = (int)($data['note_50']   ?? 0);
    $note_20   = (int)($data['note_20']   ?? 0);
    $note_10   = (int)($data['note_10']   ?? 0);
    $coin_5    = (int)($data['coin_5']    ?? 0);
    $coin_2    = (int)($data['coin_2']    ?? 0);
    $coin_1    = (int)($data['coin_1']    ?? 0);

    // Compute exact total cash from note and coin counts
    $net_amount = (float)(
        ($note_1000 * 1000) +
        ($note_500  * 500)  +
        ($note_100  * 100)  +
        ($note_50   * 50)   +
        ($note_20   * 20)   +
        ($note_10   * 10)   +
        ($coin_5    * 5)    +
        ($coin_2    * 2)    +
        ($coin_1    * 1)
    );

    $location_id = !empty($data['location_id']) ? $data['location_id'] : get_user_default_location_id();

    if (!$id) {
        $id = generate_uuid();
        $txn_number = 'CD-' . date('Ymd', strtotime($txn_date)) . '-' . rand(1000, 9999);
        
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, reference_number, net_amount, location_id) 
                      VALUES (?, ?, 'cash_denomination', ?, ?, ?, ?, 'posted', ?, ?, ?, ?, ?)", [
            $id, $txn_number, $txn_date, 
            $fiscal['year'], $fiscal['month'], $fiscal['period'], 
            $_SESSION['user_id'], $party_id, $ref_no, $net_amount, $location_id
        ]);
    } else {
        $db->execute("UPDATE transaction_headers SET txn_date = ?, party_id = ?, reference_number = ?, net_amount = ?, location_id = ?, txn_type = 'cash_denomination' WHERE id = ?", [
            $txn_date, $party_id, $ref_no, $net_amount, $location_id, $id
        ]);
        $db->execute("DELETE FROM cash_denominations WHERE header_id = ?", [$id]);
    }

    $denom_type = ($shift === 'Shift_A') ? 'opening' : 'closing';

    // Compute system cash balance from the default cash account's journal balance
    $cash_account = AccountingEngine::getInstance()->resolveAccount('default_cash_account');
    $cash_bal_row = $db->fetchOne("
        SELECT COALESCE(SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END), 0) AS bal
        FROM journal_entries je
        JOIN transaction_headers th ON je.header_id = th.id
        WHERE je.account_id = ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ", [$cash_account]);
    $system_cash_balance = round((float)($cash_bal_row['bal'] ?? 0), 2);
    $difference = round($net_amount - $system_cash_balance, 2);

    $db->execute("INSERT INTO cash_denominations (
        id, header_id, denomination_date, denomination_type, 
        note_1000, note_500, note_100, note_50, note_20, note_10, 
        coin_5, coin_2, coin_1, total_cash, system_cash_balance, difference, counted_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $txn_date, $denom_type,
        $note_1000, $note_500, $note_100, $note_50, $note_20, $note_10,
        $coin_5, $coin_2, $coin_1,
        $net_amount, $system_cash_balance, $difference,
        $_SESSION['user_id']
    ]);

    // Update net_amount in header to ensure consistency
    $db->execute("UPDATE transaction_headers SET net_amount = ? WHERE id = ?", [$net_amount, $id]);

    $pdo->commit();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Cash denomination saved successfully.', 'id' => $id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
