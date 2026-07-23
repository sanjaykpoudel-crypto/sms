<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$id = 'dd1ac436-ebca-4fa2-9407-44f158256b13';

// View query before our edit:
$view_before = $db->fetchOne("
    SELECT (
        SELECT SUM(CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'POS') THEN -l.quantity 
            ELSE 0 END)
        FROM transaction_lines l
        JOIN transaction_headers h ON l.header_id = h.id
        WHERE l.item_id = i.id AND h.status NOT IN ('void', 'voided', 'draft')
    ) as current_stock
    FROM items i
    WHERE i.id = ?
", [$id]);

echo "View Query (Before edit): " . var_export($view_before['current_stock'], true) . "\n";

// Let's check each line included in view_before query:
$lines = $db->fetchAll("
    SELECT h.txn_number, h.txn_type, h.status, h.is_deleted, l.quantity,
        (CASE 
            WHEN h.txn_type IN ('vendor_bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
            WHEN h.txn_type IN ('customer_invoice', 'POS') THEN -l.quantity 
            ELSE 0 END) as impact
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE l.item_id = ? AND h.status NOT IN ('void', 'voided', 'draft')
", [$id]);

$running = 0;
foreach ($lines as $l) {
    $running += $l['impact'];
    echo sprintf("Txn: %-35s | Type: %-20s | Status: %-10s | Deleted: %d | Impact: %6.1f | Running: %6.1f\n",
        $l['txn_number'], $l['txn_type'], $l['status'], $l['is_deleted'], $l['impact'], $running);
}
