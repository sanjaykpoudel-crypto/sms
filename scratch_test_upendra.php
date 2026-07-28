<?php
require_once 'database/DBConnection.php';
$db = db();
$cid = 'c666d6db-baa6-4692-810d-e23509f4e1c5';

echo "=== INVOICES FOR UPENDRA SAHA ===\n";
print_r($db->fetchAll("SELECT ci.*, th.txn_number, th.txn_date, th.status, th.is_deleted FROM customer_invoices ci JOIN transaction_headers th ON ci.header_id = th.id WHERE ci.customer_id = ?", [$cid]));

echo "\n=== PAYMENTS FOR UPENDRA SAHA ===\n";
print_r($db->fetchAll("SELECT p.*, th.txn_number, th.txn_date, th.status, th.is_deleted FROM payments p JOIN transaction_headers th ON p.header_id = th.id WHERE p.customer_id = ?", [$cid]));

echo "\n=== TRANSACTION LINKS FOR UPENDRA SAHA PAYMENTS ===\n";
print_r($db->fetchAll("SELECT tl.*, th_p.txn_number as pay_no, th_c.txn_number as child_no FROM transaction_links tl JOIN transaction_headers th_p ON tl.parent_id = th_p.id JOIN transaction_headers th_c ON tl.child_id = th_c.id JOIN payments p ON p.header_id = th_p.id WHERE p.customer_id = ?", [$cid]));
