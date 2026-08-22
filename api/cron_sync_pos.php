<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

header('Content-Type: application/json');

// Authorization guard: CLI, active logged-in session, or secret key
$cron_key = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
$expected_key = $_ENV['CRON_SECRET_KEY'] ?? getenv('CRON_SECRET_KEY') ?: 'SMS_CRON_SECRET_KEY_8182';

if (PHP_SAPI !== 'cli' && !isset($_SESSION['user_id']) && $cron_key !== $expected_key) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: Unauthorized access to background sync endpoint.']);
    exit;
}

try {
    auto_sync_pos_items_and_invoices(true);
    echo json_encode([
        'status' => 'success',
        'message' => 'POS to item and invoice sync completed successfully at 5-minute interval.',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
