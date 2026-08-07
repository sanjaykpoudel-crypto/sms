<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== CHECKING ALL UNMATCHED UUID PARTY_IDs IN JOURNAL ENTRIES & PAYMENTS ===\n";

$jes = $db->fetchAll("
    SELECT DISTINCT party_id, party_type 
    FROM journal_entries 
    WHERE party_id IS NOT NULL AND party_id != '' AND party_id NOT IN (SELECT id FROM customers) AND party_id NOT IN (SELECT id FROM vendors)
");

echo "Unmatched Journal Entry party_ids:\n";
print_r($jes);

$pays = $db->fetchAll("
    SELECT DISTINCT customer_id, vendor_id 
    FROM payments 
    WHERE (customer_id IS NOT NULL AND customer_id != '' AND customer_id NOT IN (SELECT id FROM customers))
       OR (vendor_id IS NOT NULL AND vendor_id != '' AND vendor_id NOT IN (SELECT id FROM vendors))
");

echo "Unmatched Payment party_ids:\n";
print_r($pays);
