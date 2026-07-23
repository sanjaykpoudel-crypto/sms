<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "JOURNAL ENTRIES WITH PARTY_ID:\n";
$entries = $db->fetchAll("
    SELECT j.*, h.txn_number, h.txn_type, h.txn_date, h.is_deleted, a.account_name, a.account_type
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    LEFT JOIN accounts a ON j.account_id = a.id
    WHERE j.party_id IS NOT NULL AND j.party_id != '' AND h.is_deleted = 0
");

print_r($entries);

echo "\nTRANSACTION HEADERS OF TYPE JOURNAL WITH PARTY_ID:\n";
$headers = $db->fetchAll("
    SELECT * FROM transaction_headers 
    WHERE (txn_type = 'Journal' OR txn_type = 'journal_entry') AND party_id IS NOT NULL AND party_id != '' AND is_deleted = 0
");
print_r($headers);
