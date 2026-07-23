<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$item = $db->fetchOne("SELECT * FROM items WHERE sku = 'CB-031' OR id = 'dd1ac436-ebca-4fa2-9407-44f158256b13'");

if (!$item) {
    echo "Item not found by SKU CB-031\n";
    exit;
}

echo "ITEM DETAILS:\n";
echo "ID: " . $item['id'] . "\n";
echo "SKU: " . $item['sku'] . "\n";
echo "Name: " . $item['item_name'] . "\n";
echo "Cached current_stock in items table: " . $item['current_stock'] . "\n\n";

echo "TRANSACTIONS IN TRANSACTION_LINES:\n";
$lines = $db->fetchAll("
    SELECT h.id as header_id, h.txn_number, h.txn_type, h.txn_date, h.status, h.is_deleted, l.quantity, l.unit_price, l.line_total
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE l.item_id = ?
    ORDER BY h.txn_date ASC, h.created_at ASC
", [$item['id']]);

$total_stock = 0;
foreach ($lines as $line) {
    echo sprintf(
        "Date: %s | Txn: %-15s | Type: %-20s | Status: %-10s | Deleted: %d | Qty: %8.2f\n",
        $line['txn_date'],
        $line['txn_number'],
        $line['txn_type'],
        $line['status'],
        $line['is_deleted'],
        $line['quantity']
    );

    if ($line['is_deleted'] == 0 && !in_array($line['status'], ['void', 'voided', 'draft'])) {
        if (in_array($line['txn_type'], ['vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment'])) {
            $total_stock += $line['quantity'];
        } elseif (in_array($line['txn_type'], ['customer_invoice', 'Invoice', 'POS', 'Sale'])) {
            $total_stock -= $line['quantity'];
        }
    }
}

echo "\nTOTAL CALCULATED STOCK: " . $total_stock . "\n";
