<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== INSPECTING CM-0002 (cb76ce91-1059-471f-9912-5de6a9550de9) ===\n\n";

$th = $db->fetchAll("SELECT * FROM transaction_headers WHERE id = 'cb76ce91-1059-471f-9912-5de6a9550de9' OR txn_number = 'CM-0002'");
echo "TRANSACTION HEADERS:\n";
print_r($th);

$cm = $db->fetchAll("SELECT * FROM credit_memos WHERE header_id = 'cb76ce91-1059-471f-9912-5de6a9550de9' OR memo_number = 'CM-0002'");
echo "\nCREDIT MEMOS:\n";
print_r($cm);

$tl1 = $db->fetchAll("SELECT * FROM transaction_links WHERE parent_id = 'cb76ce91-1059-471f-9912-5de6a9550de9' OR child_id = 'cb76ce91-1059-471f-9912-5de6a9550de9'");
echo "\nTRANSACTION LINKS (Parent or Child):\n";
print_r($tl1);

$pm = $db->fetchAll("SELECT * FROM payments WHERE applied_to_txn_id = 'cb76ce91-1059-471f-9912-5de6a9550de9' OR header_id = 'cb76ce91-1059-471f-9912-5de6a9550de9'");
echo "\nPAYMENTS:\n";
print_r($pm);

echo "\n--- ALL PAYMENTS FOR WALK IN ---\n";
$all_pm = $db->fetchAll("
    SELECT h.txn_number, h.txn_type, h.net_amount, h.status, tl.child_id, tl.link_type
    FROM transaction_headers h
    LEFT JOIN transaction_links tl ON tl.parent_id = h.id
    WHERE h.party_id = (SELECT party_id FROM transaction_headers WHERE txn_number = 'CM-0002')
");
print_r($all_pm);
