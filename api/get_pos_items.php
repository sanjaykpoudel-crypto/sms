<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
header('Content-Type: application/json');
require_once __DIR__ . '/../database/DBConnection.php';

$db = db();

$query = "
    SELECT 
        i.id, i.sku, i.item_name, r.name as category_name, 
        CAST(i.selling_price AS DECIMAL(12,2)) as selling_price, 
        CAST(i.cost_price AS DECIMAL(12,2)) as cost_price, 
        CAST(i.tax_rate AS DECIMAL(5,2)) as tax_rate, 
        i.barcode,
        COALESCE((
            SELECT SUM(CASE 
                WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
                WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
                ELSE 0 
            END)
            FROM transaction_lines l
            JOIN transaction_headers h ON l.header_id = h.id
            WHERE l.item_id = i.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ), 0) as current_stock
    FROM items i 
    LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category'
    WHERE i.is_active = 1 AND i.is_deleted = 0
    ORDER BY i.item_name ASC
";

$items = $db->fetchAll($query);

// Keep current_stock column in items table synchronized
foreach ($items as $it) {
    $db->execute("UPDATE items SET current_stock = ? WHERE id = ?", [$it['current_stock'], $it['id']]);
}

echo json_encode([
    'status' => 'success',
    'items' => $items
]);
