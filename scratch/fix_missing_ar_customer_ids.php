<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$ids = [220, 69, 85, 171, 193];

foreach ($ids as $id) {
    $th = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
    $cp = $db->fetchOne("SELECT * FROM customer_payments WHERE transaction_id = ? OR payment_number = ?", [$id, $th['txn_number'] ?? '']);
    echo "Payment {$th['txn_number']} (ID $id):\n";
    echo "  customer_id in transaction_headers: " . ($th['customer_id'] ?? 'NULL') . "\n";
    echo "  party_id in transaction_headers: " . ($th['party_id'] ?? 'NULL') . "\n";
    echo "  customer_id in customer_payments: " . ($cp['customer_id'] ?? 'NULL') . "\n";
}
