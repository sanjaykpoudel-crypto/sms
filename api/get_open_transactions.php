<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once '../database/DBConnection.php';

$party_id = $_GET['party_id'] ?? null;
$party_type = $_GET['party_type'] ?? 'customer';
$payment_id = $_GET['payment_id'] ?? '';

if (!$party_id) {
    echo json_encode([]);
    exit;
}

$db = db();

if ($party_type === 'customer') {
    $where = $payment_id ? "(tl.id IS NOT NULL OR ci.balance_due > 0.01)" : "ci.balance_due > 0.01";
    $sql = "SELECT h.txn_number, h.txn_date, ci.total_amount, 
            (ci.balance_due + COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0)) as balance_due, 
            ci.header_id as id,
            COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0) as applied_amount
            FROM customer_invoices ci 
            JOIN transaction_headers h ON ci.header_id = h.id 
            LEFT JOIN transaction_links tl ON tl.child_id = ci.header_id AND tl.parent_id = ?
            WHERE ci.customer_id = ? AND ($where) AND h.is_deleted = 0
            ORDER BY h.txn_date ASC";
} else {
    $where = $payment_id ? "(tl.id IS NOT NULL OR vb.balance_due > 0.01)" : "vb.balance_due > 0.01";
    $sql = "SELECT h.txn_number, h.txn_date, vb.total_amount, 
            (vb.balance_due + COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0)) as balance_due, 
            vb.header_id as id,
            COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0) as applied_amount
            FROM vendor_bills vb 
            JOIN transaction_headers h ON vb.header_id = h.id 
            LEFT JOIN transaction_links tl ON tl.child_id = vb.header_id AND tl.parent_id = ?
            WHERE vb.vendor_id = ? AND ($where) AND h.is_deleted = 0
            ORDER BY h.txn_date ASC";
}

$results = $db->fetchAll($sql, [$payment_id, $party_id]);
echo json_encode($results);



