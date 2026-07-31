<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== TESTING STOCK SUMMARY REPORT QUERY ===\n\n";

$locations = ['all', 'loc-main-retail', 'loc-main-wh'];

foreach ($locations as $loc_id) {
    echo "--- LOCATION: $loc_id ---\n";
    if (!empty($loc_id) && $loc_id !== 'all') {
        $rows = $db->fetchAll("
            SELECT 
                i.id, i.sku, i.item_name, rc1.name as item_category,
                i.cost_price, i.selling_price, i.reorder_level,
                COALESCE(ib.quantity_on_hand, 0) AS stock_qty
            FROM items i
            LEFT JOIN inventory_balances ib ON ib.item_id = i.id AND ib.location_id = " . $db->getConnection()->quote($loc_id) . "
            LEFT JOIN reference_codes rc1 ON i.item_category = rc1.id AND rc1.type = 'category'
            WHERE i.is_deleted = 0 AND i.is_active = 1
              AND (COALESCE(ib.quantity_on_hand, 0) != 0 OR EXISTS (
                    SELECT 1 
                    FROM transaction_lines tl 
                    JOIN transaction_headers th ON tl.header_id = th.id 
                    WHERE tl.item_id = i.id 
                      AND COALESCE(NULLIF(tl.location_id, ''), th.location_id) = " . $db->getConnection()->quote($loc_id) . " 
                      AND th.is_deleted = 0 
                      AND th.status NOT IN ('void', 'voided', 'draft')
              ))
            GROUP BY i.id
            ORDER BY rc1.name, i.item_name
        ");
    } else {
        $rows = $db->fetchAll("
            SELECT 
                i.id, i.sku, i.item_name, rc1.name as item_category,
                i.cost_price, i.selling_price, i.reorder_level,
                COALESCE(i.current_stock, 0) AS stock_qty
            FROM items i
            LEFT JOIN reference_codes rc1 ON i.item_category = rc1.id AND rc1.type = 'category'
            WHERE i.is_deleted = 0 AND i.is_active = 1
            GROUP BY i.id
            ORDER BY rc1.name, i.item_name
        ");
    }

    $total_value = 0;
    foreach ($rows as $r) { $total_value += $r['stock_qty'] * $r['cost_price']; }
    $low_stock_count = count(array_filter($rows, fn($r) => $r['reorder_level'] !== null && $r['stock_qty'] <= $r['reorder_level']));

    echo "Total Items: " . count($rows) . "\n";
    echo "Stock Value: Rs. " . number_format($total_value, 2) . "\n";
    echo "Low / Out of Stock: " . $low_stock_count . "\n\n";
}
