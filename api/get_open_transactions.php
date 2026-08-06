<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once __DIR__ . '/../database/DBConnection.php';

$party_id   = $_GET['party_id']   ?? null;
$party_type = $_GET['party_type'] ?? 'customer';
$payment_id = $_GET['payment_id'] ?? '';

if (!$party_id) {
    echo json_encode([]);
    exit;
}

$db = db();

if ($party_type === 'customer') {
    $where = $payment_id ? "(tl.id IS NOT NULL OR ci.balance_due > 0.01)" : "ci.balance_due > 0.01";

    // 1. Customer Invoices
    $invoices = $db->fetchAll("
        SELECT 'Invoice' as txn_type, 'Invoice' as txn_type_group, h.txn_number, h.txn_date,
        ci.total_amount,
        (ci.balance_due + COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0)) as balance_due,
        ci.header_id as id,
        COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0) as applied_amount,
        '' as line_id,
        NULL as memo
        FROM customer_invoices ci
        JOIN transaction_headers h ON ci.header_id = h.id
        LEFT JOIN transaction_links tl ON tl.child_id = ci.header_id AND tl.parent_id = ?
        WHERE ci.customer_id = ? AND ($where) AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ORDER BY h.txn_date ASC
    ", [$payment_id, $party_id]);

    // 2. Tagged Journal Entries for Customer
    $journals = $db->fetchAll("
        SELECT 'Journal' as txn_type,
               IF(j.memo LIKE '%Opening Balance%' OR j.memo LIKE '%opening balance%', 'Opening Balance', 'Journal') as txn_type_group,
               h.txn_number as txn_number,
               h.txn_date,
               IF(j.entry_type = 'debit', j.amount, -j.amount) as total_amount,
               (
                   IF(j.entry_type = 'debit', j.amount, -j.amount)
                   - COALESCE((
                       SELECT SUM(CAST(SUBSTRING_INDEX(tl_all.link_type, ':', -1) AS DECIMAL(10,2)))
                       FROM transaction_links tl_all
                       JOIN transaction_headers ph ON tl_all.parent_id = ph.id
                       JOIN payments p ON ph.id = p.header_id
                       WHERE (tl_all.child_id = j.id OR tl_all.child_id = h.id)
                         AND (
                             tl_all.link_type LIKE CONCAT('payment:', j.id, ':%')
                             OR (
                                 tl_all.link_type NOT LIKE 'payment:%:%'
                                 AND tl_all.link_type LIKE 'payment:%'
                                 AND ABS(CAST(SUBSTRING_INDEX(tl_all.link_type, ':', -1) AS DECIMAL(10,2)) - IF(j.entry_type = 'debit', j.amount, -j.amount)) < 0.01
                             )
                         )
                         AND p.customer_id = ?
                         AND (ph.id != ? OR ? = '')
                         AND ph.is_deleted = 0 AND ph.status NOT IN ('void', 'voided', 'draft')
                   ), 0.00)
               ) as balance_due,
               h.id,
               j.id as line_id,
               COALESCE(MAX(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0) as applied_amount,
               j.memo
        FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        LEFT JOIN transaction_links tl ON (tl.child_id = j.id OR tl.child_id = h.id)
            AND (
                tl.link_type LIKE CONCAT('payment:', j.id, ':%')
                OR (
                    tl.link_type NOT LIKE 'payment:%:%'
                    AND tl.link_type LIKE 'payment:%'
                    AND ABS(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)) - IF(j.entry_type = 'debit', j.amount, -j.amount)) < 0.01
                )
            )
            AND tl.parent_id = ?
        WHERE j.party_id = ?
          AND (j.party_type = 'customer' OR j.party_type IS NULL)
          AND h.is_deleted = 0
          AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.txn_type IN ('Journal', 'journal_entry')
        GROUP BY j.id, h.id, h.txn_number, h.txn_date, j.memo, j.entry_type, j.amount
        HAVING ABS(balance_due) > 0.01 OR ABS(applied_amount) > 0
        ORDER BY h.txn_date ASC
    ", [$party_id, $payment_id, $payment_id, $payment_id, $party_id]);

    // 3. Open Credit Memos for Customer
    $credit_memos = $db->fetchAll("
        SELECT 'Credit Memo' as txn_type, 'Credit Memo' as txn_type_group, h.txn_number, h.txn_date,
               (-1 * cm.total_amount) as total_amount,
               (-1 * (cm.remaining_credit + COALESCE(ABS(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0))) as balance_due,
               cm.header_id as id,
               COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0) as applied_amount,
               '' as line_id,
               NULL as memo
        FROM credit_memos cm
        JOIN transaction_headers h ON cm.header_id = h.id
        LEFT JOIN transaction_links tl ON tl.child_id = cm.header_id AND tl.parent_id = ?
        WHERE cm.customer_id = ? AND (cm.remaining_credit > 0.01 OR tl.id IS NOT NULL) AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ORDER BY h.txn_date ASC
    ", [$payment_id, $party_id]);

    $results = array_merge($invoices, $journals, $credit_memos);
} else {
    $where = $payment_id ? "(tl.id IS NOT NULL OR vb.balance_due > 0.01)" : "vb.balance_due > 0.01";

    // 1. Vendor Bills
    $bills = $db->fetchAll("
        SELECT 'Bill' as txn_type, 'Bill' as txn_type_group, h.txn_number, h.txn_date, vb.total_amount,
        (vb.balance_due + COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0)) as balance_due,
        vb.header_id as id,
        COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0) as applied_amount,
        '' as line_id,
        NULL as memo
        FROM vendor_bills vb
        JOIN transaction_headers h ON vb.header_id = h.id
        LEFT JOIN transaction_links tl ON tl.child_id = vb.header_id AND tl.parent_id = ?
        WHERE vb.vendor_id = ? AND ($where) AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ORDER BY h.txn_date ASC
    ", [$payment_id, $party_id]);

    // 2. Tagged Journal Entries for Vendor
    $journals = $db->fetchAll("
        SELECT 'Journal' as txn_type,
               IF(j.memo LIKE '%Opening Balance%' OR j.memo LIKE '%opening balance%', 'Opening Balance', 'Journal') as txn_type_group,
               h.txn_number as txn_number,
               h.txn_date,
               IF(j.entry_type = 'credit', j.amount, -j.amount) as total_amount,
               (
                   IF(j.entry_type = 'credit', j.amount, -j.amount)
                   - COALESCE((
                       SELECT SUM(CAST(SUBSTRING_INDEX(tl_all.link_type, ':', -1) AS DECIMAL(10,2)))
                       FROM transaction_links tl_all
                       JOIN transaction_headers ph ON tl_all.parent_id = ph.id
                       JOIN payments p ON ph.id = p.header_id
                       WHERE (tl_all.child_id = j.id OR tl_all.child_id = h.id)
                         AND (
                             tl_all.link_type LIKE CONCAT('payment:', j.id, ':%')
                             OR (
                                 tl_all.link_type NOT LIKE 'payment:%:%'
                                 AND tl_all.link_type LIKE 'payment:%'
                                 AND ABS(CAST(SUBSTRING_INDEX(tl_all.link_type, ':', -1) AS DECIMAL(10,2)) - IF(j.entry_type = 'credit', j.amount, -j.amount)) < 0.01
                             )
                         )
                         AND p.vendor_id = ?
                         AND (ph.id != ? OR ? = '')
                         AND ph.is_deleted = 0 AND ph.status NOT IN ('void', 'voided', 'draft')
                   ), 0.00)
               ) as balance_due,
               h.id,
               j.id as line_id,
               COALESCE(MAX(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0) as applied_amount,
               j.memo
        FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        LEFT JOIN transaction_links tl ON (tl.child_id = j.id OR tl.child_id = h.id)
            AND (
                tl.link_type LIKE CONCAT('payment:', j.id, ':%')
                OR (
                    tl.link_type NOT LIKE 'payment:%:%'
                    AND tl.link_type LIKE 'payment:%'
                    AND ABS(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)) - IF(j.entry_type = 'credit', j.amount, -j.amount)) < 0.01
                )
            )
            AND tl.parent_id = ?
        WHERE j.party_id = ?
          AND (j.party_type = 'vendor' OR j.party_type IS NULL)
          AND h.is_deleted = 0
          AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.txn_type IN ('Journal', 'journal_entry')
        GROUP BY j.id, h.id, h.txn_number, h.txn_date, j.memo, j.entry_type, j.amount
        HAVING ABS(balance_due) > 0.01 OR ABS(applied_amount) > 0
        ORDER BY h.txn_date ASC
    ", [$party_id, $payment_id, $payment_id, $payment_id, $party_id]);

    // 3. Open Vendor Credits for Vendor
    $vendor_credits = $db->fetchAll("
        SELECT 'Vendor Credit' as txn_type, 'Vendor Credit' as txn_type_group, h.txn_number, h.txn_date,
               (-1 * vc.total_amount) as total_amount,
               (-1 * (vc.remaining_credit + COALESCE(ABS(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0))) as balance_due,
               vc.header_id as id,
               COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), 0) as applied_amount,
               '' as line_id,
               NULL as memo
        FROM vendor_credits vc
        JOIN transaction_headers h ON vc.header_id = h.id
        LEFT JOIN transaction_links tl ON tl.child_id = vc.header_id AND tl.parent_id = ?
        WHERE vc.vendor_id = ? AND (vc.remaining_credit > 0.01 OR tl.id IS NOT NULL) AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ORDER BY h.txn_date ASC
    ", [$payment_id, $party_id]);

    $results = array_merge($bills, $journals, $vendor_credits);
}

// Sort all combined transactions by date (Oldest first at the top)
usort($results, function($a, $b) {
    $t1 = strtotime($a['txn_date'] ?? '1970-01-01');
    $t2 = strtotime($b['txn_date'] ?? '1970-01-01');
    if ($t1 === $t2) {
        return strcmp($a['txn_number'] ?? '', $b['txn_number'] ?? '');
    }
    return $t1 - $t2;
});

echo json_encode(array_values($results));
