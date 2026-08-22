<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
require_once __DIR__ . '/../api/InventoryEngine.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "====================================================================\n";
echo " DEEP SYSTEM DISCOVERY & DATABASE INTEGRITY ANALYSIS\n";
echo " Date: " . date('Y-m-d H:i:s') . "\n";
echo "====================================================================\n\n";

// 1. Transaction Types breakdown
echo "--- 1. TRANSACTION TYPES BREAKDOWN ---\n";
$txn_types = $pdo->query("
    SELECT txn_type, status, is_deleted, COUNT(*) as cnt, SUM(net_amount) as total_net, MIN(txn_date) as min_date, MAX(txn_date) as max_date
    FROM transaction_headers
    GROUP BY txn_type, status, is_deleted
    ORDER BY txn_type, status
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($txn_types as $t) {
    echo sprintf("Type: %-22s | Status: %-8s | Deleted: %d | Count: %4d | Net Sum: %12.2f | Min: %s | Max: %s\n",
        $t['txn_type'], $t['status'], $t['is_deleted'], $t['cnt'], $t['total_net'], $t['min_date'], $t['max_date']
    );
}

// 2. Journal Entry Balance Check (debit == credit per je_id)
echo "\n--- 2. JOURNAL ENTRY BALANCE CHECK (per je_id) ---\n";
$unbalanced_jes = $pdo->query("
    SELECT je.je_id, je.transaction_id, th.txn_number, th.txn_type, th.txn_date,
           SUM(jl.debit) as tot_debit, SUM(jl.credit) as tot_credit,
           ABS(SUM(jl.debit) - SUM(jl.credit)) as diff
    FROM journal_entries je
    JOIN journal_lines jl ON jl.je_id = je.je_id
    LEFT JOIN transaction_headers th ON th.id = je.transaction_id
    WHERE je.status = 'POSTED' AND (th.is_deleted IS NULL OR th.is_deleted = 0)
    GROUP BY je.je_id, je.transaction_id, th.txn_number, th.txn_type, th.txn_date
    HAVING diff > 0.01
")->fetchAll(PDO::FETCH_ASSOC);

echo "Unbalanced Posted Journal Entries Count: " . count($unbalanced_jes) . "\n";
foreach ($unbalanced_jes as $ub) {
    echo sprintf("JE ID: %d | Txn ID: %s | Txn #: %s | Dr: %.2f | Cr: %.2f | Diff: %.2f\n",
        $ub['je_id'], $ub['transaction_id'], $ub['txn_number'], $ub['tot_debit'], $ub['tot_credit'], $ub['diff']
    );
}

// 3. Overall Trial Balance Check
echo "\n--- 3. OVERALL TRIAL BALANCE assertion ---\n";
$tb_tot = $pdo->query("
    SELECT SUM(jl.debit) as total_debit, SUM(jl.credit) as total_credit
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    LEFT JOIN transaction_headers th ON th.id = je.transaction_id
    WHERE je.status = 'POSTED' AND (th.is_deleted IS NULL OR th.is_deleted = 0)
")->fetch(PDO::FETCH_ASSOC);

$tb_diff = abs(($tb_tot['total_debit'] ?? 0) - ($tb_tot['total_credit'] ?? 0));
echo sprintf("Total Debits:  Rs %14.2f\n", $tb_tot['total_debit']);
echo sprintf("Total Credits: Rs %14.2f\n", $tb_tot['total_credit']);
echo sprintf("Difference:    Rs %14.2f | Status: %s\n", $tb_diff, ($tb_diff < 0.05 ? 'PERFECT MATCH' : 'MISMATCH'));

// 4. Subledger Reconciliations
$as_of = date('Y-m-d');
echo "\n--- 4. SUBLEDGER VS GL CONTROL ACCOUNTS RECONCILIATION (As of {$as_of}) ---\n";

$ar_sub = re_get_ar_balance($db, $as_of);
$ar_gl  = re_get_ar_gl_balance($db, $as_of);
echo sprintf("AR Subledger:  Rs %12.2f | GL Control: Rs %12.2f | Diff: Rs %8.2f | Status: %s\n",
    $ar_sub, $ar_gl, abs($ar_sub - $ar_gl), (abs($ar_sub - $ar_gl) < 0.05 ? 'MATCH' : 'MISMATCH')
);

$ap_sub = re_get_ap_balance($db, $as_of);
$ap_gl  = re_get_ap_gl_balance($db, $as_of);
echo sprintf("AP Subledger:  Rs %12.2f | GL Control: Rs %12.2f | Diff: Rs %8.2f | Status: %s\n",
    $ap_sub, $ap_gl, abs($ap_sub - $ap_gl), (abs($ap_sub - $ap_gl) < 0.05 ? 'MATCH' : 'MISMATCH')
);

$inv_sub = re_get_inventory_subledger($db, $as_of);
$inv_gl  = re_get_inventory_gl_balance($db, $as_of);
echo sprintf("Inventory Sub: Rs %12.2f | GL Control: Rs %12.2f | Diff: Rs %8.2f | Status: %s\n",
    $inv_sub, $inv_gl, abs($inv_sub - $inv_gl), (abs($inv_sub - $inv_gl) <= 500 ? 'MATCH (In Tolerance)' : 'MISMATCH')
);

// 5. Orphan Records Audit
echo "\n--- 5. ORPHAN RECORDS AUDIT ---\n";
$orphan_lines = $pdo->query("
    SELECT COUNT(*) FROM transaction_lines tl
    LEFT JOIN transaction_headers th ON tl.header_id = th.id
    WHERE th.id IS NULL
")->fetchColumn();
echo "Transaction Lines without Header: {$orphan_lines}\n";

$orphan_jes = $pdo->query("
    SELECT COUNT(*) FROM journal_entries je
    LEFT JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE je.transaction_id IS NOT NULL AND th.id IS NULL
")->fetchColumn();
echo "Journal Entries with invalid Transaction ID: {$orphan_jes}\n";

$orphan_jls = $pdo->query("
    SELECT COUNT(*) FROM journal_lines jl
    LEFT JOIN journal_entries je ON jl.je_id = je.je_id
    WHERE je.je_id IS NULL
")->fetchColumn();
echo "Journal Lines without Journal Entry header: {$orphan_jls}\n";

$orphan_links = $pdo->query("
    SELECT COUNT(*) FROM transaction_links tl
    LEFT JOIN transaction_headers p ON tl.parent_id = p.id
    LEFT JOIN transaction_headers c ON tl.child_id = c.id
    WHERE p.id IS NULL OR c.id IS NULL
")->fetchColumn();
echo "Transaction Links with missing Parent or Child: {$orphan_links}\n";

// 6. Test Data Candidates Search
echo "\n--- 6. TEST DATA / TEST TRANSACTIONS IDENTIFICATION ---\n";
$test_txns = $pdo->query("
    SELECT id, txn_number, txn_type, txn_date, memo, reference_number, net_amount, status
    FROM transaction_headers
    WHERE memo LIKE '%test%'
       OR memo LIKE '%demo%'
       OR reference_number LIKE '%test%'
       OR reference_number LIKE '%demo%'
       OR txn_number LIKE '%test%'
       OR txn_number LIKE '%demo%'
       OR memo LIKE '%temp%'
    ORDER BY txn_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo "Test-keyword Header Count: " . count($test_txns) . "\n";
foreach ($test_txns as $tt) {
    echo sprintf("ID: %-5d | Txn #: %-20s | Type: %-18s | Date: %s | Net: %10.2f | Memo: %s\n",
        $tt['id'], $tt['txn_number'], $tt['txn_type'], $tt['txn_date'], $tt['net_amount'], $tt['memo']
    );
}

// Check deleted headers
$deleted_txns = $pdo->query("
    SELECT id, txn_number, txn_type, txn_date, memo, net_amount, status
    FROM transaction_headers
    WHERE is_deleted = 1
")->fetchAll(PDO::FETCH_ASSOC);

echo "Deleted Headers Count (Soft-deleted): " . count($deleted_txns) . "\n";
foreach ($deleted_txns as $dt) {
    echo sprintf("ID: %-5d | Txn #: %-20s | Type: %-18s | Date: %s | Net: %10.2f | Status: %s\n",
        $dt['id'], $dt['txn_number'], $dt['txn_type'], $dt['txn_date'], $dt['net_amount'], $dt['status']
    );
}

// 7. Master Data Overview
echo "\n--- 7. MASTER DATA COUNT & STATUS ---\n";
echo "Customers: " . $pdo->query("SELECT COUNT(*) FROM customers WHERE is_deleted = 0")->fetchColumn() . " active, " .
                    $pdo->query("SELECT COUNT(*) FROM customers WHERE is_deleted = 1")->fetchColumn() . " deleted\n";
echo "Vendors:   " . $pdo->query("SELECT COUNT(*) FROM vendors WHERE is_deleted = 0")->fetchColumn() . " active, " .
                    $pdo->query("SELECT COUNT(*) FROM vendors WHERE is_deleted = 1")->fetchColumn() . " deleted\n";
echo "Items:     " . $pdo->query("SELECT COUNT(*) FROM items WHERE is_deleted = 0")->fetchColumn() . " active, " .
                    $pdo->query("SELECT COUNT(*) FROM items WHERE is_deleted = 1")->fetchColumn() . " deleted\n";
echo "Accounts:  " . $pdo->query("SELECT COUNT(*) FROM accounts WHERE is_deleted = 0")->fetchColumn() . " active, " .
                    $pdo->query("SELECT COUNT(*) FROM accounts WHERE is_deleted = 1")->fetchColumn() . " deleted\n";
echo "Locations: " . $pdo->query("SELECT COUNT(*) FROM locations")->fetchColumn() . "\n";
echo "Users:     " . $pdo->query("SELECT COUNT(*) FROM users WHERE is_deleted = 0")->fetchColumn() . "\n";

echo "\n====================================================================\n";
echo " SYSTEM DISCOVERY COMPLETE\n";
echo "====================================================================\n";
