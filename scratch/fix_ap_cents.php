<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

$pdo->beginTransaction();

// 1. VI-00002 (ID 147)
$db->execute("UPDATE journal_lines jl JOIN journal_entries je ON jl.je_id = je.je_id SET jl.credit = 17700.00 WHERE je.transaction_id = 147 AND jl.account_id = 12");
$db->execute("UPDATE journal_lines jl JOIN journal_entries je ON jl.je_id = je.je_id SET jl.debit = jl.debit - 0.12 WHERE je.transaction_id = 147 AND jl.account_id = 7 LIMIT 1");

// 2. VI-00004 (ID 213)
$db->execute("UPDATE journal_lines jl JOIN journal_entries je ON jl.je_id = je.je_id SET jl.credit = 7300.00 WHERE je.transaction_id = 213 AND jl.account_id = 12");
$db->execute("UPDATE journal_lines jl JOIN journal_entries je ON jl.je_id = je.je_id SET jl.debit = jl.debit - 0.08 WHERE je.transaction_id = 213 AND jl.account_id = 7 LIMIT 1");

// 3. VI-GOKA-00027 (ID 138537)
$db->execute("UPDATE journal_lines jl JOIN journal_entries je ON jl.je_id = je.je_id SET jl.credit = 12500.00 WHERE je.transaction_id = 138537 AND jl.account_id = 12");
$db->execute("UPDATE journal_lines jl JOIN journal_entries je ON jl.je_id = je.je_id SET jl.debit = jl.debit - 0.16 WHERE je.transaction_id = 138537 AND jl.account_id = 7 LIMIT 1");

$pdo->commit();
echo "Successfully rounded AP cent residuals!\n";
