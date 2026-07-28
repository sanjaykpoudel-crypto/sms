<?php
require_once 'database/DBConnection.php';
$db = db();
$as_of_date = '2026-07-25';

$sql = "
    SELECT 
        th.id as header_id,
        th.txn_date as invoice_date,
        th.txn_number as invoice_number,
        c.full_name as customer_name,
        c.id as customer_id,
        ci.due_date,
        DATEDIFF(?, ci.due_date) as days_overdue,
        ci.total_amount,
        ci.amount_paid,
        ci.balance_due
    FROM customer_invoices ci
    JOIN transaction_headers th ON ci.header_id = th.id
    LEFT JOIN customers c ON ci.customer_id = c.id
    WHERE th.is_deleted = 0 
      AND th.status NOT IN ('void', 'voided', 'draft')
      AND ci.balance_due > 0.01

    UNION ALL

    SELECT 
        th.id as header_id,
        th.txn_date as invoice_date,
        th.txn_number as invoice_number,
        c.full_name as customer_name,
        c.id as customer_id,
        th.txn_date as due_date,
        DATEDIFF(?, th.txn_date) as days_overdue,
        SUM(CASE WHEN j.party_id = c.id THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as total_amount,
        COALESCE((
            SELECT SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)))
            FROM transaction_links tl
            JOIN transaction_headers ph ON tl.parent_id = ph.id
            JOIN payments p ON p.header_id = ph.id
            WHERE tl.child_id = th.id 
              AND tl.link_type LIKE 'payment:%'
              AND ph.txn_type = 'customer_payment'
              AND (p.customer_id = c.id OR ph.party_id = c.id)
              AND ph.is_deleted = 0 AND ph.status NOT IN ('void', 'voided', 'draft')
        ), 0.00) as amount_paid,
        (
            SUM(CASE WHEN j.party_id = c.id THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) 
            - 
            COALESCE((
                SELECT SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)))
                FROM transaction_links tl
                JOIN transaction_headers ph ON tl.parent_id = ph.id
                JOIN payments p ON p.header_id = ph.id
                WHERE tl.child_id = th.id 
                  AND tl.link_type LIKE 'payment:%'
                  AND ph.txn_type = 'customer_payment'
                  AND (p.customer_id = c.id OR ph.party_id = c.id)
                  AND ph.is_deleted = 0 AND ph.status NOT IN ('void', 'voided', 'draft')
            ), 0.00)
        ) as balance_due
    FROM journal_entries j
    JOIN transaction_headers th ON j.header_id = th.id
    JOIN customers c ON j.party_id = c.id
    WHERE (j.party_type = 'customer' OR j.party_type IS NULL)
      AND th.is_deleted = 0 
      AND th.status NOT IN ('void', 'voided', 'draft')
      AND th.txn_type IN ('Journal', 'journal_entry')
    GROUP BY th.id, th.txn_date, th.txn_number, c.id, c.full_name
    HAVING balance_due > 0.01
    ORDER BY customer_name ASC, due_date ASC
";

$rows = $db->fetchAll($sql, [$as_of_date, $as_of_date]);

echo "=== DETAILED INVOICES (ORDERED BY CUSTOMER) ===\n";
foreach ($rows as $r) {
    echo str_pad($r['customer_name'], 22) . " | " . str_pad($r['invoice_number'], 12) . " | Total: " . str_pad($r['total_amount'], 10) . " | Paid: " . str_pad($r['amount_paid'], 8) . " | Due: " . $r['balance_due'] . "\n";
}

// Group by customer for Customer Summary mode
$grouped = [];
foreach ($rows as $r) {
    $cname = $r['customer_name'] ?: 'Unknown';
    if (!isset($grouped[$cname])) {
        $grouped[$cname] = [
            'customer_name' => $cname,
            'count' => 0,
            'total_amount' => 0.0,
            'amount_paid' => 0.0,
            'balance_due' => 0.0,
            'max_overdue' => 0
        ];
    }
    $grouped[$cname]['count']++;
    $grouped[$cname]['total_amount'] += (float)$r['total_amount'];
    $grouped[$cname]['amount_paid'] += (float)$r['amount_paid'];
    $grouped[$cname]['balance_due'] += (float)$r['balance_due'];
    if ($r['days_overdue'] > $grouped[$cname]['max_overdue']) {
        $grouped[$cname]['max_overdue'] = $r['days_overdue'];
    }
}

echo "\n=== CUSTOMER SUMMARY (EACH CUSTOMER ONCE) ===\n";
print_r($grouped);
