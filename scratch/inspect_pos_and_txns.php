<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$item = $db->fetchOne("SELECT * FROM items WHERE sku = 'CB-031'");
$item_id = $item['id'];

echo "Checking POS tables (pos_items & pos_entry) for item_id: {$item_id}\n";
$pos_items = $db->fetchAll("
    SELECT p.id, p.invoice_no, p.date_time, p.status, p.is_deleted, pi.quantity, pi.rate, pi.net_amount
    FROM pos_items pi
    JOIN pos_entry p ON pi.pos_id = p.id
    WHERE pi.item_id = ?
    ORDER BY p.date_time ASC
", [$item_id]);

foreach ($pos_items as $pi) {
    echo sprintf(
        "POS Date: %s | Invoice: %-20s | Status: %-10s | Deleted: %d | Qty: %8.2f\n",
        $pi['date_time'],
        $pi['invoice_no'],
        $pi['status'],
        $pi['is_deleted'],
        $pi['quantity']
    );
}

echo "\nChecking POS aggregation / sync in transaction_headers:\n";
$pos_headers = $db->fetchAll("
    SELECT h.txn_number, h.txn_type, h.txn_date, h.status, h.is_deleted, l.quantity
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE l.item_id = ? AND (h.txn_type = 'POS' OR h.txn_number LIKE 'INV-POS-%' OR h.txn_number LIKE 'POS-SUM-%')
    ORDER BY h.txn_date ASC
", [$item_id]);

foreach ($pos_headers as $ph) {
    echo sprintf(
        "Txn Date: %s | Number: %-25s | Type: %-15s | Status: %-10s | Deleted: %d | Qty: %8.2f\n",
        $ph['txn_date'],
        $ph['txn_number'],
        $ph['txn_type'],
        $ph['status'],
        $ph['is_deleted'],
        $ph['quantity']
    );
}
