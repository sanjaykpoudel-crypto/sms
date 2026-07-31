<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$db = db();
$user_id = $db->fetchOne("SELECT id FROM users LIMIT 1")['id'];
$_SESSION['user_id'] = $user_id;

$_POST = [
    'txn_number'      => getNextTransactionNumber('credit_memo'),
    'txn_date'        => date('Y-m-d'),
    'party_id'        => $db->fetchOne("SELECT id FROM customers LIMIT 1")['id'],
    'invoice_id'      => '',
    'location_id'     => get_user_default_location_id(),
    'memo'            => 'Test Credit Memo Save',
    'return_to_stock' => '1',
    'item_id'         => [$db->fetchOne("SELECT id FROM items LIMIT 1")['id']],
    'qty'             => [1],
    'rate'            => [100.00],
    'tax_pct'         => [0],
    'unit'            => ['pcs']
];

ob_start();
include 'api/save_credit_memo.php';
$output = ob_get_clean();

echo "API Response: $output\n";
