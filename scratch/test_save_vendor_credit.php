<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$db = db();
$user_id = $db->fetchOne("SELECT id FROM users LIMIT 1")['id'];
$_SESSION['user_id'] = $user_id;

$_POST = [
    'txn_number'        => getNextTransactionNumber('vendor_credit'),
    'txn_date'          => date('Y-m-d'),
    'party_id'          => $db->fetchOne("SELECT id FROM vendors LIMIT 1")['id'],
    'bill_id'           => '',
    'location_id'       => get_user_default_location_id(),
    'memo'              => 'Test Vendor Credit Save',
    'deduct_from_stock' => '1',
    'item_id'           => [$db->fetchOne("SELECT id FROM items LIMIT 1")['id']],
    'qty'               => [1],
    'rate'              => [50.00],
    'tax_pct'           => [0],
    'unit'              => ['pcs']
];

ob_start();
include 'api/save_vendor_credit.php';
$output = ob_get_clean();

echo "API Response: $output\n";
