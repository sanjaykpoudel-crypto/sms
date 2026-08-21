<?php
require_once 'database/DBConnection.php';
$db = db();

$header = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number = 'VI-GOKA-00026'");
print_r($header);

$bill = $db->fetchOne("SELECT * FROM vendor_bills WHERE header_id = ?", [$header['id']]);
print_r($bill);

$lines = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$header['id']]);
echo "--- TRANSACTION LINES ---\n";
print_r($lines);

$gl = $db->fetchAll("SELECT j.*, a.account_name, a.account_subtype FROM journal_entries j JOIN accounts a ON j.account_id=a.id WHERE j.header_id = ?", [$header['id']]);
echo "--- JOURNAL ENTRIES ---\n";
print_r($gl);

$mv = $db->fetchAll("SELECT * FROM inventory_movements WHERE reference_id = ?", [$header['id']]);
echo "--- INVENTORY MOVEMENTS ---\n";
print_r($mv);
