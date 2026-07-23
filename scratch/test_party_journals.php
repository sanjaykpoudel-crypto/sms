<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$sanjay_id = '599abbd6-6f76-4d74-8618-14feed600342'; // Customer
$friendship_id = '53566186-b9c3-434f-a272-69a46a765c00'; // Vendor

// Customer Related Invoices & Journals
$cust_invoices = $db->fetchAll("
    SELECT 'Invoice' as doc_type, ci.header_id, ci.invoice_number as doc_number, ci.invoice_date as doc_date, ci.total_amount, ci.balance_due, ci.payment_status 
    FROM customer_invoices ci 
    JOIN transaction_headers th ON ci.header_id = th.id
    WHERE ci.customer_id = ? AND th.is_deleted = 0
", [$sanjay_id]);

$cust_journals = $db->fetchAll("
    SELECT 'Journal' as doc_type, h.id as header_id, h.txn_number as doc_number, h.txn_date as doc_date,
        SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as total_amount,
        (SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0)) as balance_due,
        h.status as payment_status
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
    WHERE (j.party_id = ? OR h.party_id = ?) 
      AND (j.party_type = 'customer' OR j.party_type IS NULL) 
      AND h.is_deleted = 0 
      AND h.txn_type IN ('Journal', 'journal_entry')
    GROUP BY h.id, h.txn_number, h.txn_date, h.status
", [$sanjay_id, $sanjay_id]);

$customer_docs = array_merge($cust_invoices, $cust_journals);
echo "Customer संजय Related Invoices/Journals:\n";
print_r($customer_docs);

// Vendor Related Bills & Journals
$vend_bills = $db->fetchAll("
    SELECT 'Bill' as doc_type, vb.header_id, vb.vendor_invoice_number as doc_number, vb.bill_date as doc_date, vb.total_amount, vb.balance_due, vb.payment_status 
    FROM vendor_bills vb 
    JOIN transaction_headers th ON vb.header_id = th.id
    WHERE vb.vendor_id = ? AND th.is_deleted = 0
", [$friendship_id]);

$vend_journals = $db->fetchAll("
    SELECT 'Journal' as doc_type, h.id as header_id, h.txn_number as doc_number, h.txn_date as doc_date,
        SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) as total_amount,
        (SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0)) as balance_due,
        h.status as payment_status
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
    WHERE (j.party_id = ? OR h.party_id = ?) 
      AND (j.party_type = 'vendor' OR j.party_type IS NULL) 
      AND h.is_deleted = 0 
      AND h.txn_type IN ('Journal', 'journal_entry')
    GROUP BY h.id, h.txn_number, h.txn_date, h.status
", [$friendship_id, $friendship_id]);

$vendor_docs = array_merge($vend_bills, $vend_journals);
echo "\nVendor Friendship Related Bills/Journals:\n";
print_r($vendor_docs);
