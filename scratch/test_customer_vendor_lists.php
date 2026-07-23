<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

// Test Customer List
$customers = $db->fetchAll(
    "
    SELECT c.*, 
    ((
        SELECT COALESCE(SUM(ci.total_amount), 0) 
        FROM customer_invoices ci 
        JOIN transaction_headers th ON ci.header_id = th.id 
        WHERE ci.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) + (
        SELECT COALESCE(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END), 0)
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (j.party_id = c.id OR th.party_id = c.id) AND (j.party_type = 'customer' OR j.party_type IS NULL) AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    )) AS total_sales,
    (
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id 
        WHERE p.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) AS total_paid,
    ((
        SELECT COALESCE(SUM(ci.balance_due), 0) 
        FROM customer_invoices ci 
        JOIN transaction_headers th ON ci.header_id = th.id 
        WHERE ci.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) + (
        SELECT COALESCE(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END), 0) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0)
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        LEFT JOIN transaction_links tl ON tl.child_id = th.id AND tl.link_type LIKE 'payment:%'
        WHERE (j.party_id = c.id OR th.party_id = c.id) AND (j.party_type = 'customer' OR j.party_type IS NULL) AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    )) AS total_due
    FROM customers c 
    WHERE c.is_deleted = 0 AND c.full_name LIKE '%Gurkha%'
"
);

echo "Gurkha Cafe Customer List Data:\n";
print_r($customers);

// Test Vendor List
$vendors = $db->fetchAll("
    SELECT v.*, 
    ((
        SELECT COALESCE(SUM(vb.total_amount), 0) 
        FROM vendor_bills vb 
        JOIN transaction_headers th ON vb.header_id = th.id 
        WHERE vb.vendor_id = v.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) + (
        SELECT COALESCE(SUM(CASE WHEN j.entry_type='credit' THEN j.amount ELSE -j.amount END), 0)
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (j.party_id = v.id OR th.party_id = v.id) AND (j.party_type = 'vendor' OR j.party_type IS NULL) AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    )) AS total_purchase,
    (
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id 
        WHERE p.vendor_id = v.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) AS total_paid
    FROM vendors v 
    WHERE v.is_deleted = 0 AND v.company_name LIKE '%Friendship%'
");

echo "Friendship Vendor List Data:\n";
print_r($vendors);
