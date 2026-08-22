<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$parents = [22, 119, 888962, 142091];
foreach ($parents as $p) {
    $hdr = $db->fetchOne("
        SELECT th.*, 
               c.full_name as cust_name, 
               v.company_name as vend_name
        FROM transaction_headers th
        LEFT JOIN customers c ON th.party_id = c.id AND th.party_type = 'customer'
        LEFT JOIN vendors v ON th.party_id = v.id AND th.party_type = 'vendor'
        WHERE th.id = ?
    ", [$p]);
    echo "Parent ID $p:\n";
    print_r($hdr);
    $pay = $db->fetchAll("SELECT * FROM payments WHERE header_id = ?", [$p]);
    echo "  Payments: \n";
    print_r($pay);
    $links = $db->fetchAll("SELECT * FROM transaction_links WHERE parent_id = ?", [$p]);
    echo "  Links: \n";
    print_r($links);
    echo "\n";
}
