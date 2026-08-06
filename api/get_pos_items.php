<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
header('Content-Type: application/json');
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

$db = db();

$user_id = $_SESSION['user_id'] ?? '';
$location_id = $_GET['location_id'] ?? ($_SESSION['location_id'] ?? (function_exists('get_user_default_location_id') ? get_user_default_location_id() : ''));

if (empty($location_id) && $user_id) {
    $user = $db->fetchOne("SELECT location_id FROM users WHERE id = ?", [$user_id]);
    $location_id = $user['location_id'] ?? '';
}

$query = "
    SELECT 
        i.id, i.sku, i.item_name, r.name as category_name, 
        CAST(COALESCE(ib.selling_price, i.selling_price) AS DECIMAL(12,2)) as selling_price, 
        CAST(COALESCE(ib.average_cost, i.cost_price) AS DECIMAL(12,2)) as cost_price,
        CAST(COALESCE(ib.mrp, i.mrp, 0) AS DECIMAL(12,2)) as mrp,
        CAST(i.tax_rate AS DECIMAL(5,2)) as tax_rate, 
        i.barcode,
        CAST(COALESCE(ib.quantity_on_hand, 0) AS DECIMAL(12,2)) as current_stock
    FROM items i 
    LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category'
    LEFT JOIN inventory_balances ib ON i.id = ib.item_id AND ib.location_id = ?
    WHERE i.is_active = 1 AND i.is_deleted = 0
      AND COALESCE(ib.quantity_on_hand, 0) > 0
    ORDER BY i.item_name ASC
";

$items = $db->fetchAll($query, [$location_id]);

echo json_encode([
    'status' => 'success',
    'location_id' => $location_id,
    'items' => $items
]);
