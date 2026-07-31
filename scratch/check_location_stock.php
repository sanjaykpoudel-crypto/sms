<?php
require_once 'database/DBConnection.php';
$db = db();

echo "--- INVENTORY BALANCES BY LOCATION ---\n";
$loc_balances = $db->fetchAll("SELECT location_id, COUNT(*) as item_count, SUM(quantity_on_hand) as total_stock FROM inventory_balances GROUP BY location_id");
print_r($loc_balances);

echo "\n--- LOCATIONS LIST ---\n";
$locations = $db->fetchAll("SELECT id, name, is_default FROM locations");
print_r($locations);

echo "\n--- TRANSACTION LINES COUNT BY LOCATION ---\n";
$txns_by_loc = $db->fetchAll("
    SELECT h.location_id, COUNT(DISTINCT l.item_id) as distinct_items, COUNT(*) as total_lines, SUM(l.quantity) as total_qty
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0
    GROUP BY h.location_id
");
print_r($txns_by_loc);
