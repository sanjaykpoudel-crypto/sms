<?php
session_start();
$_SESSION['user_id'] = 'usr-admin-001';

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

// Test 1: Fetch open transactions for Customer Sanjay
$_GET['party_id'] = '599abbd6-6f76-4d74-8618-14feed600342';
$_GET['party_type'] = 'customer';

ob_start();
include __DIR__ . '/../api/get_open_transactions.php';
$customer_res = ob_get_clean();

echo "Customer Payment Open Transactions Result:\n" . $customer_res . "\n\n";

// Test 2: Fetch open transactions for Vendor Friendship
$_GET['party_id'] = '53566186-b9c3-434f-a272-69a46a765c00';
$_GET['party_type'] = 'vendor';

ob_start();
include __DIR__ . '/../api/get_open_transactions.php';
$vendor_res = ob_get_clean();

echo "Vendor Payment Open Transactions Result:\n" . $vendor_res . "\n";
