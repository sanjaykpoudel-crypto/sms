<?php
require_once 'database/DBConnection.php';
$db = db();

echo "--- GENERAL LEDGER QUERY RESULTS FOR CM-0001 ---\n";
$sql = "
    SELECT 
        j.entry_date,
        h.txn_number,
        h.txn_type,
        a.account_name,
        j.amount,
        h.is_deleted,
        h.status
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    JOIN accounts a ON j.account_id = a.id
    WHERE h.txn_number = 'CM-0001'
";
print_r($db->fetchAll($sql));
