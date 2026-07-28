<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * EMVCo CRC-16 CCITT Calculation
 * Polynomial: 0x1021, Initial: 0xFFFF
 */
function calculate_emvco_crc16($str) {
    $crc = 0xFFFF;
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $x = (($crc >> 8) ^ ord($str[$i])) & 0xFF;
        $x ^= $x >> 4;
        $crc = (($crc << 8) ^ ($x << 12) ^ ($x << 5) ^ $x) & 0xFFFF;
    }
    return strtoupper(sprintf("%04X", $crc));
}

/**
 * Parse EMVCo TLV (Tag-Length-Value) Payload String
 */
function parse_emvco_payload($payload) {
    $payload = trim($payload);
    $data = [
        'is_valid_emvco' => false,
        'raw_payload' => $payload,
        'merchant_name' => '',
        'merchant_city' => '',
        'merchant_id' => '',
        'currency' => 'NPR (524)',
        'original_amount' => '',
        'point_of_initiation' => 'Static (11)',
        'mcc' => '',
        'bill_number' => '',
        'reference_id' => '',
        'tags' => []
    ];

    if (strpos($payload, '000201') !== 0) {
        // Fallback for non-EMVCo raw text or URL format (e.g. upi:// or esewa://)
        if (preg_match('/(mcode|merchant|pid)=([^&]+)/i', $payload, $matches)) {
            $data['merchant_id'] = urldecode($matches[2]);
        }
        return $data;
    }

    $data['is_valid_emvco'] = true;
    $pos = 0;
    $len = strlen($payload);

    while ($pos < $len) {
        if ($pos + 4 > $len) break;
        $tag = substr($payload, $pos, 2);
        $val_len = (int)substr($payload, $pos + 2, 2);
        $pos += 4;

        if ($pos + $val_len > $len) break;
        $value = substr($payload, $pos, $val_len);
        $pos += $val_len;

        $data['tags'][$tag] = $value;

        // Extract key EMVCo Fields
        if ($tag === '01') {
            $data['point_of_initiation'] = ($value === '12') ? 'Dynamic (12)' : 'Static (11)';
        } elseif ($tag === '52') {
            $data['mcc'] = $value;
        } elseif ($tag === '53') {
            $data['currency'] = ($value === '524') ? 'NPR (524)' : $value;
        } elseif ($tag === '54') {
            $data['original_amount'] = $value;
        } elseif ($tag === '59') {
            $data['merchant_name'] = $value;
        } elseif ($tag === '60') {
            $data['merchant_city'] = $value;
        } elseif (in_array($tag, ['26', '27', '28', '29', '30', '31', '38'])) {
            // Sub-parse merchant account info
            if (empty($data['merchant_id'])) {
                if (preg_match('/01(\d{2})([^\d\s]{2,}\d+)/', $value, $m)) {
                    $data['merchant_id'] = $m[2];
                } else {
                    $data['merchant_id'] = substr($value, 4);
                }
            }
        } elseif ($tag === '62') {
            // Sub-parse additional data (Bill No, Ref ID)
            $sub_pos = 0;
            $sub_len = strlen($value);
            while ($sub_pos + 4 <= $sub_len) {
                $sub_tag = substr($value, $sub_pos, 2);
                $sub_vlen = (int)substr($value, $sub_pos + 2, 2);
                $sub_pos += 4;
                if ($sub_pos + $sub_vlen <= $sub_len) {
                    $sub_val = substr($value, $sub_pos, $sub_vlen);
                    $sub_pos += $sub_vlen;
                    if ($sub_tag === '01') $data['bill_number'] = $sub_val;
                    if ($sub_tag === '05' || $sub_tag === '07') $data['reference_id'] = $sub_val;
                } else break;
            }
        }
    }

    return $data;
}

/**
 * Generate Dynamic EMVCo Payload with Updated Amount & Valid Checksum
 */
