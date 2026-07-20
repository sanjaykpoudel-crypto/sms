<?php
require_once 'database/DBConnection.php';
$db = db();
$txs = $db->fetchAll("
    SELECT id, txn_number, txn_type, txn_date, net_amount, status, memo
    FROM transaction_headers
    WHERE txn_date BETWEEN '2026-07-16' AND '2026-07-18' AND is_deleted = 0
    ORDER BY txn_date, txn_number
");

foreach ($txs as $t) {
    echo "Date: {$t['txn_date']} | Num: {$t['txn_number']} | Type: {$t['txn_type']} | Net Amt: {$t['net_amount']} | Status: {$t['status']} | Memo: {$t['memo']}\n";
}
