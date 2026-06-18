<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once '../database/DBConnection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$db = db();
$id = $_POST['id'] ?? '';
$type = $_POST['type'] ?? '';
$name = $_POST['name'] ?? '';
$is_active = $_POST['is_active'] ?? 1;
$description = $_POST['description'] ?? '';

// Initialize specific fields as null/empty
$code = $_POST['override_code'] ?? ($_POST['code'] ?? ($_POST['tax_code'] ?? ($_POST['currency_code'] ?? ($_POST['ref_code'] ?? ($_POST['status_code'] ?? '')))));
$value = 0;
$symbol = null;

// Only populate relevant fields based on type
if ($type === 'tax' || $type === 'tax_code') {
    $value = (float)($_POST['value_tax'] ?? $_POST['value'] ?? 0);
    $symbol = null; 
} elseif ($type === 'currency') {
    $value = (float)($_POST['value_currency'] ?? $_POST['value'] ?? 0);
    $symbol = $_POST['symbol'] ?? '';
} else {
    $value = (float)($_POST['value'] ?? 0);
    $symbol = null;
}

if (empty($type) || empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'Type and Name are required']);
    exit;
}

try {
    $data = [
        'type' => $type,
        'name' => $name,
        'code' => $code,
        'value' => $value,
        'symbol' => $symbol,
        'description' => $description,
        'is_active' => $is_active
    ];

    if (empty($id)) {
        // Create new record
        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $data['id'] = $id;
        $db->insert('reference_codes', $data);
    } else {
        // Update existing record
        $db->update('reference_codes', $data, "id = :id", ['id' => $id]);
    }

    echo json_encode(['status' => 'success', 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}




