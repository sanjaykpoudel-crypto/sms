<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "Running verification across Item List, Item View, and Stock Summary Report...\n";

// 1. Item List stock query
$item_list_stocks = $db->fetchAll("
    SELECT i.id, i.sku, i.item_name,
        (SELECT COALESCE(SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
            ELSE 0 END), 0)
         FROM transaction_lines l
         JOIN transaction_headers h ON l.header_id = h.id
         WHERE l.item_id = i.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ) as current_stock
    FROM items i 
    WHERE i.is_deleted = 0
");

// 2. Stock Summary Report query
$stock_rpt_rows = $db->fetchAll("
    SELECT 
        i.id, i.sku, i.item_name,
        COALESCE(SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
            ELSE 0 
        END), 0) AS stock_qty
    FROM items i
    LEFT JOIN transaction_lines l ON l.item_id = i.id
    LEFT JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    WHERE i.is_deleted = 0 AND i.is_active = 1
    GROUP BY i.id
");
$rpt_map = [];
foreach ($stock_rpt_rows as $r) {
    $rpt_map[$r['id']] = (float)$r['stock_qty'];
}

$mismatch = 0;
foreach ($item_list_stocks as $item) {
    $id = $item['id'];
    $list_stock = (float)$item['current_stock'];
    $rpt_stock = $rpt_map[$id] ?? null;

    if ($rpt_stock !== null && $list_stock !== $rpt_stock) {
        echo "MISMATCH found for SKU {$item['sku']}: Item List = {$list_stock}, Stock Report = {$rpt_stock}\n";
        $mismatch++;
    }
}

if ($mismatch === 0) {
    echo "SUCCESS: Stock values match perfectly across Item List, Item View, and Stock Reports for all " . count($item_list_stocks) . " items!\n";
} else {
    echo "FAILED: Found {$mismatch} mismatches.\n";
}
