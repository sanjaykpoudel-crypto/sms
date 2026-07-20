<?php
require_once 'database/DBConnection.php';
$db = db();

// Let's count how many we are going to update
$cogs_count = $db->fetchOne("SELECT COUNT(*) as count FROM journal_entries WHERE account_id = 'acc-1100' AND memo LIKE '%COGS%'")['count'];
$disc_count = $db->fetchOne("SELECT COUNT(*) as count FROM journal_entries WHERE account_id = 'acc-1100' AND memo LIKE '%Discount%'")['count'];

echo "Found {$cogs_count} COGS entries and {$disc_count} Discount entries to correct.\n";

if ($cogs_count > 0) {
    $db->execute("UPDATE journal_entries SET account_id = 'acc-5100' WHERE account_id = 'acc-1100' AND memo LIKE '%COGS%'");
    echo "Updated COGS entries to acc-5100.\n";
}

if ($disc_count > 0) {
    $db->execute("UPDATE journal_entries SET account_id = 'acc-6160' WHERE account_id = 'acc-1100' AND memo LIKE '%Discount%'");
    echo "Updated Discount entries to acc-6160.\n";
}

// Check new GL balances
function get_balances_corrected($db, $as_of) {
    $rows = $db->fetchAll("
        SELECT 
            CASE 
                WHEN a.account_subtype = 'cash' OR a.account_code = '1010' THEN 'cash'
                WHEN a.account_subtype = 'bank' THEN 'bank'
                WHEN a.account_subtype = 'receivable' THEN 'ar'
                WHEN a.account_subtype = 'payable' THEN 'ap'
            END as bt,
            COALESCE(SUM(CASE WHEN h.id IS NOT NULL THEN (CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE -je.amount END) ELSE 0 END), 0) as bal
        FROM accounts a
        LEFT JOIN journal_entries je ON je.account_id = a.id AND je.entry_date <= ?
        LEFT JOIN transaction_headers h ON je.header_id = h.id AND h.is_deleted = 0 AND h.status != 'voided'
        WHERE (a.account_subtype IN ('cash','bank','receivable','payable') OR a.account_code='1010')
          AND a.is_active = 1 AND a.is_deleted = 0
        GROUP BY bt HAVING bt IS NOT NULL
    ", [$as_of]);
    $r = ['cash'=>0,'bank'=>0,'ar'=>0,'ap'=>0];
    foreach ($rows as $row) { $r[$row['bt']] = (float)$row['bal']; }
    $r['ap'] = abs($r['ap']);
    return $r;
}

$today = date('Y-m-d');
echo "\nNew GL balances: \n";
print_r(get_balances_corrected($db, $today));
