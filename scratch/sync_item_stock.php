<?php
require_once 'database/DBConnection.php';
require_once 'api/InventoryEngine.php';
require_once 'api/ReportingEngine.php';

$db = db();

// Sync items.current_stock from inventory_balances or inventory_movements
$db->execute("
    UPDATE items i
    LEFT JOIN (
        SELECT item_id, SUM(quantity_on_hand) as total_qty
        FROM inventory_balances
        GROUP BY item_id
    ) ib ON i.id = ib.item_id
    SET i.current_stock = COALESCE(ib.total_qty, 0)
    WHERE i.is_deleted = 0
");

$subledger_val = (float)($db->fetchOne("
    SELECT SUM(current_stock * cost_price) as val FROM items WHERE is_deleted = 0 AND is_active = 1
")['val'] ?? 0);

$gl_bal = re_get_inventory_gl_balance($db, date('Y-m-d'));

echo "After Sync from inventory_balances:\n";
echo "Subledger Stock Val: {$subledger_val}\n";
echo "GL Inventory Asset: {$gl_bal}\n";
echo "Difference: " . round($subledger_val - $gl_bal, 2) . "\n";
