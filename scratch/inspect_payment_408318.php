<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$id = 408318;
echo "=== transaction_headers for ID $id ===\n";
$th = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
print_r($th);

echo "\n=== payments for header_id = $id ===\n";
$p = $db->fetchAll("SELECT * FROM payments WHERE header_id = ?", [$id]);
print_r($p);

echo "\n=== payments where id = $id ===\n";
$p2 = $db->fetchAll("SELECT * FROM payments WHERE id = ?", [$id]);
print_r($p2);

echo "\n=== journal_entries for transaction_id = $id ===\n";
$je = $db->fetchAll("SELECT * FROM journal_entries WHERE transaction_id = ?", [$id]);
print_r($je);

echo "\n=== transaction_links for parent or child = $id ===\n";
$tl = $db->fetchAll("SELECT * FROM transaction_links WHERE parent_id = ? OR child_id = ?", [$id, $id]);
print_r($tl);
