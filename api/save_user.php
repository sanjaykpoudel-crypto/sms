<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../database/DBConnection.php';

// Strict Authentication and RBAC Check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Admin privileges required.']);
        exit;
    }
    $_SESSION['error'] = "Unauthorized access. Admin privileges required.";
    header("Location: ../index.php");
    exit;
}

$db = db();
$pdo = $db->getConnection();

$id       = trim($_POST['id'] ?? '');
$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role       = trim($_POST['role'] ?? 'cashier');
$locationId = !empty($_POST['location_id']) ? trim($_POST['location_id']) : null;
$isActive   = isset($_POST['is_inactive']) ? 0 : (isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1);
$userId     = $_SESSION['user_id'];

$phone            = trim($_POST['phone'] ?? '');
$designation      = trim($_POST['designation'] ?? '');
$department       = trim($_POST['department'] ?? '');
$joiningDate      = !empty($_POST['joining_date']) ? trim($_POST['joining_date']) : null;
$address          = trim($_POST['address'] ?? '');
$emergencyContact = trim($_POST['emergency_contact'] ?? '');

// Basic validation
if (empty($fullName) || empty($username) || empty($email)) {
    $_SESSION['error'] = "Full Name, Username, and Email are required.";
    header("Location: ../index.php?page=system/users/manage" . ($id ? "&id=$id" : ''));
    exit;
}

// Validate Email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = "Invalid email address format: '$email'.";
    header("Location: ../index.php?page=system/users/manage" . ($id ? "&id=$id" : ''));
    exit;
}

// Validate Phone Number format if provided
if (!empty($phone) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
    $_SESSION['error'] = "Invalid phone number format: '$phone'. Please use numbers, spaces, or +-() prefix (e.g. +977 9801234567).";
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

        // Check email uniqueness (exclude current user)
        $existingEmail = $db->fetchOne("SELECT id FROM users WHERE email = ? AND id != ? AND is_deleted = 0", [$email, $id]);
        if ($existingEmail) {
            throw new Exception("Email '$email' is already registered to another user.");
        }

        if (!empty($password)) {
            // Update with new password
            $pdo->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, location_id=?, is_active=?, phone=?, designation=?, department=?, joining_date=?, address=?, emergency_contact=?, password_hash=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$fullName, $username, $email, $role, $locationId, $isActive, $phone, $designation, $department, $joiningDate, $address, $emergencyContact, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            // Keep existing password
            $pdo->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, location_id=?, is_active=?, phone=?, designation=?, department=?, joining_date=?, address=?, emergency_contact=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$fullName, $username, $email, $role, $locationId, $isActive, $phone, $designation, $department, $joiningDate, $address, $emergencyContact, $id]);
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

        // Check email uniqueness
        $existingEmail = $db->fetchOne("SELECT id FROM users WHERE email = ? AND is_deleted = 0", [$email]);
        if ($existingEmail) {
            throw new Exception("Email '$email' is already registered.");
        }

        // Generate UUID
        $newId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );

        $pdo->prepare("INSERT INTO users (id, full_name, username, email, password_hash, role, location_id, is_active, phone, designation, department, joining_date, address, emergency_contact) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$newId, $fullName, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $locationId, $isActive, $phone, $designation, $department, $joiningDate, $address, $emergencyContact]);

        $newData = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$newId]);
        $pdo->prepare("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES (?,?,?,?,?,?)")
            ->execute(['users', 'create', $newId, json_encode(null), json_encode($newData), $userId]);
    }

    $pdo->commit();

    // Handle File Uploads (Avatar & Citizenship Documents)
    $savedId = $id ?: $newId;
    $uploadDir = __DIR__ . '/../uploads/employees';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $fileUpdates = [];
    $fileParams = [];

    $filesToProcess = [
        'avatar'            => 'avatar',
        'citizenship_front' => 'cit_front',
        'citizenship_back'  => 'cit_back'
    ];

    foreach ($filesToProcess as $field => $prefix) {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK && !empty($_FILES[$field]['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            if (in_array($ext, $allowed)) {
                $filename = $prefix . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $savedId) . '_' . time() . '.' . $ext;
                $target = $uploadDir . '/' . $filename;
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
                    $dbPath = 'uploads/employees/' . $filename;
                    $fileUpdates[] = "$field = ?";
                    $fileParams[] = $dbPath;
                }
            }
        }
    }

    if (!empty($fileUpdates)) {
        $fileParams[] = $savedId;
        $sql = "UPDATE users SET " . implode(', ', $fileUpdates) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($fileParams);
    }

    // If the edited user is the currently logged-in user, update session so header reflects changes immediately
    if ($savedId === $_SESSION['user_id']) {
        $_SESSION['full_name'] = $fullName;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;
    }

    $_SESSION['success'] = $id ? "User updated successfully." : "User created successfully.";
    header("Location: ../index.php?page=system/users/view&id=" . $savedId);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = $e->getMessage();
    header("Location: ../index.php?page=system/users/manage" . ($id ? "&id=$id" : ''));
    exit;
}



