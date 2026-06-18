<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../database/DBConnection.php';

$db = db();
$pdo = $db->getConnection();

$id       = trim($_POST['id'] ?? '');
$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role     = trim($_POST['role'] ?? 'cashier');
$isActive = isset($_POST['is_active']) ? 1 : 0;
$userId   = $_SESSION['user_id'] ?? null;

// Basic validation
if (empty($fullName) || empty($username) || empty($email)) {
    $_SESSION['error'] = "Full Name, Username, and Email are required.";
    header("Location: ../index.php?page=system/users/manage" . ($id ? "&id=$id" : ''));
    exit;
}

try {
    $pdo->beginTransaction();

    if ($id) {
        // --- UPDATE existing user ---
        $oldData = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$oldData) {
            throw new Exception("User not found.");
        }

        // Check username uniqueness (exclude current user)
        $existing = $db->fetchOne("SELECT id FROM users WHERE username = ? AND id != ?", [$username, $id]);
        if ($existing) {
            throw new Exception("Username '$username' is already taken.");
        }

        if (!empty($password)) {
            // Update with new password
            $pdo->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, is_active=?, password_hash=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$fullName, $username, $email, $role, $isActive, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            // Keep existing password
            $pdo->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$fullName, $username, $email, $role, $isActive, $id]);
        }

        // Audit log
        $newData = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        $pdo->prepare("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES (?,?,?,?,?,?)")
            ->execute(['users', 'update', $id, json_encode($oldData), json_encode($newData), $userId]);

    } else {
        // --- CREATE new user ---
        if (empty($password)) {
            throw new Exception("Password is required for new users.");
        }

        // Check username uniqueness
        $existing = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) {
            throw new Exception("Username '$username' is already taken.");
        }

        // Generate UUID
        $newId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );

        $pdo->prepare("INSERT INTO users (id, full_name, username, email, password_hash, role, is_active) VALUES (?,?,?,?,?,?,?)")
            ->execute([$newId, $fullName, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $isActive]);

        $newData = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$newId]);
        $pdo->prepare("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES (?,?,?,?,?,?)")
            ->execute(['users', 'create', $newId, json_encode(null), json_encode($newData), $userId]);
    }

    $pdo->commit();

    // If the edited user is the currently logged-in user, update session so header reflects changes immediately
    $savedId = $id ?: $newId;
    if ($savedId === $_SESSION['user_id']) {
        $_SESSION['full_name'] = $fullName;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;
    }

    $_SESSION['success'] = $id ? "User updated successfully." : "User created successfully.";
    header("Location: ../index.php?page=system/users/view&id=" . $savedId);
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
    header("Location: ../index.php?page=system/users/manage" . ($id ? "&id=$id" : ''));
    exit;
}



