<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../forms/modules/reports/rpt_helpers.php';
$db = db();

$_GET['date_from'] = '2026-08-01';
$_GET['date_to'] = '2026-08-20';
$_GET['location_id'] = '1';

$date_from = $_GET['date_from'];
$date_to = $_GET['date_to'];
$loc_sql = rpt_location_sql('h');

echo "loc_sql generated: [{$loc_sql}]\n";

// Query 1: Only customer_invoice (current code in top_profit_items_list.php)
$items_curr = $db->fetchAll("
    SELECT 
        i.sku, 
        i.item_name, 
        rc.name as category_name,
        SUM(l.quantity) as total_qty, 
        SUM(CASE 
            WHEN h.txn_number LIKE 'INV-POS-%' OR h.txn_number LIKE 'POS-SUM-%' THEN l.line_total
            ELSE l.line_total - l.tax_amount
        END) as total_revenue, 
        SUM(l.cost_price * COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))) as total_cost,
        SUM(COALESCE(l.gross_profit, (CASE WHEN h.txn_number LIKE 'INV-POS-%' OR h.txn_number LIKE 'POS-SUM-%' THEN l.line_total ELSE l.line_total - l.tax_amount END) - (l.cost_price * COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))))) as total_profit
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    JOIN items i ON l.item_id = i.id
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE h.txn_type = 'customer_invoice' 
      AND h.is_deleted = 0 
      AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ? {$loc_sql}
    GROUP BY l.item_id
    ORDER BY total_profit DESC
", [$date_from, $date_to]);

echo "Current query returned count: " . count($items_curr) . "\n";

// Check if there are POS entries in pos_items for date range that are not in transaction_headers
$pos_unrolled = $db->fetchAll("
    SELECT pi.*, pe.date_time, pe.location_id
    FROM pos_items pi
    JOIN pos_entry pe ON pi.pos_id = pe.id
    WHERE pe.is_deleted = 0 AND DATE(pe.date_time) BETWEEN ? AND ?
", [$date_from, $date_to]);
echo "Unrolled pos_items count: " . count($pos_unrolled) . "\n";
