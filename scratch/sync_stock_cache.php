<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$items = $db->fetchAll("SELECT id, sku, item_name, current_stock FROM items WHERE is_deleted = 0");
$updated_count = 0;

echo "Starting stock cache synchronization...\n";

foreach ($items as $item) {
    $id = $item['id'];
    $old_stock = (float)($item['current_stock'] ?? 0);

    $calc = $db->fetchOne("
        SELECT COALESCE(SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
            ELSE 0 
        END), 0) as current_stock
        FROM transaction_lines l
        JOIN transaction_headers h ON l.header_id = h.id
        WHERE l.item_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ", [$id]);

    $new_stock = (float)($calc['current_stock'] ?? 0);

    if ($old_stock !== $new_stock) {
        $db->execute("UPDATE items SET current_stock = ? WHERE id = ?", [$new_stock, $id]);
        echo "Updated SKU {$item['sku']} ({$item['item_name']}): Old Stock = {$old_stock}, New Stock = {$new_stock}\n";
        $updated_count++;
    }
}

echo "Completed. Total items updated: {$updated_count}\n";
