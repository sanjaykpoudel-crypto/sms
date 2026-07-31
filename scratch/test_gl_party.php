<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== TESTING ENHANCED GENERAL LEDGER PARTY COALESCE WITH LIMIT 1 ===\n\n";

$sql = "
    SELECT 
        j.entry_date,
        h.txn_number,
        h.txn_type,
        a.account_name,
        j.amount,
        COALESCE(
            CASE WHEN j.party_type = 'customer' THEN (SELECT full_name FROM customers WHERE id = j.party_id LIMIT 1) END,
            CASE WHEN j.party_type = 'vendor' THEN (SELECT company_name FROM vendors WHERE id = j.party_id LIMIT 1) END,
            (SELECT full_name FROM customers WHERE id = j.party_id LIMIT 1),
            (SELECT company_name FROM vendors WHERE id = j.party_id LIMIT 1),
            CASE WHEN h.party_type = 'customer' THEN (SELECT full_name FROM customers WHERE id = h.party_id LIMIT 1) END,
            CASE WHEN h.party_type = 'vendor' THEN (SELECT company_name FROM vendors WHERE id = h.party_id LIMIT 1) END,
            (SELECT full_name FROM customers WHERE id = h.party_id LIMIT 1),
            (SELECT company_name FROM vendors WHERE id = h.party_id LIMIT 1),
            (SELECT c.full_name FROM customer_invoices ci JOIN customers c ON ci.customer_id = c.id WHERE ci.header_id = h.id LIMIT 1),
            (SELECT v.company_name FROM vendor_bills vb JOIN vendors v ON vb.vendor_id = v.id WHERE vb.header_id = h.id LIMIT 1),
            (SELECT c.full_name FROM credit_memos cm JOIN customers c ON cm.customer_id = c.id WHERE cm.header_id = h.id LIMIT 1),
            (SELECT v.company_name FROM vendor_credits vc JOIN vendors v ON vc.vendor_id = v.id WHERE vc.header_id = h.id LIMIT 1),
            (SELECT c.full_name FROM payments p JOIN customers c ON p.customer_id = c.id WHERE p.header_id = h.id LIMIT 1),
            (SELECT v.company_name FROM payments p JOIN vendors v ON p.vendor_id = v.id WHERE p.header_id = h.id LIMIT 1),
            (SELECT v.company_name FROM expenses e JOIN vendors v ON e.vendor_id = v.id WHERE e.header_id = h.id LIMIT 1),
            '-'
        ) as party
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    JOIN accounts a ON j.account_id = a.id
    WHERE h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ORDER BY j.entry_date DESC, h.updated_at DESC
    LIMIT 30
";

$rows = $db->fetchAll($sql);

foreach ($rows as $r) {
    echo "Date: {$r['entry_date']} | Txn: {$r['txn_number']} | Type: {$r['txn_type']} | Account: {$r['account_name']} | Party: " . ($r['party'] ?: '-') . "\n";
}
