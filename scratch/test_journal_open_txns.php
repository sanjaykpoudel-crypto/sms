<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$sanjay_id = '599abbd6-6f76-4d74-8618-14feed600342'; // Customer
$friendship_id = '53566186-b9c3-434f-a272-69a46a765c00'; // Vendor

echo "Testing open journals for Customer Sanjay ({$sanjay_id}):\n";
$customer_journals = $db->fetchAll("
    SELECT h.id, h.txn_number, h.txn_date,
        SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as net_amount,
        COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0) as prev_applied
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
    WHERE j.party_id = ? AND (j.party_type = 'customer' OR j.party_type IS NULL) AND h.is_deleted = 0
    GROUP BY h.id, h.txn_number, h.txn_date
", [$sanjay_id]);
print_r($customer_journals);

echo "\nTesting open journals for Vendor Friendship ({$friendship_id}):\n";
$vendor_journals = $db->fetchAll("
    SELECT h.id, h.txn_number, h.txn_date,
        SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) as net_amount,
        COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0) as prev_applied
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
    WHERE j.party_id = ? AND (j.party_type = 'vendor' OR j.party_type IS NULL) AND h.is_deleted = 0
    GROUP BY h.id, h.txn_number, h.txn_date
", [$friendship_id]);
print_r($vendor_journals);
