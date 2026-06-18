<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
header('Content-Type: application/json');
require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['error' => 'No item ID provided']);
    exit;
}

$db = db();
$item = $db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);

if (!$item) {
    echo json_encode(['error' => 'Item not found']);
    exit;
}

// Calculate stock
// Sum(Quantity where type is incoming) - Sum(Quantity where type is outgoing)
// We'll use a broad check for types
$stock_query = "
    SELECT 
        SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
            ELSE 0 
        END) as current_stock
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE l.item_id = ?
";
$stock_data = $db->fetchOne($stock_query, [$id]);
$item['current_stock'] = $stock_data['current_stock'] ?? 0;

// Resolve unit name from reference_codes
$unit_rec = $db->fetchOne("SELECT name FROM reference_codes WHERE id = ? AND type = 'units'", [$item['unit_type'] ?? '']);
$item['unit_name'] = $unit_rec ? $unit_rec['name'] : ($item['unit_type'] ?? '');

// Resolve tax rate from tax_id
if (!empty($item['tax_id'])) {
    $tax_rec = $db->fetchOne("SELECT value FROM reference_codes WHERE id = ? AND type = 'tax_code'", [$item['tax_id']]);
    if ($tax_rec) $item['tax_rate'] = $tax_rec['value'];
}

// Resolve effective accounts
$item['resolved_income_account_id'] = get_effective_account($id, 'income');
$item['resolved_cogs_account_id'] = get_effective_account($id, 'cogs');
$item['resolved_inventory_account_id'] = get_effective_account($id, 'inventory');

echo json_encode($item);



