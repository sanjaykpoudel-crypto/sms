<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

$item_id   = $_GET['item_id']   ?? null;
$loc_id    = $_GET['location_id'] ?? null;

if (!$item_id || !$loc_id) {
    echo json_encode(['error' => 'item_id and location_id required']);
    exit;
}

$db = db();

$balances = sync_and_get_item_inventory_balances($db, $item_id);
$stock = 0.00;
foreach ($balances as $b) {
    if ((string)$b['location_id'] === (string)$loc_id) {
        $stock = (float)($b['quantity_on_hand'] ?? 0);
        break;
    }
}

echo json_encode([
    'stock' => $stock,
    'formatted' => number_format($stock, 2)
]);

