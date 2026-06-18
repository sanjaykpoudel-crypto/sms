<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
header('Content-Type: application/json');
require_once '../database/DBConnection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$db = db();
$user_id = $_SESSION['user_id'];
$layout_data = isset($data['layout_data']) ? json_encode($data['layout_data']) : null;
$filters_data = isset($data['filters_data']) ? json_encode($data['filters_data']) : null;

try {
    $existing = $db->fetchOne("SELECT id FROM user_dashboard_preferences WHERE user_id = ?", [$user_id]);
    if ($existing) {
        $update_data = [];
        if (isset($data['layout_data'])) {
            $update_data['layout_data'] = $layout_data;
        }
        if (isset($data['filters_data'])) {
            $update_data['filters_data'] = $filters_data;
        }
        if (!empty($update_data)) {
            $db->update('user_dashboard_preferences', $update_data, "user_id = :user_id", ['user_id' => $user_id]);
        }
    } else {
        // Insert new
        require_once 'reference_helper.php';
        $pref_id = generate_uuid();
        $db->insert('user_dashboard_preferences', [
            'id' => $pref_id,
            'user_id' => $user_id,
            'layout_data' => $layout_data,
            'filters_data' => $filters_data
        ]);
    }
    echo json_encode(['status' => 'success', 'message' => 'Preferences saved successfully']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
