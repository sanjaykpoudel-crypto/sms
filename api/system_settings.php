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

// Root of the sms project (c:\xampp\htdocs\sms)
$root = dirname(__DIR__); // api/../ = sms/

try {
    header('Content-Type: application/json');
    // Save all text fields
    foreach ($_POST as $key => $value) {
        $exists = $db->fetchOne("SELECT id FROM system_info WHERE meta_field = :key", ['key' => $key]);
        if ($exists) {
            $db->execute("UPDATE system_info SET meta_value = :val WHERE meta_field = :key", ['val' => $value, 'key' => $key]);
        } else {
            $db->execute("INSERT INTO system_info (meta_field, meta_value) VALUES (:key, :val)", ['key' => $key, 'val' => $value]);
        }
    }

    // Handle logo upload
    if (isset($_FILES['img']) && !empty($_FILES['img']['tmp_name'])) {
        $upload_dir = $root . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Keep original extension
        $ext = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
        $ext = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? $ext : 'png';
        $filename = 'logo.' . $ext;
        $target   = $upload_dir . DIRECTORY_SEPARATOR . $filename;
        $db_value = 'uploads/' . $filename; // relative to sms/ root, used in src=""

        if (move_uploaded_file($_FILES['img']['tmp_name'], $target)) {
            $exists = $db->fetchOne("SELECT id FROM system_info WHERE meta_field = 'logo'");
            if ($exists) {
                $db->execute("UPDATE system_info SET meta_value = :val WHERE meta_field = 'logo'", ['val' => $db_value]);
            } else {
                $db->execute("INSERT INTO system_info (meta_field, meta_value) VALUES ('logo', :val)", ['val' => $db_value]);
            }
        } else {
            throw new Exception('Logo upload failed. Target: ' . $target);
        }
    }

    // Handle cover upload
    if (isset($_FILES['cover']) && !empty($_FILES['cover']['tmp_name'])) {
        $upload_dir = $root . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
        $ext = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? $ext : 'png';
        $filename = 'cover.' . $ext;
        $target   = $upload_dir . DIRECTORY_SEPARATOR . $filename;
        $db_value = 'uploads/' . $filename;

        if (move_uploaded_file($_FILES['cover']['tmp_name'], $target)) {
            $exists = $db->fetchOne("SELECT id FROM system_info WHERE meta_field = 'cover'");
            if ($exists) {
                $db->execute("UPDATE system_info SET meta_value = :val WHERE meta_field = 'cover'", ['val' => $db_value]);
            } else {
                $db->execute("INSERT INTO system_info (meta_field, meta_value) VALUES ('cover', :val)", ['val' => $db_value]);
            }
        } else {
            throw new Exception('Cover upload failed. Target: ' . $target);
        }
    }

    // Handle payment QR image upload
    if (isset($_FILES['payment_qr_image']) && !empty($_FILES['payment_qr_image']['tmp_name'])) {
        $upload_dir = $root . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES['payment_qr_image']['name'], PATHINFO_EXTENSION));
        $ext = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? $ext : 'png';
        $filename = 'payment_qr.' . $ext;
        $target   = $upload_dir . DIRECTORY_SEPARATOR . $filename;
        $db_value = 'uploads/' . $filename;

        if (move_uploaded_file($_FILES['payment_qr_image']['tmp_name'], $target)) {
            $exists = $db->fetchOne("SELECT id FROM system_info WHERE meta_field = 'payment_qr_image'");
            if ($exists) {
                $db->execute("UPDATE system_info SET meta_value = :val WHERE meta_field = 'payment_qr_image'", ['val' => $db_value]);
            } else {
                $db->execute("INSERT INTO system_info (meta_field, meta_value) VALUES ('payment_qr_image', :val)", ['val' => $db_value]);
            }

            // Auto-decode payload text from uploaded static QR image
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.qrserver.com/v1/read-qr-code/');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($target, 'image/' . $ext, 'payment_qr.' . $ext)]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $resp = curl_exec($ch);
                curl_close($ch);

                if ($resp) {
                    $json_res = json_decode($resp, true);
                    $decoded_str = $json_res[0]['symbol'][0]['data'] ?? '';
                    if (!empty($decoded_str)) {
                        $p_exists = $db->fetchOne("SELECT id FROM system_info WHERE meta_field = 'payment_qr_text'");
                        if ($p_exists) {
                            $db->execute("UPDATE system_info SET meta_value = ? WHERE meta_field = 'payment_qr_text'", [$decoded_str]);
                        } else {
                            $db->execute("INSERT INTO system_info (meta_field, meta_value) VALUES ('payment_qr_text', ?)", [$decoded_str]);
                        }
                    }
                }
            } catch (Exception $ex) {
                // Ignore fallback
            }
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully.']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}




