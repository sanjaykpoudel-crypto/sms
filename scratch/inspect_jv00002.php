<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- TRANSACTION HEADER FOR JV-00002 ---\n";
$hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number = 'JV-00002'");
print_r($hdr);

if ($hdr) {
    $hdr_id = $hdr['id'];
    echo "\n--- JOURNAL ENTRIES FOR JV-00002 ---\n";
    $lines = $db->fetchAll("SELECT j.*, a.account_name 
                            FROM journal_entries j 
                            LEFT JOIN accounts a ON j.account_id = a.id 
                            WHERE j.header_id = ?", [$hdr_id]);
    print_r($lines);

    echo "\n--- TRANSACTION LINKS WHERE CHILD IS JV-00002 ---\n";
    $links = $db->fetchAll("SELECT tl.*, ph.txn_number, ph.txn_type, ph.status as pay_status, ph.is_deleted as pay_deleted 
                            FROM transaction_links tl 
                            JOIN transaction_headers ph ON tl.parent_id = ph.id 
                            WHERE tl.child_id = ?", [$hdr_id]);
    print_r($links);

    echo "\n--- ALL TRANSACTION LINKS FOR PAYMENTS DELETED OR NOT ---\n";
    $all_links = $db->fetchAll("SELECT tl.*, ph.txn_number, ph.status as pay_status, ph.is_deleted as pay_deleted 
                                FROM transaction_links tl 
                                LEFT JOIN transaction_headers ph ON tl.parent_id = ph.id 
                                WHERE tl.child_id = ?", [$hdr_id]);
    print_r($all_links);

    echo "\n--- PAYMENTS WHERE APPLIED TO JV-00002 ---\n";
    $pay_rows = $db->fetchAll("SELECT p.*, ph.txn_number, ph.status as pay_status, ph.is_deleted as pay_deleted 
                               FROM payments p 
                               JOIN transaction_headers ph ON p.header_id = ph.id 
                               WHERE p.applied_to_txn_id = ?", [$hdr_id]);
    print_r($pay_rows);
}
