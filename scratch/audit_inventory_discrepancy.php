<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
$db = db();

echo "=== Subledger Item Stocks vs Valuation ===\n";
$items = $db->fetchAll("
    SELECT i.id, i.item_name, i.current_stock, i.cost_price, (i.current_stock * i.cost_price) as val
    FROM items i
    WHERE i.is_deleted = 0 AND (i.current_stock * i.cost_price) > 0
    ORDER BY val DESC
");
$tot_sub = 0;
foreach ($items as $it) {
    $tot_sub += $it['val'];
    if ($it['val'] > 5000) {
        printf("Item %-4d: %-30s | Stock: %6.1f | Cost: %8.2f | Total Val: Rs. %10.2f\n", $it['id'], substr($it['item_name'], 0, 30), $it['current_stock'], $it['cost_price'], $it['val']);
    }
}
echo "Total Subledger Valuation: Rs. " . number_format($tot_sub, 2) . "\n";

$inv_gl = re_get_inventory_gl_balance($db);
echo "Total GL Inventory Account #7: Rs. " . number_format($inv_gl, 2) . "\n";
echo "Difference: Rs. " . number_format($tot_sub - $inv_gl, 2) . "\n";
