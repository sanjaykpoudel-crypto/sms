<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$id = 'dd1ac436-ebca-4fa2-9407-44f158256b13';

$lines = $db->fetchAll("
    SELECT h.txn_number, h.txn_type, h.status, h.is_deleted, l.quantity,
        (CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
            ELSE 0 
        END) as impact
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE l.item_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
", [$id]);

echo "Lines counted in query:\n";
$sum = 0;
foreach ($lines as $l) {
    $sum += $l['impact'];
    echo sprintf("Txn: %-30s | Type: %-20s | Qty: %6.2f | Impact: %6.2f | Running: %6.2f\n", $l['txn_number'], $l['txn_type'], $l['quantity'], $l['impact'], $sum);
}
echo "Total Sum: " . $sum . "\n";
