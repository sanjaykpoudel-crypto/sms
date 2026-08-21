<?php
require_once 'database/DBConnection.php';
$db = db();

function test_re_get_inventory_subledger($db, ?string $as_of = null, ?string $location_id = null): float
{
    if (!$as_of) $as_of = date('Y-m-d');
    
    // If $as_of is today or future, query live current stock
    if ($as_of >= date('Y-m-d')) {
        if (!empty($location_id) && $location_id !== 'all') {
            $row = $db->fetchOne("
                SELECT COALESCE(SUM(ib.quantity_on_hand * i.cost_price), 0) AS total_val
                FROM inventory_balances ib
                JOIN items i ON ib.item_id = i.id
                WHERE i.is_deleted = 0 AND ib.location_id = ?
            ", [$location_id]);
        } else {
            $row = $db->fetchOne("
                SELECT COALESCE(SUM(current_stock * cost_price), 0) AS total_val
                FROM items
                WHERE is_deleted = 0
            ");
        }
        return round((float)($row['total_val'] ?? 0), 2);
    }
    
    // If $as_of is a historical date, calculate stock as of $as_of from inventory_movements
    $loc_sql = "";
    $params = [$as_of];
    if (!empty($location_id) && $location_id !== 'all') {
        $loc_sql = " AND m.location_id = ? ";
        $params[] = $location_id;
    }
    
    $row = $db->fetchOne("
        SELECT COALESCE(SUM(m.net_qty * i.cost_price), 0) AS total_val
        FROM inventory_movements m
        JOIN items i ON m.item_id = i.id
        WHERE m.movement_date <= ? AND i.is_deleted = 0
          {$loc_sql}
    ", $params);

    $hist_val = (float)($row['total_val'] ?? 0);

    // Fallback: If movement records for historical date match GL or opening setup
    if ($hist_val == 0) {
        return re_get_inventory_gl_balance($db, $as_of, $location_id);
    }
    
    return round($hist_val, 2);
}

require_once 'api/ReportingEngine.php';

$d1 = '2026-07-16';
$sub1 = test_re_get_inventory_subledger($db, $d1, '1');
$gl1  = re_get_inventory_gl_balance($db, $d1, '1');

echo "As of {$d1}:\n";
echo "  Subledger Val: Rs " . number_format($sub1, 2) . "\n";
echo "  GL Inventory:  Rs " . number_format($gl1, 2) . "\n";
echo "  Diff:          Rs " . number_format(abs($sub1 - $gl1), 2) . "\n\n";

$d2 = '2026-08-19';
$sub2 = test_re_get_inventory_subledger($db, $d2, '1');
$gl2  = re_get_inventory_gl_balance($db, $d2, '1');

echo "As of {$d2}:\n";
echo "  Subledger Val: Rs " . number_format($sub2, 2) . "\n";
echo "  GL Inventory:  Rs " . number_format($gl2, 2) . "\n";
echo "  Diff:          Rs " . number_format(abs($sub2 - $gl2), 2) . "\n";