function generate_dynamic_emvco_payload($raw_payload, $amount, $bill_number = '', $reference_id = '') {
    $clean_amount = number_format((float)$amount, 2, '.', '');
    $payload = trim($raw_payload);

    // 1. If standard EMVCo string (starts with 000201)
    if (strpos($payload, '000201') === 0) {
        $emv = preg_replace('/6304[A-Fa-f0-9]{4}$/i', '', $payload);

        // Parse TLV tags properly to avoid regex corruption of MCC (Tag 52: 5411)
        $pos = 0;
        $len = strlen($emv);
        $tags = [];
        $tag_order = [];

        while ($pos < $len) {
            if ($pos + 4 > $len) break;
            $tag = substr($emv, $pos, 2);
            $vlen = (int)substr($emv, $pos + 2, 2);
            $pos += 4;
            if ($pos + $vlen > $len) break;
            $val = substr($emv, $pos, $vlen);
            $pos += $vlen;

            // Skip existing amount tag 54 to replace it with new amount
            if ($tag === '54') continue;

            $tags[$tag] = $val;
            $tag_order[] = $tag;
        }

        // Set Tag 01 to Dynamic Initiation Mode (12) so mobile apps auto-fill amount
        $tags['01'] = '12';

        // Set Tag 54 (Amount)
        $tags['54'] = $clean_amount;

        // Reconstruct payload in standard EMVCo tag order
        $all_tags = array_unique(array_merge($tag_order, ['01', '54']));
        usort($all_tags, function($a, $b) {
            return (int)$a - (int)$b;
        });

        $reconstructed = '';
        foreach ($all_tags as $t) {
            if (isset($tags[$t])) {
                $v = $tags[$t];
                $vlen = sprintf("%02d", strlen($v));
                $reconstructed .= $t . $vlen . $v;
            }
        }

        // Recalculate CRC-16 Checksum
        $to_crc = $reconstructed . "6304";
        $crc = calculate_emvco_crc16($to_crc);
        $final_payload = $to_crc . $crc;

        return $final_payload;
    }

    // 2. URI / Custom URL Format (e.g. upi://pay?... or esewa://pay?...)
    if (strpos($payload, '://') !== false || strpos($payload, 'pa=') !== false || strpos($payload, 'amt=') !== false) {
        $sep = (strpos($payload, '?') !== false) ? '&' : '?';
        // Replace existing amt/am if present
        $p = preg_replace('/(amt|am)=[0-9\.]+/i', '', $payload);
        $p = rtrim($p, '&?');
        $sep = (strpos($p, '?') !== false) ? '&' : '?';
        return $p . $sep . "amt=" . $clean_amount . ($bill_number ? "&pid=" . urlencode($bill_number) : "");
    }

    // 3. Fallback text payload
    return "PAYMENT | " . $payload . " | Amount: NPR " . $clean_amount . ($bill_number ? " | Bill: " . $bill_number : "");
}

// ROUTE DISPATCHER
$action = $_GET['action'] ?? ($_POST['action'] ?? 'generate');

try {
    if ($action === 'parse' || $action === 'decode') {
        $raw_payload = $_POST['payload'] ?? $_GET['payload'] ?? '';
        
        if (empty($raw_payload)) {
            $raw_input = file_get_contents('php://input');
            $json = json_decode($raw_input, true);
            $raw_payload = $json['payload'] ?? '';
        }

        if (empty($raw_payload)) {
            echo json_encode(['status' => 'error', 'message' => 'No QR payload provided.']);
            exit;
        }

        $parsed = parse_emvco_payload($raw_payload);
        echo json_encode([
            'status' => 'success',
            'data' => $parsed
        ]);
        exit;
    }

    if ($action === 'generate') {
        $raw_input = file_get_contents('php://input');
        $json = json_decode($raw_input, true) ?? [];

        $raw_payload = $_POST['payload'] ?? ($json['payload'] ?? '');
        $amount      = (float)($_POST['amount'] ?? ($json['amount'] ?? 0));
        $bill_no     = $_POST['bill_number'] ?? ($json['bill_number'] ?? '');
        $ref_id      = $_POST['reference_id'] ?? ($json['reference_id'] ?? '');

        if (empty($raw_payload)) {
            echo json_encode(['status' => 'error', 'message' => 'Please provide or scan a static eSewa QR code first.']);
            exit;
        }

        if ($amount <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a valid payment amount greater than 0.']);
            exit;
        }

        $parsed_info = parse_emvco_payload($raw_payload);
        $dynamic_payload = generate_dynamic_emvco_payload($raw_payload, $amount, $bill_no, $ref_id);

        $qr_image_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=0&data=" . urlencode($dynamic_payload);

        echo json_encode([
            'status' => 'success',
            'message' => 'Dynamic eSewa QR generated successfully!',
            'amount_formatted' => number_format($amount, 2),
            'merchant_name' => $parsed_info['merchant_name'] ?: 'eSewa Merchant',
            'merchant_id' => $parsed_info['merchant_id'] ?: 'N/A',
            'dynamic_payload' => $dynamic_payload,
            'qr_image_url' => $qr_image_url,
            'parsed_info' => $parsed_info
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action parameter.']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server Error: ' . $e->getMessage()]);
}
