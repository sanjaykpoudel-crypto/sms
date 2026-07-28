<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once '../database/DBConnection.php';
header('Content-Type: application/json');

$db = db();

// Fetch WhatsApp integration settings
$info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info WHERE meta_field LIKE 'whatsapp_%' OR meta_field = 'name'");
$settings = [];
foreach($info as $row) {
    $settings[$row['meta_field']] = $row['meta_value'];
}

$enabled      = $settings['whatsapp_enabled'] ?? '1';
$provider     = strtolower($settings['whatsapp_api_provider'] ?? 'generic');
$api_url      = trim($settings['whatsapp_api_url'] ?? '');
$instance_id  = trim($settings['whatsapp_instance_id'] ?? '');
$api_token    = trim($settings['whatsapp_api_token'] ?? '');
$company_name = $settings['name'] ?? 'MNS LIQUORS';

// Get request payload (supports JSON and POST)
$raw_input = file_get_contents('php://input');
$input_data = json_decode($raw_input, true) ?? [];
$to        = $_POST['to'] ?? ($input_data['to'] ?? '');
$message   = $_POST['message'] ?? ($input_data['message'] ?? '');
$media_url = $_POST['media_url'] ?? ($input_data['media_url'] ?? ($_POST['document_url'] ?? ($input_data['document_url'] ?? '')));

if (empty($to)) {
    echo json_encode(['status' => 'error', 'message' => 'Recipient phone number is required.']);
    exit;
}

if (empty($message) && empty($media_url)) {
    echo json_encode(['status' => 'error', 'message' => 'Message body or document attachment is required.']);
    exit;
}

// Clean phone number format
$clean_phone = preg_replace('/[^0-9]/', '', $to);
// Prepend Nepal country code 977 if local 10-digit mobile starting with 9
if (strlen($clean_phone) === 10 && (strpos($clean_phone, '98') === 0 || strpos($clean_phone, '97') === 0)) {
    $clean_phone = '977' . $clean_phone;
}

if (empty($api_url) && empty($api_token) && empty($instance_id)) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'WhatsApp API Gateway settings are not configured in Setup. Please configure API URL/Token under Setup > WhatsApp Integration.'
    ]);
    exit;
}

try {
    $ch = curl_init();
    $http_status = 200;
    $response_body = '';

    if ($provider === 'ultramsg' || strpos($api_url, 'ultramsg.com') !== false) {
        $endpoint = !empty($api_url) ? $api_url : "https://api.ultramsg.com/{$instance_id}/messages/" . (!empty($media_url) ? 'document' : 'chat');
        $params = [
            'token' => $api_token,
            'to'    => '+' . $clean_phone,
            'body'  => $message,
            'caption' => $message
        ];
        if (!empty($media_url)) {
            $params['document'] = $media_url;
            $params['filename'] = 'Statement_Document.pdf';
        }
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

    } elseif ($provider === 'greenapi' || strpos($api_url, 'green-api.com') !== false) {
        $action_path = !empty($media_url) ? "sendFileByUrl/{$api_token}" : "sendMessage/{$api_token}";
        $endpoint = !empty($api_url) ? $api_url : "https://api.green-api.com/waInstance{$instance_id}/{$action_path}";
        $payload = [
            'chatId'  => $clean_phone . '@c.us',
            'message' => $message,
            'caption' => $message
        ];
        if (!empty($media_url)) {
            $payload['urlFile'] = $media_url;
            $payload['fileName'] = 'Statement_Document.pdf';
        }
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    } elseif ($provider === 'waboxapp' || strpos($api_url, 'waboxapp.com') !== false) {
        $endpoint = !empty($api_url) ? $api_url : "https://waboxapp.com/api/send/chat";
        $params = [
            'token'      => $api_token,
            'uid'        => $clean_phone,
            'to'         => $clean_phone,
            'custom_uid' => time(),
            'text'       => $message
        ];
        if (!empty($media_url)) {
            $params['media_url'] = $media_url;
        }
        curl_setopt($ch, CURLOPT_URL, $endpoint . '?' . http_build_query($params));

    } else {
        // Generic / Custom Webhook Gateway API
        $endpoint = $api_url;
        $payload = [
            'instance_id'  => $instance_id,
            'token'        => $api_token,
            'to'           => $clean_phone,
            'phone'        => $clean_phone,
            'message'      => $message,
            'text'         => $message,
            'media_url'    => $media_url,
            'document_url' => $media_url
        ];
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "Authorization: Bearer {$api_token}"]);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response_body = curl_exec($ch);
    $http_status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error    = curl_error($ch);
    curl_close($ch);

    if (!empty($curl_error)) {
        throw new Exception("cURL Error: " . $curl_error);
    }

    $res_json = json_decode($response_body, true);

    if ($http_status >= 200 && $http_status < 300) {
        echo json_encode([
            'status'  => 'success',
            'message' => 'WhatsApp message sent successfully to ' . $to . '!',
            'raw'     => $res_json ?? $response_body
        ]);
    } else {
        echo json_encode([
            'status'  => 'error',
            'message' => 'API returned HTTP code ' . $http_status . '. Response: ' . (is_string($response_body) ? substr($response_body, 0, 200) : 'Error'),
            'raw'     => $res_json ?? $response_body
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to send WhatsApp message: ' . $e->getMessage()]);
}
