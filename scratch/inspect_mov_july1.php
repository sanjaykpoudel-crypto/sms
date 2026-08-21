<?php
require_once 'database/DBConnection.php';
$db = db();

$mov_sum = $db->fetchAll("
    SELECT m.movement_type, COUNT(*) as cnt, SUM(m.net_qty * i.cost_price) as tot_val
    FROM inventory_movements m
    JOIN items i ON m.item_id = i.id
    WHERE m.movement_date <= '2026-07-16'
    GROUP BY m.movement_type
");

echo "=== MOVEMENTS BY TYPE ON OR BEFORE 2026-07-16 ===\n";
print_r($mov_sum);
