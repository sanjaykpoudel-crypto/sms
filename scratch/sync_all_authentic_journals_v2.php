<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

echo "========================================================\n";
echo " SYNCHRONIZING ALL JOURNALS WITH AUTHENTIC BACKUP LINES\n";
echo "========================================================\n";

$pdo->beginTransaction();

// 1. Restore JV-00002 (ID: 174) with exact 11 lines
$id = 174;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);

$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-01 00:00:00', 'JOURNAL', 'Opening receivable and payable', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_174 = $pdo->lastInsertId();

// Helper to insert line
function insert_line($pdo, $jl_id, $je_id, $acc_id, $dr, $cr, $etype, $eid, $memo = '') {
    if ($jl_id !== null) {
        $pdo->exec("DELETE FROM journal_lines WHERE jl_id = $jl_id");
        $stmt = $pdo->prepare("INSERT INTO journal_lines (jl_id, je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$jl_id, $je_id, $acc_id, $dr, $cr, $etype, $eid]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO journal_lines (je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$je_id, $acc_id, $dr, $cr, $etype, $eid]);
    }
}

// 1. JV-00002 (ID: 174) - 11 Authentic lines
insert_line($pdo, null, $je_id_174, 6, 1550.00, 0.00, 'CUSTOMER', 7, 'previous amount to take');
insert_line($pdo, 117,  $je_id_174, 12, 0.00, 22825.00, 'VENDOR', 5, 'friendship to pay');
insert_line($pdo, null, $je_id_174, 38, 0.00, 106190.00, 'NONE', null, 'parking account');
insert_line($pdo, null, $je_id_174, 6, 715.00, 0.00, 'CUSTOMER', 1, 'bhada');
insert_line($pdo, 469,  $je_id_174, 6, 2900.00, 0.00, 'CUSTOMER', 21, 'previous amount to take');
insert_line($pdo, null, $je_id_174, 6, 190000.00, 0.00, 'CUSTOMER', 4, 'bluebird');
insert_line($pdo, 587,  $je_id_174, 6, 53775.00, 0.00, 'CUSTOMER', 20, 'prabesh khanal');
insert_line($pdo, null, $je_id_174, 12, 0.00, 300000.00, 'VENDOR', 7, 'manish to pay');
insert_line($pdo, null, $je_id_174, 6, 5000.00, 0.00, 'CUSTOMER', 16, 'chiyapul');
insert_line($pdo, null, $je_id_174, 6, 167775.00, 0.00, 'CUSTOMER', 9, 'previous opening to get amount');
insert_line($pdo, 882,  $je_id_174, 12, 7300.00, 0.00, 'VENDOR', 5, 'friendship to pay');
$pdo->prepare("UPDATE transaction_headers SET net_amount = 429015.00, memo = 'Opening receivable and payable' WHERE id = 174")->execute();
echo "[SUCCESS] JV-00002 (ID: 174) restored with all 11 customer & vendor lines!\n";

// 2. OPENING-BALANCES (ID: 236)
$id = 236;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-17 00:00:00', 'JOURNAL', 'System Opening Balances', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_236 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_236, 3, 357443.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_236, 2, 17228.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_236, 38, 0.00, 374671.00, 'NONE', null);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 374671.00, memo = 'System Opening Balances' WHERE id = 236")->execute();
echo "[SUCCESS] OPENING-BALANCES (ID: 236) restored!\n";

// 3. JV-GOKA-00008 (ID: 80)
$id = 80;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-02 00:00:00', 'JOURNAL', 'Opening Stock & Valuation Balance', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id_80 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_80, 5, 247453.66, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_80, 3, 1035.71, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_80, 2, 27640.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_80, 4, 0.00, 1530.59, 'NONE', null);
insert_line($pdo, null, $je_id_80, 3, 0.00, 244822.21, 'NONE', null);
insert_line($pdo, null, $je_id_80, 9, 0.00, 151.57, 'NONE', null);
insert_line($pdo, null, $je_id_80, 2, 0.00, 29625.00, 'NONE', null);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 276129.37 WHERE id = 80")->execute();
echo "[SUCCESS] JV-GOKA-00008 (ID: 80) restored!\n";

// 4. JV-00005 (ID: 99)
$id = 99;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-31 00:00:00', 'JOURNAL', 'Salary got and invested to bring mustang, highlander, gorkha, mustang', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_99 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_99, 3, 118000.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_99, 21, 0.00, 118000.00, 'NONE', null);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 118000.00 WHERE id = 99")->execute();
echo "[SUCCESS] JV-00005 (ID: 99) restored!\n";

// 5. JV-00004 (ID: 122)
$id = 122;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-24 00:00:00', 'JOURNAL', 'recharge done', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_122 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_122, 3, 2.25, 0.00, 'NONE', null, 'income on khalti');
insert_line($pdo, null, $je_id_122, 2, 100.00, 0.00, 'NONE', null, 'amount received for recharge');
insert_line($pdo, null, $je_id_122, 24, 0.00, 2.25, 'NONE', null, 'bonus on recharge');
insert_line($pdo, null, $je_id_122, 3, 0.00, 100.00, 'NONE', null, 'amount spend for recharge');
$pdo->prepare("UPDATE transaction_headers SET net_amount = 102.25 WHERE id = 122")->execute();
echo "[SUCCESS] JV-00004 (ID: 122) restored!\n";

// 6. JV-GOKA-00010 (ID: 140)
$id = 140;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-31 00:00:00', 'JOURNAL', 'Rent and Electricity Expenses 11000 rent and 1000 Electricity', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_140 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_140, 30, 11000.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_140, 32, 1000.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_140, 2, 0.00, 12000.00, 'NONE', null);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 12000.00 WHERE id = 140")->execute();
echo "[SUCCESS] JV-GOKA-00010 (ID: 140) restored!\n";

// 7. JV-GOKA-00011 (ID: 108)
$id = 108;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-06 00:00:00', 'JOURNAL', 'Adjustment of cash and bank', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id_108 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_108, 2, 1860.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_108, 3, 4946.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_108, 36, 0.00, 6806.00, 'NONE', null);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 6806.00 WHERE id = 108")->execute();
echo "[SUCCESS] JV-GOKA-00011 (ID: 108) restored!\n";

// 8. JV-GOKA-00009 (ID: 114)
$id = 114;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-04 00:00:00', 'JOURNAL', 'for fixed deposit transfer', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id_114 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_114, 5, 3000.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_114, 3, 0.00, 3000.00, 'NONE', null);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 3000.00 WHERE id = 114")->execute();
echo "[SUCCESS] JV-GOKA-00009 (ID: 114) restored!\n";

// 9. JV-GOKA-00012 (ID: 177)
$id = 177;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-07 00:00:00', 'JOURNAL', 'For fridge bought', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id_177 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_177, 8, 7700.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_177, 2, 0.00, 7700.00, 'NONE', null);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 7700.00 WHERE id = 177")->execute();
echo "[SUCCESS] JV-GOKA-00012 (ID: 177) restored!\n";

// 10. JV-CA-OPEN-01164523 (ID: 163)
$id = 163;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-16 00:00:00', 'JOURNAL', 'CA Audit Adjustment: Clear Opening Balance Suspense Account to Retained Earnings', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_163 = $pdo->lastInsertId();
insert_line($pdo, null, $je_id_163, 38, 106190.00, 0.00, 'NONE', null);
insert_line($pdo, null, $je_id_163, 22, 0.00, 106190.00, 'NONE', null);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 106190.00 WHERE id = 163")->execute();
echo "[SUCCESS] JV-CA-OPEN-01164523 (ID: 163) restored!\n";

$pdo->commit();

echo "\nALL AUTHENTIC JOURNALS SYNCHRONIZED AND COMMITTED SUCCESSFULLY!\n";
