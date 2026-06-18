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
    $party_id = $data['party_id'] ?? 'Main';
    $net_amount = (float)($data['net_amount'] ?? 0);
    $fiscal = calculate_fiscal_info($txn_date);

    if (!$id) {
        $id = generate_uuid();
        $txn_number = 'CD-' . date('Ymd', strtotime($txn_date)) . '-' . rand(1000, 9999);
        
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id) 
                      VALUES (?, ?, 'cash_denomination', ?, ?, ?, ?, 'posted', ?, ?)", [
            $id, $txn_number, $txn_date, 
            $fiscal['year'], $fiscal['month'], $fiscal['period'], 
            $_SESSION['user_id'], $party_id
        ]);
    } else {
        $db->execute("UPDATE transaction_headers SET txn_date = ?, party_id = ?, net_amount = ?, txn_type = 'cash_denomination' WHERE id = ?", [
            $txn_date, $party_id, $net_amount, $id
        ]);
        $db->execute("DELETE FROM cash_denominations WHERE header_id = ?", [$id]);
    }

    $denom_type = ($party_id === 'Shift_A') ? 'opening' : 'closing';

    $db->execute("INSERT INTO cash_denominations (
        id, header_id, denomination_date, denomination_type, 
        note_1000, note_500, note_100, note_50, note_20, note_10, 
        coin_5, coin_2, coin_1, total_cash, counted_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $txn_date, $denom_type,
        (int)($data['note_1000'] ?? 0),
        (int)($data['note_500']  ?? 0),
        (int)($data['note_100']  ?? 0),
        (int)($data['note_50']   ?? 0),
        (int)($data['note_20']   ?? 0),
        (int)($data['note_10']   ?? 0),
        (int)($data['coin_5']    ?? 0),
        (int)($data['coin_2']    ?? 0),
        (int)($data['coin_1']    ?? 0),
        $net_amount,
        $_SESSION['user_id']
    ]);

    // Update net_amount in header if not already done
    $db->execute("UPDATE transaction_headers SET net_amount = ? WHERE id = ?", [$net_amount, $id]);

    $pdo->commit();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Cash denomination saved successfully.', 'id' => $id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
