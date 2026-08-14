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

$location_id = (int)($_GET['location_id'] ?? ($_SESSION['location_id'] ?? (function_exists('get_user_default_location_id') ? get_user_default_location_id() : 1)));
if ($location_id > 0) {
    $_SESSION['location_id'] = $location_id;
}

$query = "
    SELECT 
        i.id, i.sku, i.item_name, r.name as category_name, 
        COALESCE(ru.name, i.unit_type, 'PCS') as unit_type,
        CAST(COALESCE(ib.selling_price, i.selling_price) AS DECIMAL(12,2)) as selling_price, 
        CAST(COALESCE(ib.average_cost, i.cost_price) AS DECIMAL(12,2)) as cost_price,
        CAST(COALESCE(ib.mrp, i.mrp, 0) AS DECIMAL(12,2)) as mrp,
        CAST(i.tax_rate AS DECIMAL(5,2)) as tax_rate, 
        i.barcode, i.units_per_case, COALESCE(i.case_unit_name, 'CASE') as case_unit_name, i.case_selling_price, i.case_barcode,
        CAST(COALESCE(ib.quantity_on_hand, 0) AS DECIMAL(12,2)) as current_stock
    FROM items i 
    LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category'
    LEFT JOIN reference_codes ru ON (i.unit_type = ru.id OR i.unit_type = ru.code) AND ru.type IN ('unit', 'units')
    LEFT JOIN inventory_balances ib ON ib.item_id = i.id AND ib.location_id = ?
    WHERE i.is_active = 1 AND i.is_deleted = 0 AND COALESCE(ib.quantity_on_hand, 0) > 0
    ORDER BY i.item_name ASC
";

require_once __DIR__ . '/PromotionEngine.php';

$items = $db->fetchAll($query, [$location_id]);

$promoEngine = PromotionEngine::getInstance();
foreach ($items as &$it) {
    $promoEval = $promoEngine->evaluateItemPromotion($it['id'], $location_id, 1, (float)$it['mrp'], (float)$it['selling_price']);
    $it['has_promotion'] = $promoEval['has_promotion'];
    if ($promoEval['has_promotion']) {
        $it['promotion_id'] = $promoEval['promotion']['id'];
        $it['promo_code'] = $promoEval['promotion']['promo_code'];
        $it['promo_name'] = $promoEval['promotion']['name'];
        $it['promotional_price'] = $promoEval['promotional_selling_price'];
        $it['promo_discount_amount'] = $promoEval['discount_amount_per_unit'];
    }
}

echo json_encode([
    'status' => 'success',
    'location_id' => $location_id,
    'items' => $items
]);
