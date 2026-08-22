<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

// Check transaction_links for CPAY-00001 (ID 220) and CPAY-00002 (ID 69)
$links = $db->fetchAll("SELECT * FROM transaction_links WHERE parent_id IN (220, 69) OR child_id IN (220, 69)");
print_r($links);

foreach ($links as $l) {
    $inv_id = $l['child_id'];
    $inv = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$inv_id]);
    echo "Invoice for link: {$l['link_type']} | Header $inv_id:\n";
    print_r($inv);
}
