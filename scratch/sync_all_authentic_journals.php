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

$stmtL = $pdo->prepare("INSERT INTO journal_lines (jl_id, je_id, account_id, debit, credit, entity_type, entity_id, location_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");

$stmtL->execute([59, $je_id_174, 6, 1550.00, 0.00, 'CUSTOMER', 7]);
$stmtL->execute([117, $je_id_174, 12, 0.00, 22825.00, 'VENDOR', 5]);
$stmtL->execute([251, $je_id_174, 38, 0.00, 106190.00, 'NONE', null]);
$stmtL->execute([438, $je_id_174, 6, 715.00, 0.00, 'CUSTOMER', 1]);
$stmtL->execute([469, $je_id_174, 6, 2900.00, 0.00, 'CUSTOMER', 21]);
$stmtL->execute([538, $je_id_174, 6, 190000.00, 0.00, 'CUSTOMER', 4]);
$stmtL->execute([587, $je_id_174, 6, 53775.00, 0.00, 'CUSTOMER', 20]);
$stmtL->execute([636, $je_id_174, 12, 0.00, 300000.00, 'VENDOR', 7]);
$stmtL->execute([697, $je_id_174, 6, 5000.00, 0.00, 'CUSTOMER', 16]);
$stmtL->execute([846, $je_id_174, 6, 167775.00, 0.00, 'CUSTOMER', 9]);
$stmtL->execute([882, $je_id_174, 12, 7300.00, 0.00, 'VENDOR', 5]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 429015.00, memo = 'Opening receivable and payable' WHERE id = ?")->execute([$id]);

echo "[RESTORED] JV-00002 (ID: 174) with 11 authentic lines totaling Rs. 429,015.00\n";

// 2. Restore OPENING-BALANCES (ID: 236)
$id = 236;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-17 00:00:00', 'JOURNAL', 'System Opening Balances', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_236 = $pdo->lastInsertId();
$stmtL->execute([97, $je_id_236, 3, 357443.00, 0.00, 'NONE', null]);
$stmtL->execute([620, $je_id_236, 2, 17228.00, 0.00, 'NONE', null]);
$stmtL->execute([254, $je_id_236, 38, 0.00, 374671.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 374671.00, memo = 'System Opening Balances' WHERE id = ?")->execute([$id]);
echo "[RESTORED] OPENING-BALANCES (ID: 236) with 3 authentic lines totaling Rs. 374,671.00\n";

// 3. Restore JV-GOKA-00008 (ID: 80)
$id = 80;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-02 00:00:00', 'JOURNAL', 'Opening Stock & Valuation Balance', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id_80 = $pdo->lastInsertId();
$stmtL->execute([118, $je_id_80, 5, 247453.66, 0.00, 'NONE', null]);
$stmtL->execute([173, $je_id_80, 3, 1035.71, 0.00, 'NONE', null]);
$stmtL->execute([290, $je_id_80, 2, 27640.00, 0.00, 'NONE', null]);
$stmtL->execute([106, $je_id_80, 4, 0.00, 1530.59, 'NONE', null]);
$stmtL->execute([108, $je_id_80, 3, 0.00, 244822.21, 'NONE', null]);
$stmtL->execute([553, $je_id_80, 9, 0.00, 151.57, 'NONE', null]);
$stmtL->execute([806, $je_id_80, 2, 0.00, 29625.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 276129.37 WHERE id = ?")->execute([$id]);
echo "[RESTORED] JV-GOKA-00008 (ID: 80) with 7 authentic lines totaling Rs. 276,129.37\n";

// 4. Restore JV-00005 (ID: 99)
$id = 99;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-31 00:00:00', 'JOURNAL', 'Salary got and invested to bring mustang, highlander, gorkha, mustang', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_99 = $pdo->lastInsertId();
$stmtL->execute([109, $je_id_99, 3, 118000.00, 0.00, 'NONE', null]);
$stmtL->execute([456, $je_id_99, 21, 0.00, 118000.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 118000.00 WHERE id = ?")->execute([$id]);
echo "[RESTORED] JV-00005 (ID: 99) with 2 authentic lines totaling Rs. 118,000.00\n";

// 5. Restore JV-00004 (ID: 122)
$id = 122;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-24 00:00:00', 'JOURNAL', 'recharge done', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_122 = $pdo->lastInsertId();
$stmtL->execute([464, $je_id_122, 3, 2.25, 0.00, 'NONE', null]);
$stmtL->execute([606, $je_id_122, 2, 100.00, 0.00, 'NONE', null]);
$stmtL->execute([691, $je_id_122, 24, 0.00, 2.25, 'NONE', null]);
$stmtL->execute([703, $je_id_122, 3, 0.00, 100.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 102.25 WHERE id = ?")->execute([$id]);
echo "[RESTORED] JV-00004 (ID: 122) with 4 authentic lines totaling Rs. 102.25\n";

// 6. Restore JV-GOKA-00010 (ID: 140)
$id = 140;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-31 00:00:00', 'JOURNAL', 'Rent and Electricity Expenses 11000 rent and 1000 Electricity', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_140 = $pdo->lastInsertId();
$stmtL->execute([85, $je_id_140, 30, 12000.00, 0.00, 'NONE', null]);
$stmtL->execute([841, $je_id_140, 2, 0.00, 12000.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 12000.00 WHERE id = ?")->execute([$id]);
echo "[RESTORED] JV-GOKA-00010 (ID: 140) with 2 authentic lines totaling Rs. 12,000.00\n";

// 7. Restore JV-GOKA-00011 (ID: 108)
$id = 108;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-06 00:00:00', 'JOURNAL', 'Adjustment of cash and bank', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id_108 = $pdo->lastInsertId();
$stmtL->execute([122, $je_id_108, 2, 1860.00, 0.00, 'NONE', null]);
$stmtL->execute([780, $je_id_108, 3, 4946.00, 0.00, 'NONE', null]);
$stmtL->execute([296, $je_id_108, 36, 0.00, 6806.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 6806.00 WHERE id = ?")->execute([$id]);
echo "[RESTORED] JV-GOKA-00011 (ID: 108) with 3 authentic lines totaling Rs. 6,806.00\n";

// 8. Restore JV-GOKA-00009 (ID: 114)
$id = 114;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-04 00:00:00', 'JOURNAL', 'for fixed deposit transfer', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id_114 = $pdo->lastInsertId();
$stmtL->execute([699, $je_id_114, 5, 3000.00, 0.00, 'NONE', null]);
$stmtL->execute([442, $je_id_114, 3, 0.00, 3000.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 3000.00 WHERE id = ?")->execute([$id]);
echo "[RESTORED] JV-GOKA-00009 (ID: 114) with 2 authentic lines totaling Rs. 3,000.00\n";

// 9. Restore JV-GOKA-00012 (ID: 177)
$id = 177;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-08-07 00:00:00', 'JOURNAL', 'For fridge bought', 'POSTED', 8)");
$stmtH->execute([$id]);
$je_id_177 = $pdo->lastInsertId();
$stmtL->execute([449, $je_id_177, 9, 7700.00, 0.00, 'NONE', null]);
$stmtL->execute([818, $je_id_177, 2, 0.00, 7700.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 7700.00 WHERE id = ?")->execute([$id]);
echo "[RESTORED] JV-GOKA-00012 (ID: 177) with 2 authentic lines totaling Rs. 7,700.00\n";

// 10. Restore JV-CA-OPEN-01164523 (ID: 163)
$id = 163;
$db->execute("DELETE FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = ?)", [$id]);
$db->execute("DELETE FROM journal_entries WHERE transaction_id = ?", [$id]);
$stmtH = $pdo->prepare("INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id) VALUES (?, '2026-07-16 00:00:00', 'JOURNAL', 'CA Audit Adjustment: Clear Opening Balance Suspense Account to Retained Earnings', 'POSTED', 7)");
$stmtH->execute([$id]);
$je_id_163 = $pdo->lastInsertId();
$stmtL->execute([310, $je_id_163, 38, 106190.00, 0.00, 'NONE', null]);
$stmtL->execute([216, $je_id_163, 22, 0.00, 106190.00, 'NONE', null]);
$pdo->prepare("UPDATE transaction_headers SET net_amount = 106190.00 WHERE id = ?")->execute([$id]);
echo "[RESTORED] JV-CA-OPEN-01164523 (ID: 163) with 2 authentic lines totaling Rs. 106,190.00\n";

$pdo->commit();

echo "\nALL JOURNALS SYNCHRONIZED AND COMMITTED SUCCESSFULLY!\n";
