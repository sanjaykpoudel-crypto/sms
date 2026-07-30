<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');
require_once __DIR__ . '/../database/DBConnection.php';

$item_id   = $_GET['item_id']   ?? null;
$loc_id    = $_GET['location_id'] ?? null;

if (!$item_id || !$loc_id) {
    echo json_encode(['error' => 'item_id and location_id required']);
    exit;
}

$db = db();

// Calculate stock for this item at this specific location from transaction_lines (including inventory transfers)
$hdr_stock = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE 
        WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') AND h.location_id = ? THEN l.quantity 
        WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') AND h.location_id = ? THEN -l.quantity 
        WHEN h.txn_type = 'inventory_transfer' AND h.party_id = ? THEN l.quantity 
        WHEN h.txn_type = 'inventory_transfer' AND h.location_id = ? THEN -l.quantity 
        ELSE 0 END), 0) as qty
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE l.item_id = ? 
      AND (h.location_id = ? OR (h.txn_type = 'inventory_transfer' AND h.party_id = ?))
      AND h.is_deleted = 0 
      AND h.status NOT IN ('void', 'voided', 'draft')
", [$loc_id, $loc_id, $loc_id, $loc_id, $item_id, $loc_id, $loc_id])['qty'] ?? 0);

// POS entries not yet in an INV-POS invoice
$pos_stock = (float)($db->fetchOne("
    SELECT COALESCE(SUM(-pi.quantity), 0) as qty
    FROM pos_items pi
    JOIN pos_entry pe ON pi.pos_id = pe.id
    LEFT JOIN users u ON pe.created_by = u.id
    WHERE pi.item_id = ? AND (pe.location_id = ? OR u.location_id = ?) AND pe.is_deleted = 0
    AND NOT EXISTS (
        SELECT 1 FROM transaction_headers th
        JOIN transaction_lines tl ON tl.header_id = th.id
        WHERE th.txn_type = 'customer_invoice'
          AND th.txn_number LIKE 'INV-POS%'
          AND th.location_id = ?
          AND DATE(th.txn_date) = DATE(pe.date_time)
          AND tl.item_id = pi.item_id
          AND th.is_deleted = 0
    )
", [$item_id, $loc_id, $loc_id, $loc_id])['qty'] ?? 0);

$stock = max(0, $hdr_stock + $pos_stock);

echo json_encode([
    'stock' => $stock,
    'formatted' => number_format($stock, 2)
]);
