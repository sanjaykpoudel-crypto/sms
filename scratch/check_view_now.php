<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$id = 'dd1ac436-ebca-4fa2-9407-44f158256b13';

$item = $db->fetchOne("
    SELECT i.*, 
        (
            SELECT COALESCE(SUM(CASE 
                WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
                WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
                ELSE 0 END), 0)
            FROM transaction_lines l
            JOIN transaction_headers h ON l.header_id = h.id
            WHERE l.item_id = i.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ) as current_stock
    FROM items i
    WHERE i.id = ?
", [$id]);

echo "Updated Item View current_stock for Gorkha 650: " . $item['current_stock'] . "\n";
