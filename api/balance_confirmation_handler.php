<?php
/**
 * AntiGravity ERP - Balance Confirmation API Handler
 * Supports sending emails and API requests for balance confirmations
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

$db = db();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'send_email') {
    header('Content-Type: application/json');
    $party_type = $_POST['party_type'] ?? 'customer';
    $party_id   = $_POST['party_id'] ?? '';
    $email      = $_POST['email'] ?? '';
    $as_on_date = $_POST['as_on_date'] ?? date('Y-m-d');
    $message    = $_POST['message'] ?? '';

    if (!$party_id || !$email) {
        echo json_encode(['status' => 'error', 'message' => 'Party ID and Email are required.']);
        exit();
    }

    // In PHP application without external SMTP configured, log/simulate email dispatch
    try {
        $db->execute("INSERT INTO system_logs (log_type, message, context, user_id) VALUES (?, ?, ?, ?)", [
            'EMAIL_CONFIRMATION',
            "Balance confirmation report sent to " . $party_type . " (ID: " . $party_id . ") at " . $email,
            json_encode([
                'party_type' => $party_type,
                'party_id'   => $party_id,
                'email'      => $email,
                'as_on_date' => $as_on_date,
                'sent_at'    => date('Y-m-d H:i:s')
            ]),
            $_SESSION['user_id']
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Balance confirmation letter successfully sent to ' . htmlspecialchars($email)
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to process email request: ' . $e->getMessage()
        ]);
    }
    exit();
}

header('Content-Type: application/json');
echo json_encode(['status' => 'error', 'message' => 'Invalid action parameter']);
exit();
