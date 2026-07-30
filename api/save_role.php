<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

try {
    $id = $_POST['id'] ?? null;
    $role_name = trim($_POST['role_name'] ?? '');
    $role_code = strtolower(trim($_POST['role_code'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $access_level = $_POST['access_level'] ?? 'custom';
    $is_active = isset($_POST['is_inactive']) ? 0 : 1;
    $permissions_input = $_POST['permissions'] ?? [];

    if (empty($role_name)) {
        throw new Exception("Role Name is required.");
    }

    if (empty($role_code)) {
        $role_code = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $role_name));
    }

    // Check duplicate role_code
    if ($id) {
        $dup = $db->fetchOne("SELECT id FROM roles WHERE role_code = ? AND id != ?", [$role_code, $id]);
    } else {
        $dup = $db->fetchOne("SELECT id FROM roles WHERE role_code = ?", [$role_code]);
    }

    if ($dup) {
        throw new Exception("A role with code '$role_code' already exists.");
    }

    $permissions_json = json_encode($permissions_input, JSON_PRETTY_PRINT);

    if ($id) {
        $db->execute("
            UPDATE roles 
            SET role_name = ?, role_code = ?, description = ?, access_level = ?, permissions = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [$role_name, $role_code, $description, $access_level, $permissions_json, $is_active, $id]);
    } else {
        $id = generate_uuid();
        $db->execute("
            INSERT INTO roles (id, role_code, role_name, description, access_level, permissions, is_system, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
        ", [$id, $role_code, $role_name, $description, $access_level, $permissions_json, $is_active]);
    }

    ob_end_clean();
    echo json_encode(['status' => 'success', 'message' => 'Role permissions saved successfully.', 'id' => $id]);
    exit;
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
