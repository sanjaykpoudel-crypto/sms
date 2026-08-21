<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';
require_once __DIR__ . '/../api/InventoryEngine.php';

$db = db();
$item_id = '84'; // Gorkha 650

$item_before = $db->fetchOne("SELECT id, item_name, cost_price, case_purchase_price, mrp, units_per_case FROM items WHERE id = ?", [$item_id]);
echo "BEFORE UPDATE:\n";
print_r($item_before);

// Test recalculating moving average cost
$incoming_qty = 10;
$incoming_unit_cost = 340.00; // New cost
$new_cost = InventoryEngine::getInstance()->calculateMovingAverageCost($item_id, 1, $incoming_qty, $incoming_unit_cost);

$item_after = $db->fetchOne("SELECT id, item_name, cost_price, case_purchase_price, mrp, units_per_case FROM items WHERE id = ?", [$item_id]);
echo "\nAFTER UPDATE:\n";
print_r($item_after);

$logs = $db->fetchAll("SELECT * FROM audit_logs WHERE record_id = ? AND table_name = 'items' ORDER BY created_at DESC", [$item_id]);
echo "\nAUDIT LOGS FOR ITEM 84:\n";
print_r($logs);

// Restore original cost price to keep data intact
$orig_cost = (float)$item_before['cost_price'];
$orig_case = (float)$item_before['case_purchase_price'];
$db->execute("UPDATE items SET cost_price = ?, case_purchase_price = ? WHERE id = ?", [$orig_cost, $orig_case, $item_id]);
echo "\nRestored original cost ($orig_cost) and case price ($orig_case).\n";
