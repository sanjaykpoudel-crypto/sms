<?php
require_once 'database/DBConnection.php';
$db = db();

echo "--- CHECKING BLANK OR NULL txn_type IN transaction_headers ---\n";
$blank_txns = $db->fetchAll("SELECT id, txn_number, txn_type, reference_number FROM transaction_headers WHERE txn_type IS NULL OR txn_type = ''");
print_r($blank_txns);

echo "\n--- REPAIRING BLANK txn_type RECORDS ---\n";
$db->execute("UPDATE transaction_headers SET txn_type = 'credit_memo' WHERE (txn_type IS NULL OR txn_type = '') AND (txn_number LIKE 'CM-%' OR reference_number LIKE 'CM-%')");
$db->execute("UPDATE transaction_headers SET txn_type = 'vendor_credit' WHERE (txn_type IS NULL OR txn_type = '') AND (txn_number LIKE 'VC-%' OR reference_number LIKE 'VC-%')");
$db->execute("UPDATE transaction_headers SET txn_type = 'customer_invoice' WHERE (txn_type IS NULL OR txn_type = '') AND (txn_number LIKE 'INV-%' OR txn_number LIKE 'SI-%')");
$db->execute("UPDATE transaction_headers SET txn_type = 'vendor_bill' WHERE (txn_type IS NULL OR txn_type = '') AND (txn_number LIKE 'VI-%' OR txn_number LIKE 'BILL-%')");

echo "REPAIR COMPLETE.\n";

$remaining = $db->fetchAll("SELECT id, txn_number, txn_type FROM transaction_headers WHERE txn_type IS NULL OR txn_type = ''");
echo "REMAINING BLANK RECS: " . count($remaining) . "\n";
