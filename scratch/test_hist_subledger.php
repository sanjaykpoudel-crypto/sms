<?php
require_once 'database/DBConnection.php';
$db = db();

$as_of = '2026-07-16';

// Historical stock valuation as of 2026-07-16 from inventory_movements
$hist_val = (float)($db->fetchOne("
    SELECT COALESCE(SUM(m.net_qty * i.cost_price), 0) as hist_subledger_val
    FROM inventory_movements m
    JOIN items i ON m.item_id = i.id
    WHERE m.movement_date <= ? AND i.is_deleted = 0
", [$as_of])[0] ?? 0);

echo "Historical Stock Valuation from inventory_movements as of {$as_of}: Rs " . number_format($hist_val, 2) . "\n";

// GL Inventory balance as of 2026-07-16
require_once 'api/ReportingEngine.php';
$gl_val = re_get_inventory_gl_balance($db, $as_of);
echo "GL Inventory Account #7 Balance as of {$as_of}: Rs " . number_format($gl_val, 2) . "\n";
echo "Difference: Rs " . number_format(abs($hist_val - $gl_val), 2) . "\n";
