<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../database/DBConnection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';
$db = db();

if ($action === 'request_reset') {
    $identifier = trim($_POST['identifier'] ?? '');
    if (empty($identifier)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter your username or email address']);
        exit;
    }

    try {
        $user = $db->fetchOne(
            "SELECT * FROM users WHERE (username = :username_param OR email = :email_param) AND is_active = 1 AND is_deleted = 0",
            ['username_param' => $identifier, 'email_param' => $identifier]
        );

        if (!$user) {
            echo json_encode(['status' => 'error', 'message' => 'No active user account found with that username or email address']);
            exit;
        }

        // Generate token and 6-digit OTP code
        $token = bin2hex(random_bytes(32));
        $otp_code = sprintf('%06d', mt_rand(100000, 999999));

        // Invalidate old tokens for this user
        $db->execute("UPDATE password_resets SET is_used = 1 WHERE user_id = :user_id AND is_used = 0", [
            'user_id' => $user['id']
        ]);

        // Insert new password reset request with MySQL timestamp calculation (prevents PHP vs MySQL timezone mismatches)
        $db->execute(
            "INSERT INTO password_resets (user_id, username, email, token, otp_code, expires_at) VALUES (:user_id, :username, :email, :token, :otp_code, DATE_ADD(NOW(), INTERVAL 30 MINUTE))",
            [
                'user_id'  => $user['id'],
                'username' => $user['username'],
                'email'    => $user['email'],
                'token'    => $token,
                'otp_code' => $otp_code
            ]
        );

        // Attempt sending email if PHP mail is operational
        $email_sent = false;
        if (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $subject = "Password Reset Code - SMS ERP";
            $message = "Hello " . htmlspecialchars($user['full_name']) . ",\n\n" .
                       "You requested a password reset for your SMS ERP account.\n\n" .
                       "Your 6-digit Verification Code is: " . $otp_code . "\n\n" .
                       "This code will expire in 15 minutes.\n\n" .
                       "If you did not request this, please ignore this message.\n\n" .
                       "Regards,\nSMS ERP Team";
            $headers = "From: no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n" .
                       "X-Mailer: PHP/" . phpversion();
            
            @$email_sent = mail($user['email'], $subject, $message, $headers);
        }

        echo json_encode([
            'status'     => 'success',
            'message'    => 'Verification code generated successfully.' . ($email_sent ? ' Check your email inbox.' : ''),
            'token'      => $token,
            'username'   => $user['username'],
            'email'      => $user['email'],
            'otp_code'   => $otp_code, // Provided for local environment display & instant verification
            'email_sent' => $email_sent
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to process request: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'verify_otp') {
    $token    = trim($_POST['token'] ?? '');
    $otp_code = trim($_POST['otp_code'] ?? '');

    if (empty($otp_code)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter the 6-digit verification code']);
        exit;
    }

    try {
        $reset_req = null;
        if (!empty($token)) {
            $reset_req = $db->fetchOne(
                "SELECT * FROM password_resets WHERE token = :token AND otp_code = :otp_code AND is_used = 0 AND expires_at > NOW()",
                ['token' => $token, 'otp_code' => $otp_code]
            );
        } else {
            $reset_req = $db->fetchOne(
                "SELECT * FROM password_resets WHERE otp_code = :otp_code AND is_used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1",
                ['otp_code' => $otp_code]
            );
        }

        if (!$reset_req) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired verification code']);
            exit;
        }

        echo json_encode([
            'status'  => 'success',
            'message' => 'Verification code confirmed. Please set your new password.',
            'token'   => $reset_req['token']
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'reset_password') {
    $token            = trim($_POST['token'] ?? '');
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'Reset token is missing or invalid']);
        exit;
    }

    if (empty($new_password)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a new password']);
        exit;
    }

    if (strlen($new_password) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'New password must be at least 6 characters long']);
        exit;
    }

    if ($new_password !== $confirm_password) {
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
        exit;
    }

    try {
        $reset_req = $db->fetchOne(
            "SELECT * FROM password_resets WHERE token = :token AND is_used = 0 AND expires_at > NOW()",
            ['token' => $token]
        );

        if (!$reset_req) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired reset token. Please request a new code.']);
            exit;
        }

        // Update user password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $db->execute(
            "UPDATE users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE id = :user_id",
            ['hash' => $password_hash, 'user_id' => $reset_req['user_id']]
        );

        // Mark reset token as used
        $db->execute("UPDATE password_resets SET is_used = 1 WHERE id = :id", ['id' => $reset_req['id']]);

        echo json_encode([
            'status'   => 'success',
            'message'  => 'Your password has been reset successfully! You can now sign in with your new password.',
            'username' => $reset_req['username']
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to reset password: ' . $e->getMessage()]);
        exit;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}
