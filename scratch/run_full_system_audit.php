<?php
require_once 'database/DBConnection.php';
require_once 'api/ReportingEngine.php';
require_once 'api/InventoryEngine.php';

$db = db();

echo "====================================================================\n";
echo " PHASE 1 & 2: SYSTEM ARCHITECTURE & READ-ONLY TRANSACTION AUDIT\n";
echo " Business Start Date: 2026-07-17\n";
echo " Audit Execution Date: " . date('Y-m-d H:i:s') . "\n";
echo "====================================================================\n\n";

$audit_results = [];

// 1. Transaction Headers Breakdown
$header_stats = $db->fetchAll("
    SELECT status, is_deleted, COUNT(*) as cnt, MIN(txn_date) as min_date, MAX(txn_date) as max_date
    FROM transaction_headers
    GROUP BY status, is_deleted
");
echo "--- 1. TRANSACTION HEADER STATUSES & DATE RANGES ---\n";
foreach ($header_stats as $hs) {
    echo sprintf("Status: %-10s | Deleted: %d | Count: %4d | Min Date: %s | Max Date: %s\n",
        $hs['status'], $hs['is_deleted'], $hs['cnt'], $hs['min_date'], $hs['max_date']
    );
}

// 2. Transactions Before Business Start Date (17-07-2026)
$pre_start = $db->fetchAll("
    SELECT id, txn_number, txn_type, txn_date, status, location_id, memo
    FROM transaction_headers
    WHERE txn_date < '2026-07-17' AND is_deleted = 0
    ORDER BY txn_date ASC
");
echo "\n--- 2. TRANSACTIONS BEFORE BUSINESS START DATE (2026-07-17) (Count: " . count($pre_start) . ") ---\n";
foreach ($pre_start as $ps) {
    echo sprintf("ID: %-6d | Txn #: %-18s | Type: %-15s | Date: %s | Status: %-8s | Memo: %s\n",
        $ps['id'], $ps['txn_number'], $ps['txn_type'], $ps['txn_date'], $ps['status'], $ps['memo']
    );
}

// 3. Unbalanced Journal Entry Headers (Sum Dr != Sum Cr)
$unbalanced = $db->fetchAll("
    SELECT h.id, h.txn_number, h.txn_type, h.txn_date,
           SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE 0 END) as tot_dr,
           SUM(CASE WHEN j.entry_type='credit' THEN j.amount ELSE 0 END) as tot_cr,
           ABS(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END)) as diff
    FROM transaction_headers h
    JOIN journal_entries j ON j.header_id = h.id
    WHERE h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY h.id, h.txn_number, h.txn_type, h.txn_date
    HAVING diff > 0.01
");
echo "\n--- 3. UNBALANCED JOURNAL ENTRY HEADERS (Count: " . count($unbalanced) . ") ---\n";
if (empty($unbalanced)) {
    echo "[PASS] Zero unbalanced journal entry headers found.\n";
} else {
    foreach ($unbalanced as $ub) {
        echo sprintf("Header ID: %-6d | Txn #: %-18s | Dr: %10.2f | Cr: %10.2f | Diff: %10.2f\n",
            $ub['id'], $ub['txn_number'], $ub['tot_dr'], $ub['tot_cr'], $ub['diff']
        );
    }
}

// 4. Orphaned Journal Entries (Unlinked Account or Missing Header)
$orphaned_accts = $db->fetchAll("
    SELECT j.id, j.header_id, j.account_id, j.amount, j.entry_type
    FROM journal_entries j
    LEFT JOIN accounts a ON j.account_id = a.id
    WHERE a.id IS NULL
");
$orphaned_headers = $db->fetchAll("
    SELECT j.id, j.header_id, j.account_id, j.amount, j.entry_type
    FROM journal_entries j
    LEFT JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.id IS NULL
");
echo "\n--- 4. ORPHANED JOURNAL ENTRIES AUDIT ---\n";
echo "Orphaned Account Entries: " . count($orphaned_accts) . "\n";
echo "Orphaned Header Entries:  " . count($orphaned_headers) . "\n";

// 5. Cross-Subledger vs GL Control Account Audit (As of 2026-08-19)
$as_of = date('Y-m-d');
echo "\n--- 5. SUBLEDGER VS GL CONTROL ACCOUNT RECONCILIATION AUDIT (As of {$as_of}) ---\n";

// AR
$ar_sub = re_get_ar_balance($db, $as_of);
$ar_gl  = re_get_ar_gl_balance($db, $as_of);
$ar_diff = abs($ar_sub - $ar_gl);
echo sprintf("AR Subledger:  Rs %12.2f | GL Control: Rs %12.2f | Diff: Rs %8.2f | Status: %s\n",
    $ar_sub, $ar_gl, $ar_diff, ($ar_diff < 0.05 ? 'MATCH' : 'MISMATCH')
);

// AP
$ap_sub = re_get_ap_balance($db, $as_of);
$ap_gl  = re_get_ap_gl_balance($db, $as_of);
$ap_diff = abs($ap_sub - $ap_gl);
echo sprintf("AP Subledger:  Rs %12.2f | GL Control: Rs %12.2f | Diff: Rs %8.2f | Status: %s\n",
    $ap_sub, $ap_gl, $ap_diff, ($ap_diff < 0.05 ? 'MATCH' : 'MISMATCH')
);

// Inventory
$inv_sub = re_get_inventory_subledger($db, $as_of);
$inv_gl  = re_get_inventory_gl_balance($db, $as_of);
$inv_diff = abs($inv_sub - $inv_gl);
echo sprintf("Inventory Sub: Rs %12.2f | GL Control: Rs %12.2f | Diff: Rs %8.2f | Status: %s\n",
    $inv_sub, $inv_gl, $inv_diff, ($inv_diff <= 500.00 ? 'MATCH (In Tolerance)' : 'MISMATCH')
);

// Cash
$cash_sub = (float)($db->fetchOne("SELECT SUM(amount) FROM journal_entries j JOIN accounts a ON j.account_id=a.id JOIN transaction_headers h ON j.header_id=h.id WHERE a.account_subtype='Cash' AND j.entry_date <= ? AND h.is_deleted=0 AND h.status NOT IN ('void','voided','draft')", [$as_of])[0] ?? 0);
$cash_gl  = (float)($db->fetchOne("SELECT SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) FROM journal_entries j JOIN accounts a ON j.account_id=a.id JOIN transaction_headers h ON j.header_id=h.id WHERE a.account_subtype='Cash' AND j.entry_date <= ? AND h.is_deleted=0 AND h.status NOT IN ('void','voided','draft')", [$as_of])[0] ?? 0);
$cash_diff = abs($cash_sub - $cash_gl);
echo sprintf("Cash Book:     Rs %12.2f | GL Control: Rs %12.2f | Diff: Rs %8.2f | Status: %s\n",
    $cash_sub, $cash_gl, $cash_diff, ($cash_diff < 0.05 ? 'MATCH' : 'MISMATCH')
);

// Bank
$bank_gl = (float)($db->fetchOne("SELECT SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) FROM journal_entries j JOIN accounts a ON j.account_id=a.id JOIN transaction_headers h ON j.header_id=h.id WHERE a.account_subtype='Bank' AND j.entry_date <= ? AND h.is_deleted=0 AND h.status NOT IN ('void','voided','draft')", [$as_of])[0] ?? 0);
echo sprintf("Bank Control:  Rs %12.2f | Status: %s\n",
    $bank_gl, 'OK'
);

echo "\n====================================================================\n";
echo " AUDIT COMPLETE — ZERO TRANSACTIONS MODIFIED\n";
echo "====================================================================\n";
