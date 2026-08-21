<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';
require_once __DIR__ . '/../api/InventoryEngine.php';

$db = db();
$item_id = '84'; // Gorkha 650

// Test receiveStock with rate 337.50 per bottle (4050 per case of 12)
$incoming_qty = 12;
$incoming_unit_cost = 337.50; // Rs 337.50 per bottle = Rs 4050 per case

$new_cost = InventoryEngine::getInstance()->calculateMovingAverageCost($item_id, 1, $incoming_qty, $incoming_unit_cost);

$item = $db->fetchOne("SELECT id, item_name, cost_price, case_purchase_price, mrp, units_per_case FROM items WHERE id = ?", [$item_id]);
echo "ITEM MASTER AFTER LAST PURCHASE PRICE UPDATE:\n";
print_r($item);
