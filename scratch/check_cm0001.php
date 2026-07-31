<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== CHECKING CM-0001 IN DATABASE ===\n\n";

$th = $db->fetchAll("SELECT * FROM transaction_headers WHERE txn_number = 'CM-0001'");
echo "TRANSACTION HEADERS:\n";
print_r($th);

$cm = $db->fetchAll("SELECT * FROM credit_memos WHERE memo_number = 'CM-0001'");
echo "\nCREDIT MEMOS:\n";
print_r($cm);

$je = $db->fetchAll("SELECT j.*, h.is_deleted as h_is_deleted, h.status as h_status FROM journal_entries j JOIN transaction_headers h ON j.header_id = h.id WHERE h.txn_number = 'CM-0001'");
echo "\nJOURNAL ENTRIES:\n";
print_r($je);
