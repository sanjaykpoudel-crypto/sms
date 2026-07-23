<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "Header details for POS-SUM-20260719-DEL-acf062a7:\n";
$header = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number LIKE 'POS-SUM-20260719%'");
print_r($header);

echo "\nPOS Entries for 2026-07-19:\n";
$pos_entries = $db->fetchAll("SELECT * FROM pos_entry WHERE DATE(date_time) = '2026-07-19'");
print_r($pos_entries);

echo "\nPOS Items for 2026-07-19:\n";
$pos_items = $db->fetchAll("
    SELECT pi.*, p.invoice_no, p.status as pos_status, p.is_deleted as pos_is_deleted
    FROM pos_items pi
    JOIN pos_entry p ON pi.pos_id = p.id
    WHERE DATE(p.date_time) = '2026-07-19'
");
print_r($pos_items);
