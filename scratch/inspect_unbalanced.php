<?php
require_once 'database/DBConnection.php';
$db = db();

$headers = ['184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'bd96863a-ad0f-4bc7-8d87-174f5e20dbe8'];

foreach ($headers as $hid) {
    $h = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$hid]);
    echo "=== TRANSACTION HEADER ===\n";
    echo "ID: {$h['id']}\n";
    echo "Num: {$h['txn_number']}\n";
    echo "Type: {$h['txn_type']}\n";
    echo "Date: {$h['txn_date']}\n";
    echo "Status: {$h['status']}\n";
    echo "Memo: {$h['memo']}\n";
    echo "Net Amt: {$h['net_amount']}\n\n";

    echo "--- JOURNAL ENTRIES ---\n";
    $entries = $db->fetchAll("
        SELECT j.*, a.account_code, a.account_name, a.account_type
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        WHERE j.header_id = ?
    ", [$hid]);

    $sum_dr = 0;
    $sum_cr = 0;
    foreach ($entries as $e) {
        $amt = (float)$e['amount'];
        if ($e['entry_type'] === 'debit') {
            $sum_dr += $amt;
            echo "  [DR] Code: {$e['account_code']} | Name: {$e['account_name']} | Amt: {$amt} | Memo: {$e['memo']}\n";
        } else {
            $sum_cr += $amt;
            echo "  [CR] Code: {$e['account_code']} | Name: {$e['account_name']} | Amt: {$amt} | Memo: {$e['memo']}\n";
        }
    }
    echo "  TOTAL DEBITS:  $sum_dr\n";
    echo "  TOTAL CREDITS: $sum_cr\n";
    echo "  DISCREPANCY:   " . ($sum_dr - $sum_cr) . "\n\n";
}
