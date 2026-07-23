<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$id = 'dd1ac436-ebca-4fa2-9407-44f158256b13';

$movements = $db->fetchAll("
    SELECT h.id, h.txn_date, h.txn_number, h.txn_type, l.quantity, l.unit_price, l.line_total 
    FROM transaction_lines l 
    JOIN transaction_headers h ON l.header_id = h.id 
    WHERE l.item_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ORDER BY h.txn_date DESC, h.created_at DESC LIMIT 50
", [$id]);

echo "Total movements listed: " . count($movements) . "\n";
echo "Movements List:\n";
foreach ($movements as $m) {
    echo sprintf("Date: %s | Txn: %-30s | Qty: %8.2f\n", $m['txn_date'], $m['txn_number'], $m['quantity']);
}
