<?php
require_once 'database/DBConnection.php';
$db = db();

$movs = $db->fetchAll("
    SELECT m.*, i.item_name, i.cost_price
    FROM inventory_movements m
    JOIN items i ON m.item_id = i.id
    WHERE m.movement_date <= '2026-07-16'
");

echo "=== INVENTORY MOVEMENTS ON OR BEFORE 2026-07-16 (Count: " . count($movs) . ") ===\n";
foreach ($movs as $m) {
    echo sprintf("ID: %-6d | Date: %s | Item: %-25s | Qty In: %8.2f | Qty Out: %8.2f | Net Qty: %8.2f | Ref: %s\n",
        $m['id'], $m['movement_date'], $m['item_name'], $m['qty_in'], $m['qty_out'], $m['net_qty'], $m['reference_number']
    );
}
