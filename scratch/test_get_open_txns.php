<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$_GET = [
    'party_id' => 19,
    'party_type' => 'customer',
    'payment_id' => 408318
];

ob_start();
include __DIR__ . '/../api/get_open_transactions.php';
$json = ob_get_clean();

echo "Open transactions for party 19 (Upendra Saha) with payment 408318:\n";
$data = json_decode($json, true);
print_r($data);
