<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$items = $db->fetchAll("
    SELECT i.id, i.sku, i.item_name, r.name as category_name, i.selling_price, i.cost_price, i.tax_rate, i.barcode,
        COALESCE((
            SELECT SUM(CASE 
                WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
                WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
                ELSE 0 
            END)
            FROM transaction_lines l
            JOIN transaction_headers h ON l.header_id = h.id
            WHERE l.item_id = i.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ), 0) as current_stock
    FROM items i 
    LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category'
    WHERE i.is_active = 1 AND i.is_deleted = 0
    ORDER BY i.item_name ASC
");

echo "Total POS items loaded: " . count($items) . "\n";
echo "First 5 items sample:\n";
foreach (array_slice($items, 0, 5) as $it) {
    echo sprintf(
        "SKU: %-10s | Name: %-30s | Stock: %6.1f | Cost: Rs %8.2f | Sell: Rs %8.2f\n",
        $it['sku'],
        $it['item_name'],
        $it['current_stock'],
        $it['cost_price'],
        $it['selling_price']
    );
}

// Find Gorkha 650 in items:
$gorkha = array_filter($items, fn($i) => $i['sku'] === 'CB-031');
if (!empty($gorkha)) {
    $g = array_values($gorkha)[0];
    echo sprintf("\nFOUND Gorkha 650 (CB-031) in POS items list:\nStock: %f | Cost: Rs %f | Sell: Rs %f\n", $g['current_stock'], $g['cost_price'], $g['selling_price']);
}
