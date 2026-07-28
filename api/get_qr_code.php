<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
header('Content-Type: application/json');

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

$db = db();

$amount = isset($_REQUEST['amount']) ? (float)$_REQUEST['amount'] : 0.00;
$txn_no = isset($_REQUEST['txn_no']) ? trim($_REQUEST['txn_no']) : '';
$company_name = isset($_REQUEST['company_name']) ? trim($_REQUEST['company_name']) : '';

$sys = [];
$rows = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
foreach ($rows as $r) {
    $sys[$r['meta_field']] = $r['meta_value'];
}

if (empty($company_name)) {
    $company_name = $sys['name'] ?? ($sys['company_name'] ?? 'MNS LIQUORS');
}

$qr_image_db = $sys['payment_qr_image'] ?? '';
$qr_custom_text = $sys['payment_qr_text'] ?? '';
$qr_raw = !empty($qr_image_db) ? $qr_image_db : $qr_custom_text;

$qr_src = generate_payment_qr_src($qr_raw, $amount, $txn_no, $company_name);

echo json_encode([
    'status' => 'success',
    'qr_src' => $qr_src,
    'amount' => number_format($amount, 2, '.', ''),
    'formatted_amount' => 'Rs ' . number_format($amount, 2),
    'txn_no' => $txn_no,
    'company_name' => $company_name
]);
