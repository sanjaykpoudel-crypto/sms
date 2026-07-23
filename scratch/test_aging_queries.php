<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$today = date('Y-m-d');

// AR Aging
$ar_sql = "
    SELECT 
        c.customer_code,
        c.full_name as customer_name,
        COALESCE(SUM(open_docs.balance_due), 0.00) as total_due,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) BETWEEN 0 AND 30 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_30,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) BETWEEN 31 AND 60 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) BETWEEN 61 AND 90 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_90,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) > 90 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_over_90
    FROM customers c
    JOIN (
        SELECT ci.customer_id, ci.invoice_date as doc_date, ci.balance_due
        FROM customer_invoices ci
        JOIN transaction_headers h ON ci.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        WHERE ci.balance_due > 0.01

        UNION ALL

        SELECT 
            COALESCE(j.party_id, h.party_id) as customer_id,
            h.txn_date as doc_date,
            (SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0)) as balance_due
        FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
        WHERE (j.party_type = 'customer' OR j.party_type IS NULL) 
          AND h.is_deleted = 0 
          AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.txn_type IN ('Journal', 'journal_entry')
        GROUP BY h.id, j.party_id, h.party_id, h.txn_date
        HAVING balance_due > 0.01
    ) open_docs ON c.id = open_docs.customer_id
    WHERE c.is_deleted = 0
    GROUP BY c.id, c.customer_code, c.full_name
    HAVING total_due > 0.00
    ORDER BY c.full_name ASC
";
$ar_rows = $db->fetchAll($ar_sql, [$today, $today, $today, $today]);

echo "AR AGING ROWS:\n";
print_r($ar_rows);

// AP Aging
$ap_sql = "
    SELECT 
        v.vendor_code,
        v.company_name as vendor_name,
        COALESCE(SUM(open_docs.balance_due), 0.00) as total_due,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) BETWEEN 0 AND 30 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_30,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) BETWEEN 31 AND 60 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) BETWEEN 61 AND 90 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_90,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) > 90 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_over_90
    FROM vendors v
    JOIN (
        SELECT vb.vendor_id, vb.bill_date as doc_date, vb.balance_due
        FROM vendor_bills vb
        JOIN transaction_headers h ON vb.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        WHERE vb.balance_due > 0.01

        UNION ALL

        SELECT 
            COALESCE(j.party_id, h.party_id) as vendor_id,
            h.txn_date as doc_date,
            (SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0)) as balance_due
        FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
        WHERE (j.party_type = 'vendor' OR j.party_type IS NULL) 
          AND h.is_deleted = 0 
          AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.txn_type IN ('Journal', 'journal_entry')
        GROUP BY h.id, j.party_id, h.party_id, h.txn_date
        HAVING balance_due > 0.01
    ) open_docs ON v.id = open_docs.vendor_id
    WHERE v.is_deleted = 0
    GROUP BY v.id, v.vendor_code, v.company_name
    HAVING total_due > 0.00
    ORDER BY v.company_name ASC
";
$ap_rows = $db->fetchAll($ap_sql, [$today, $today, $today, $today]);

echo "\nAP AGING ROWS:\n";
print_r($ap_rows);
