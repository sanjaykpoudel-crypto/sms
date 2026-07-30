<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Only block direct access to this helper file, not when it's included
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'reference_helper.php') {
    if (PHP_SAPI !== 'cli' && !isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
        exit;
    }
}
/**
 * Helper functions for auto-generated transaction numbering and payment QR codes
 */

function generate_static_qr_src($raw_text_or_image, $company_name = '')
{
    $db = db();
    $qr_text = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'payment_qr_text'")['meta_value'] ?? '';
    $qr_img = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'payment_qr_image'")['meta_value'] ?? '';

    // 1. If static uploaded QR image file exists, return image file path directly
    if (!empty($qr_img)) {
        if (file_exists($qr_img))
            return $qr_img;
        if (file_exists('../' . $qr_img))
            return '../' . $qr_img;
    }
    if (!empty($raw_text_or_image)) {
        if (file_exists($raw_text_or_image))
            return $raw_text_or_image;
        if (file_exists('../' . $raw_text_or_image))
            return '../' . $raw_text_or_image;
    }

    // 2. If static QR text payload exists, render static QR code without modifying amount
    $payload = !empty($qr_text) ? trim($qr_text) : trim($raw_text_or_image);
    if (!empty($payload)) {
        return "https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=0&data=" . urlencode($payload);
    }

    // 3. Fallback static payload
    $merchant = !empty($company_name) ? $company_name : 'Merchant';
    return "https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=0&data=" . urlencode("PAYMENT | Merchant: " . $merchant);
}

function generate_payment_qr_src($raw_text_or_image, $amount, $txn_no = '', $company_name = '')
{
    $clean_amount = number_format((float) $amount, 2, '.', '');
    $db = db();

    // Fetch system info to check if payment_qr_text (EMVCo string) is defined
    $qr_text = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'payment_qr_text'")['meta_value'] ?? '';
    $payload = !empty($qr_text) ? trim($qr_text) : trim($raw_text_or_image);

    // 1. If EMVCo QR String (NepalPay / Fonepay / Smart QR format starting with 000201...)
    if (strpos($payload, '000201') === 0) {
        $emv = preg_replace('/6304[A-Fa-f0-9]{4}$/i', '', $payload);

        // Parse TLV tags properly to avoid regex corruption of MCC (Tag 52: 5411)
        $pos = 0;
        $len = strlen($emv);
        $tags = [];
        $tag_order = [];

        while ($pos < $len) {
            if ($pos + 4 > $len)
                break;
            $tag = substr($emv, $pos, 2);
            $vlen = (int) substr($emv, $pos + 2, 2);
            $pos += 4;
            if ($pos + $vlen > $len)
                break;
            $val = substr($emv, $pos, $vlen);
            $pos += $vlen;

            // Skip existing amount tag 54 to replace it with new amount
            if ($tag === '54')
                continue;

            $tags[$tag] = $val;
            $tag_order[] = $tag;
        }

        // Set Tag 01 to Dynamic Initiation Mode (12) so mobile apps auto-fill amount
        $tags['01'] = '12';

        // Set Tag 54 (Amount)
        $tags['54'] = $clean_amount;

        // Reconstruct payload in standard EMVCo tag order
        $all_tags = array_unique(array_merge($tag_order, ['01', '54']));
        usort($all_tags, function ($a, $b) {
            return (int) $a - (int) $b;
        });

        $reconstructed = '';
        foreach ($all_tags as $t) {
            if (isset($tags[$t])) {
                $v = $tags[$t];
                $vlen = sprintf("%02d", strlen($v));
                $reconstructed .= $t . $vlen . $v;
            }
        }

        // Compute & append CRC-16 (Tag 6304)
        $to_crc = $reconstructed . "6304";
        $crc = strtoupper(sprintf("%04X", emvco_crc16_calc($to_crc)));
        $final_payload = $to_crc . $crc;

        return "https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=0&data=" . urlencode($final_payload);
    }

    // 2. If URI / UPI link (e.g. upi://pay?... or fonepay://... or https://...)
    if (strpos($payload, '://') !== false || strpos($payload, 'pa=') !== false) {
        $sep = (strpos($payload, '?') !== false) ? '&' : '?';
        $final_payload = $payload . $sep . "am=" . $clean_amount . "&tn=" . urlencode($txn_no);
        return "https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=" . urlencode($final_payload);
    }

    // 3. If uploaded local static QR image file exists AND no EMVCo text payload is set, use image file
    if (!empty($raw_text_or_image) && empty($qr_text)) {
        if (file_exists($raw_text_or_image))
            return $raw_text_or_image;
        if (file_exists('../' . $raw_text_or_image))
            return '../' . $raw_text_or_image;
    }

    // 4. Default dynamic QR fallback payload
    $merchant = !empty($payload) ? $payload : (!empty($company_name) ? $company_name : 'Merchant');
    $final_payload = "PAYMENT | Merchant: " . $merchant . " | Ref: " . $txn_no . " | Amount: NPR " . $clean_amount;
    return "https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=" . urlencode($final_payload);
}

function emvco_crc16_calc($str)
{
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($str); $i++) {
        $x = (($crc >> 8) ^ ord($str[$i])) & 0xFF;
        $x ^= $x >> 4;
        $crc = (($crc << 8) ^ ($x << 12) ^ ($x << 5) ^ $x) & 0xFFFF;
    }
    return $crc;
}

function getNextTransactionNumber($type)
{
    $db = db();

    // Fetch settings from system_info
    $prefix = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'ref_{$type}_prefix'")['meta_value'] ?? null;

    // Default prefixes if not set
    if ($prefix === null) {
        $defaults = [
            'customer_invoice' => 'INV',
            'vendor_bill' => 'BILL',
            'customer_payment' => 'CPAY',
            'vendor_payment' => 'VPAY',
            'journal_entry' => 'JE',
            'expense' => 'EXP',
            'purchase_order' => 'PO',
            'item' => 'ITM',
            'customer' => 'CUS',
            'vendor' => 'VEND',
            'inventory_adjustment' => 'ADJ',
            'account_transfer' => 'XFER',
            'inventory_transfer' => 'ITR'
        ];
        $prefix = $defaults[$type] ?? 'TXN';
    }

    $sep = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'ref_{$type}_sep'")['meta_value'] ?? '-';
    $next = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'ref_{$type}_next'")['meta_value'] ?? '1';
    $pad = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'ref_{$type}_pad'")['meta_value'] ?? '4';

    return $prefix . $sep . str_pad($next, (int) $pad, '0', STR_PAD_LEFT);
}

/**
 * Increments the next number in system_info
 */
function incrementTransactionNumber($type)
{
    $db = db();
    $key = "ref_{$type}_next";

    $row = $db->fetchOne("SELECT id, meta_value FROM system_info WHERE meta_field = ?", [$key]);

    if ($row) {
        $next = (int) $row['meta_value'] + 1;
        $db->execute("UPDATE system_info SET meta_value = ? WHERE id = ?", [$next, $row['id']]);
    } else {
        // If it doesn't exist, start from 2 (since 1 was just used)
        $db->execute("INSERT INTO system_info (meta_field, meta_value) VALUES (?, '2')", [$key]);
    }
}

/**
 * Gets a preference value from system_info
 */
function get_accounting_preference($key)
{
    $db = db();
    $row = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = ?", [$key]);
    return $row['meta_value'] ?? null;
}

/**
 * Resolves the effective account for a given master record and preference type.
 * Priority: Master Record -> System Preference
 * 
 * $type can be: 'income', 'cogs', 'inventory', 'receivable', 'payable'
 */
function get_effective_account($master_id, $type)
{
    $db = db();
    $pref_key = "default_{$type}_account";

    // Map internal types to column names and tables
    $mapping = [
        'income' => ['table' => 'items', 'col' => 'income_account_id'],
        'cogs' => ['table' => 'items', 'col' => 'cogs_account_id'],
        'inventory' => ['table' => 'items', 'col' => 'inventory_account_id'],
        'receivable' => ['table' => 'customers', 'col' => 'receivable_account_id'],
        'payable' => ['table' => 'vendors', 'col' => 'payable_account_id'],
    ];

    if (!empty($master_id) && isset($mapping[$type])) {
        $m = $mapping[$type];
        $col = $m['col'];
        $table = $m['table'];

        $master_acc = $db->fetchOne("SELECT $col FROM $table WHERE id = ?", [$master_id]);
        if ($master_acc && !empty($master_acc[$col])) {
            return $master_acc[$col];
        }
    }

    // Fallback to system preference
    // Handle special naming if necessary (e.g. default_ar_account instead of default_receivable_account)
    $special_prefs = [
        'receivable' => 'default_ar_account',
        'payable' => 'default_ap_account',
        'inventory' => 'default_asset_account' // existing naming in code
    ];

    $final_pref_key = $special_prefs[$type] ?? $pref_key;
    $pref = get_accounting_preference($final_pref_key);

    if (!empty($pref)) {
        return $pref;
    }

    throw new Exception("Account of type '$type' is not configured for record '$master_id', and default system preference '$final_pref_key' is missing.");
}

/**
 * Universal UUID Generator
 */
if (!function_exists('generate_uuid')) {
    function generate_uuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

/**
 * Resolves payment method ('cash', 'esewa', 'khalti', 'bank_transfer') from account name.
 */
if (!function_exists('resolve_payment_method')) {
    function resolve_payment_method($account_name)
    {
        $name = strtolower($account_name ?? '');
        if (strpos($name, 'cash') !== false) {
            return 'cash';
        } elseif (strpos($name, 'esewa') !== false) {
            return 'esewa';
        } elseif (strpos($name, 'khalti') !== false) {
            return 'khalti';
        }
        return 'bank_transfer';
    }
}


/**
 * Calculates fiscal year, month and period string from date
 */
function calculate_fiscal_info($date)
{
    $time = strtotime($date);
    return [
        'year' => date('Y', $time),
        'month' => date('m', $time),
        'period' => date('Y-m', $time)
    ];
}




/**
 * Converts a number into words (South Asian System: Lakhs/Crores)
 */
function amount_in_words($amount)
{
    $amount = (float) $amount;
    if (abs($amount) < 0.001)
        return "Zero Rupees Only";
    $is_neg = $amount < 0;
    $amount = abs($amount);

    $no = (int) floor($amount);
    $point = (int) round(($amount - $no) * 100);

    $words = [
        0 => '',
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen',
        20 => 'Twenty',
        30 => 'Thirty',
        40 => 'Forty',
        50 => 'Fifty',
        60 => 'Sixty',
        70 => 'Seventy',
        80 => 'Eighty',
        90 => 'Ninety'
    ];
    $digits = ['', 'Hundred', 'Thousand', 'Lakh', 'Crore'];

    $str = [];
    $i = 0;
    $digits_len = strlen((string) $no);

    while ($i < $digits_len) {
        $divider = ($i == 2) ? 10 : 100;
        $num = (int) floor($no % $divider);
        $no = (int) floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($num) {
            $hundred = (count($str) == 1 && !empty($str[0])) ? ' and ' : '';
            $d_label = $digits[count($str)] ?? '';
            if ($num < 21) {
                $w = $words[$num] ?? '';
                $str[] = trim("$w $d_label $hundred");
            } else {
                $tens = (int) (floor($num / 10) * 10);
                $ones = (int) ($num % 10);
                $w = trim(($words[$tens] ?? '') . ' ' . ($words[$ones] ?? ''));
                $str[] = trim("$w $d_label $hundred");
            }
        } else {
            $str[] = '';
        }
    }

    $str = array_filter(array_reverse($str));
    $result = trim(implode(' ', $str));
    if (empty($result))
        $result = "Zero";

    $points = '';
    if ($point > 0) {
        $tens = (int) (floor($point / 10) * 10);
        $ones = (int) ($point % 10);
        $p_str = trim(($words[$tens] ?? '') . ' ' . ($words[$ones] ?? ''));
        $points = " and " . $p_str . " Paisa";
    }

    $prefix = $is_neg ? "Minus " : "";
    return $prefix . $result . " Rupees" . $points . " Only";
}

/**
 * Synchronizes the opening balances of accounts from the accounts table
 * to a balanced, posted journal entry header ('OPENING-BALANCES').
 */
function sync_opening_balance_journal_entries($pdo, $date = null, $location_id = null)
{
    // 1. Fetch all accounts with non-zero opening balance
    $stmt = $pdo->prepare("SELECT id, account_name, normal_balance, opening_balance FROM accounts WHERE opening_balance != 0.00 AND is_deleted = 0");
    $stmt->execute();
    $opening_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Check if the OPENING-BALANCES transaction header exists
    $stmt = $pdo->prepare("SELECT id, location_id FROM transaction_headers WHERE txn_number = 'OPENING-BALANCES'");
    $stmt->execute();
    $header = $stmt->fetch(PDO::FETCH_ASSOC);
    $header_id = $header ? $header['id'] : null;

    if (empty($opening_accounts)) {
        // If no opening balances configured, clean up any existing journal entries and the header
        if ($header_id) {
            $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$header_id]);
            $pdo->prepare("DELETE FROM transaction_headers WHERE id = ?")->execute([$header_id]);
        }
        return;
    }

    // Determine location_id fallback if not provided
    if (empty($location_id)) {
        if ($header && !empty($header['location_id'])) {
            $location_id = $header['location_id'];
        } elseif (function_exists('get_user_default_location_id')) {
            $location_id = get_user_default_location_id();
        }
    }

    // Find a valid user ID to satisfy foreign key constraint
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        $stmt_user = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt_user->execute([$userId]);
        if (!$stmt_user->fetch()) {
            $userId = null;
        }
    }
    if (!$userId) {
        // Fallback: pick the first admin user, or any active user
        $stmt_user = $pdo->query("SELECT id FROM users ORDER BY (role = 'admin') DESC LIMIT 1");
        $user_row = $stmt_user->fetch(PDO::FETCH_ASSOC);
        $userId = $user_row ? $user_row['id'] : 'usr-admin-001';
    }

    // Determine target date
    if ($date) {
        $txn_date = $date;
    } else {
        if ($header_id) {
            $stmt_date = $pdo->prepare("SELECT txn_date FROM transaction_headers WHERE id = ?");
            $stmt_date->execute([$header_id]);
            $hdr_date = $stmt_date->fetch(PDO::FETCH_ASSOC);
            $txn_date = $hdr_date ? $hdr_date['txn_date'] : (date('Y') . '-01-01');
        } else {
            $txn_date = date('Y') . '-01-01';
        }
    }
    $fiscal = calculate_fiscal_info($txn_date);
    $fiscal_year = $fiscal['year'];
    $fiscal_month = $fiscal['month'];
    $fiscal_period = $fiscal['period'];

    if (!$header_id) {
        $header_id = 'opening-balances-txn-uuid';
        $stmt = $pdo->prepare("
            INSERT INTO transaction_headers 
            (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, created_by, net_amount, location_id) 
            VALUES (?, 'OPENING-BALANCES', 'Journal', ?, ?, ?, ?, 'posted', 'System Opening Balances', ?, 0.00, ?)
        ");
        $stmt->execute([$header_id, $txn_date, $fiscal_year, $fiscal_month, $fiscal_period, $userId, $location_id]);
    } else {
        // Clear existing lines for this header
        $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$header_id]);
        // Update header details just in case
        $stmt = $pdo->prepare("
            UPDATE transaction_headers 
            SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, location_id = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$txn_date, $fiscal_year, $fiscal_month, $fiscal_period, $location_id, $header_id]);
    }

    $total_debit = 0.00;
    $total_credit = 0.00;
    $entries = [];

    // 3. Prepare journal entry rows for each account
    foreach ($opening_accounts as $acc) {
        $balance = (float) $acc['opening_balance'];
        $normal = strtolower($acc['normal_balance']);

        $entry_type = 'debit';
        // For credit normal accounts, a positive opening balance is credit, negative is debit
        if ($normal === 'credit') {
            if ($balance > 0) {
                $entry_type = 'credit';
            } else {
                $entry_type = 'debit';
                $balance = abs($balance);
            }
        } else { // debit normal accounts (assets, expenses)
            if ($balance > 0) {
                $entry_type = 'debit';
            } else {
                $entry_type = 'credit';
                $balance = abs($balance);
            }
        }

        if ($balance == 0.00)
            continue;

        if ($entry_type === 'debit') {
            $total_debit += $balance;
        } else {
            $total_credit += $balance;
        }

        $entries[] = [
            'id' => generate_uuid(),
            'account_id' => $acc['id'],
            'entry_type' => $entry_type,
            'amount' => $balance,
            'memo' => 'Opening Balance for ' . $acc['account_name']
        ];
    }

    // 4. Handle double-entry balancing using Opening Balance account ('Opening Balance') or Owner Capital (acc-3100)
    $difference = $total_debit - $total_credit;
    if (abs($difference) > 0.001) {
        // Find offset account (Opening Balance first)
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE account_name = 'Opening Balance'");
        $stmt->execute();
        $offset_acc = $stmt->fetch(PDO::FETCH_ASSOC);
        $offset_id = $offset_acc ? $offset_acc['id'] : null;

        if (!$offset_id) {
            // Fallback: search for Owner Capital (acc-3100 or another equity account)
            $stmt = $pdo->prepare("SELECT id FROM accounts WHERE id = 'acc-3100' OR account_name LIKE '%Capital%'");
            $stmt->execute();
            $offset_acc = $stmt->fetch(PDO::FETCH_ASSOC);
            $offset_id = $offset_acc ? $offset_acc['id'] : null;
        }

        if (!$offset_id) {
            // Fallback: search for any equity account
            $stmt = $pdo->prepare("SELECT id FROM accounts WHERE account_type = 'equity' AND is_deleted = 0 LIMIT 1");
            $stmt->execute();
            $fallback_acc = $stmt->fetch(PDO::FETCH_ASSOC);
            $offset_id = $fallback_acc ? $fallback_acc['id'] : null;
        }

        if (!$offset_id) {
            // If still not found, create a new Opening Balance account
            $offset_id = 'acc-open';
            $stmt = $pdo->prepare("
                INSERT INTO accounts 
                (id, account_code, account_name, account_type, account_subtype, normal_balance, parent_account_id, currency, is_active) 
                VALUES ('acc-open', 'open', 'Opening Balance', 'equity', 'other', 'credit', NULL, 'NPR', 1)
            ");
            $stmt->execute();
        }

        $offset_type = $difference > 0 ? 'credit' : 'debit';
        $offset_amount = abs($difference);

        if ($offset_type === 'debit') {
            $total_debit += $offset_amount;
        } else {
            $total_credit += $offset_amount;
        }

        $entries[] = [
            'id' => generate_uuid(),
            'account_id' => $offset_id,
            'entry_type' => $offset_type,
            'amount' => $offset_amount,
            'memo' => 'Opening Balance Equity Offset'
        ];
    }

    // 5. Insert all journal entries
    $stmt = $pdo->prepare("
        INSERT INTO journal_entries 
        (id, header_id, account_id, entry_type, amount, memo, entry_date, fiscal_period, fiscal_year, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($entries as $e) {
        $stmt->execute([
            $e['id'],
            $header_id,
            $e['account_id'],
            $e['entry_type'],
            $e['amount'],
            $e['memo'],
            $txn_date,
            $fiscal_period,
            $fiscal_year,
            $userId
        ]);
    }

    // 6. Update net_amount of header
    $net_amount = max($total_debit, $total_credit);
    $pdo->prepare("UPDATE transaction_headers SET net_amount = ? WHERE id = ?")->execute([$net_amount, $header_id]);
}

/**
 * Regenerates the daily POS summary invoice and payment for a given date
 */
function sync_daily_pos_summary($date)
{
    $db = db();
    $pdo = $db->getConnection();

    $today_str = date('Ymd', strtotime($date));
    $summary_invoice_no = "INV-POS-" . $today_str;
    $summary_payment_no = "PAY-POS-" . $today_str;

    $fiscal = calculate_fiscal_info($date);

    // Check if the daily summary invoice exists and is not deleted
    $existing_inv = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_invoice' AND is_deleted = 0", [$summary_invoice_no]);
    $existing_pay = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_payment' AND is_deleted = 0", [$summary_payment_no]);

    // Load all active POS entries for this date
    $pos_entries = $db->fetchAll("SELECT id, customer_id FROM pos_entry WHERE DATE(date_time) = ? AND is_deleted = 0", [$date]);

    // If no active POS entries exist for this date, delete any existing summaries
    if (empty($pos_entries)) {
        if ($existing_inv) {
            $inv_id = $existing_inv['id'];
            $pdo->prepare("DELETE FROM transaction_lines WHERE header_id = ?")->execute([$inv_id]);
            $pdo->prepare("DELETE FROM customer_invoices WHERE header_id = ?")->execute([$inv_id]);
            $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$inv_id]);
            $pdo->prepare("DELETE FROM transaction_headers WHERE id = ?")->execute([$inv_id]);
        }
        if ($existing_pay) {
            $pay_id = $existing_pay['id'];
            $pdo->prepare("DELETE FROM payments WHERE header_id = ?")->execute([$pay_id]);
            $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$pay_id]);
            $pdo->prepare("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?")->execute([$pay_id, $pay_id]);
            $pdo->prepare("DELETE FROM transaction_headers WHERE id = ?")->execute([$pay_id]);
        }
        return;
    }

    $user_id = $_SESSION['user_id'] ?? 'usr-admin-001';

    $def_location_id = get_user_default_location_id();

    // Resolve daily summary IDs
    if ($existing_inv) {
        $invoice_header_id = $existing_inv['id'];
        $pdo->prepare("UPDATE transaction_headers SET location_id = ? WHERE id = ? AND (location_id IS NULL OR location_id = '')")->execute([$def_location_id, $invoice_header_id]);
    } else {
        $invoice_header_id = generate_uuid();
        $customer_id = $pos_entries[0]['customer_id'];

        $pdo->prepare("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type, location_id)
            VALUES (?, ?, 'customer_invoice', ?, ?, ?, ?, 'paid', ?, ?, 'customer', ?)
        ")->execute([$invoice_header_id, $summary_invoice_no, $date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $user_id, $customer_id, $def_location_id]);
    }

    if ($existing_pay) {
        $payment_header_id = $existing_pay['id'];
        $pdo->prepare("UPDATE transaction_headers SET location_id = ? WHERE id = ? AND (location_id IS NULL OR location_id = '')")->execute([$def_location_id, $payment_header_id]);
    } else {
        $payment_header_id = generate_uuid();
        $customer_id = $pos_entries[0]['customer_id'];

        $pdo->prepare("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type, location_id)
            VALUES (?, ?, 'customer_payment', ?, ?, ?, ?, 'posted', ?, ?, 'customer', ?)
        ")->execute([$payment_header_id, $summary_payment_no, $date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $user_id, $customer_id, $def_location_id]);
    }

    // Clear child details (rebuild them dynamically)
    $pdo->prepare("DELETE FROM transaction_lines WHERE header_id = ?")->execute([$invoice_header_id]);
    $pdo->prepare("DELETE FROM customer_invoices WHERE header_id = ?")->execute([$invoice_header_id]);
    $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$invoice_header_id]);

    $pdo->prepare("DELETE FROM payments WHERE header_id = ?")->execute([$payment_header_id]);
    $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$payment_header_id]);
    $pdo->prepare("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?")->execute([$payment_header_id, $payment_header_id]);

    // 1. Aggregate Items
    $agg_items = $db->fetchAll("
        SELECT 
            pi.item_id, 
            SUM(pi.quantity) as total_qty, 
            SUM(pi.amount) as total_gross, 
            SUM(pi.discount) as total_discount, 
            SUM(pi.tax) as total_tax, 
            SUM(pi.net_amount) as total_net, 
            SUM(pi.quantity * i.cost_price) as total_cogs 
        FROM pos_items pi 
        JOIN pos_entry pe ON pi.pos_id = pe.id 
        JOIN items i ON pi.item_id = i.id 
        WHERE DATE(pe.date_time) = ? AND pe.is_deleted = 0 
        GROUP BY pi.item_id
    ", [$date]);

    $summary_subtotal = 0;
    $summary_discount = 0;
    $summary_tax = 0;
    $summary_total = 0;
    $summary_cogs = 0;
    $max_line = 0;

    $sales_distributions = [];
    $cogs_distributions = [];
    $inv_distributions = [];

    foreach ($agg_items as $item) {
        $item_id = $item['item_id'];
        $qty = (float) $item['total_qty'];
        $gross = (float) $item['total_gross'];
        $disc = (float) $item['total_discount'];
        $tax = (float) $item['total_tax'];
        $net = (float) $item['total_net'];
        $cogs = (float) $item['total_cogs'];

        $summary_subtotal += $gross;
        $summary_discount += $disc;
        $summary_tax += $tax;
        $summary_total += $net;
        $summary_cogs += $cogs;

        $line_income_account = get_effective_account($item_id, 'income') ?: 'acc-4100';
        $line_cogs_account = get_effective_account($item_id, 'cogs') ?: 'acc-5100';
        $line_inv_account = get_effective_account($item_id, 'inventory') ?: 'acc-1200';

        $sales_distributions[$line_income_account] = ($sales_distributions[$line_income_account] ?? 0) + $gross;
        if ($cogs > 0) {
            $cogs_distributions[$line_cogs_account] = ($cogs_distributions[$line_cogs_account] ?? 0) + $cogs;
            $inv_distributions[$line_inv_account] = ($inv_distributions[$line_inv_account] ?? 0) + $cogs;
        }

        $max_line++;
        // Do not change rate of items when discount is given; keep unit_price as gross rate and record discount on invoice header
        $unit_price_full = $qty > 0 ? $gross / $qty : 0;
        $gross_profit_excl_tax = ($net - $tax) - $cogs; // true margin, tax excluded
        $pdo->prepare("
            INSERT INTO transaction_lines (id, header_id, item_id, account_id, line_number, quantity, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([generate_uuid(), $invoice_header_id, $item_id, $line_income_account, $max_line, $qty, $unit_price_full, ($gross > 0) ? ($tax / $gross) * 100 : 0, $tax, $gross, $qty > 0 ? $cogs / $qty : 0, $gross_profit_excl_tax, $user_id]);
    }

    // Write customer_invoices record
    $customer_id = $pos_entries[0]['customer_id'];
    $pdo->prepare("
        INSERT INTO customer_invoices (id, header_id, customer_id, invoice_date, due_date, invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status, sale_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'paid', 'cash')
    ")->execute([generate_uuid(), $invoice_header_id, $customer_id, $date, $date, $summary_invoice_no, $summary_subtotal, $summary_discount, $summary_tax, $summary_total, $summary_total]);

    // 2. Aggregate Payments
    $agg_payments = $db->fetchAll("
        SELECT pp.account_id, SUM(pp.amount) as total_amount 
        FROM pos_payments pp 
        JOIN pos_entry pe ON pp.pos_id = pe.id 
        WHERE DATE(pe.date_time) = ? AND pe.is_deleted = 0 
        GROUP BY pp.account_id
    ", [$date]);

    foreach ($agg_payments as $pay) {
        $acc_id = $pay['account_id'];
        $pay_amount = (float) $pay['total_amount'];

        $acc_info = $db->fetchOne("SELECT account_name FROM accounts WHERE id = ?", [$acc_id]);
        $mapped_method = resolve_payment_method($acc_info['account_name'] ?? '');

        $pdo->prepare("
            INSERT INTO payments (id, header_id, payment_type, customer_id, payment_method, bank_account_id, amount, payment_date, created_by, applied_to_txn_id)
            VALUES (?, ?, 'customer_payment', ?, ?, ?, ?, ?, ?, ?)
        ")->execute([generate_uuid(), $payment_header_id, $customer_id, $mapped_method, $acc_id, $pay_amount, $date, $user_id, $invoice_header_id]);
    }

    // Insert link
    $pdo->prepare("
        INSERT INTO transaction_links (id, parent_id, child_id, link_type)
        VALUES (?, ?, ?, ?)
    ")->execute([generate_uuid(), $payment_header_id, $invoice_header_id, 'payment:' . $summary_total]);

    // 3. Invoice GL
    $ar_account = get_accounting_preference('default_ar_account') ?: 'acc-1100';
    $tax_account = get_accounting_preference('default_tax_account') ?: 'acc-2200';
    $disc_account = get_accounting_preference('default_discount_account') ?: 'acc-6160';

    $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)")
        ->execute([generate_uuid(), $invoice_header_id, $ar_account, $summary_total, 'Daily POS Sales Invoice ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);

    if ($summary_discount > 0) {
        $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)")
            ->execute([generate_uuid(), $invoice_header_id, $disc_account, $summary_discount, 'Daily POS Invoice Discount ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    foreach ($sales_distributions as $inc_acct => $amt) {
        $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)")
            ->execute([generate_uuid(), $invoice_header_id, $inc_acct, $amt, 'Daily POS Invoice Sales ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    if ($summary_tax > 0) {
        $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)")
            ->execute([generate_uuid(), $invoice_header_id, $tax_account, $summary_tax, 'Daily POS Invoice VAT ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    foreach ($cogs_distributions as $cogs_acct => $amt) {
        $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)")
            ->execute([generate_uuid(), $invoice_header_id, $cogs_acct, $amt, 'Daily POS Invoice COGS ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }
    foreach ($inv_distributions as $inv_acct => $amt) {
        $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)")
            ->execute([generate_uuid(), $invoice_header_id, $inv_acct, $amt, 'Daily POS Invoice Inventory Out ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    // 4. Payment GL
    $payment_total = 0.0;
    foreach ($agg_payments as $pay) {
        $payment_total += (float) $pay['total_amount'];
    }
    $discrepancy = $summary_total - $payment_total;

    foreach ($agg_payments as $pay) {
        $entry_type = ($pay['total_amount'] >= 0) ? 'debit' : 'credit';
        $abs_amount = abs($pay['total_amount']);
        if ($abs_amount > 0) {
            $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([generate_uuid(), $payment_header_id, $pay['account_id'], $entry_type, $abs_amount, 'Daily POS Invoice Payment ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
        }
    }

    if (abs($discrepancy) > 0.005) {
        $misc_expense_acct = 'acc-6170'; // Miscellaneous Expenses
        $entry_type = ($discrepancy > 0) ? 'debit' : 'credit'; // Positive is shortage (debit expense), negative is overage (credit)
        $abs_discrepancy = abs($discrepancy);
        $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([generate_uuid(), $payment_header_id, $misc_expense_acct, $entry_type, $abs_discrepancy, 'POS Daily Cash Discrepancy ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    $pdo->prepare("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)")
        ->execute([generate_uuid(), $payment_header_id, $ar_account, $summary_total, 'POS Daily Payment ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);

    // 5. Update transaction_headers with the correct net_amount and customer
    $pdo->prepare("UPDATE transaction_headers SET net_amount = ?, party_id = ? WHERE id = ?")
        ->execute([$summary_total, $customer_id, $invoice_header_id]);
    $pdo->prepare("UPDATE transaction_headers SET net_amount = ?, party_id = ? WHERE id = ?")
        ->execute([$summary_total, $customer_id, $payment_header_id]);
}

/**
 * Checks if the transaction date falls within a closed fiscal year.
 * Throws an Exception if the year is closed/locked.
 */
function check_fiscal_year_lock($date)
{
    if (empty($date))
        return;
    $db = db();
    try {
        $fy = $db->fetchOne("
            SELECT name FROM fiscal_years 
            WHERE :date BETWEEN start_date AND end_date 
              AND status = 'closed'
        ", ['date' => $date]);

        if ($fy) {
            throw new Exception("The date '{$date}' falls within the closed Fiscal Year '{$fy['name']}'. Modification of transactions in closed fiscal years is strictly prohibited.");
        }
    } catch (PDOException $e) {
        // If the fiscal_years table doesn't exist yet (e.g. during early initialization/testing), ignore
    }
}

/**
 * Helper to check permissions based on user roles
 */
function has_permission($permission)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $role = $_SESSION['role'] ?? '';

    if (empty($role))
        return false;
    if ($role === 'admin')
        return true;

    switch ($permission) {
        case 'view_fiscal_year':
        case 'print_closing_report':
        case 'view_closing_journal':
            return in_array($role, ['admin', 'manager', 'accountant']);
        case 'create_fiscal_year':
        case 'edit_fiscal_year':
        case 'close_fiscal_year':
            return in_array($role, ['admin', 'accountant', 'manager']);
        case 'reopen_fiscal_year':
            return in_array($role, ['admin', 'accountant']);
        case 'delete_fiscal_year':
            return $role === 'admin';
        default:
            return false;
    }
}

/**
 * Helper for financial reports to find the correct aggregation start date
 * to prevent double-counting prior transactions.
 */
function get_report_start_date($as_of)
{
    $db = db();
    try {
        $fy = $db->fetchOne("
            SELECT start_date, status FROM fiscal_years 
            WHERE :as_of BETWEEN start_date AND end_date
        ", ['as_of' => $as_of]);

        if (!$fy) {
            return '1970-01-01';
        }

        if ($fy['status'] === 'closed') {
            return $fy['start_date'];
        }

        // Find earliest unclosed fiscal year
        $earliest = $db->fetchOne("
            SELECT start_date FROM fiscal_years 
            WHERE status IN ('open', 'reopened') 
            ORDER BY start_date ASC 
            LIMIT 1
        ");

        return $earliest ? $earliest['start_date'] : $fy['start_date'];
    } catch (Exception $e) {
        return '1970-01-01';
    }
}

/**
 * Recalculates document amounts (amount_paid, balance_due) and statuses
 * (payment_status, header status) whenever a payment is created, edited, or deleted.
 * Supports Customer Invoices, Vendor Bills, and Tagged Journal Entries.
 */
function recalculate_document_payment_status($doc_header_id, $pdo = null)
{
    if (empty($doc_header_id))
        return;
    $db = db();
    if (!$pdo) {
        $pdo = $db->getConnection();
    }

    // 1. Fetch document header info
    $stmt = $pdo->prepare("SELECT id, txn_type, status, party_id, party_type FROM transaction_headers WHERE id = ?");
    $stmt->execute([$doc_header_id]);
    $header = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$header)
        return;

    $txn_type = $header['txn_type'] ?? '';

    // 2. Sum active payment links applied to this document (child_id)
    $stmt_pay = $pdo->prepare("
        SELECT COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0.00) as total_paid
        FROM transaction_links tl
        JOIN transaction_headers ph ON tl.parent_id = ph.id
        WHERE tl.child_id = ? AND tl.link_type LIKE 'payment:%'
          AND ph.is_deleted = 0 AND ph.status NOT IN ('void', 'voided', 'draft')
    ");
    $stmt_pay->execute([$doc_header_id]);
    $total_paid = (float) ($stmt_pay->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0.00);

    // Also check direct payments in `payments` table (applied_to_txn_id)
    $stmt_pay2 = $pdo->prepare("
        SELECT COALESCE(SUM(p.amount), 0.00) as total_paid2
        FROM payments p
        JOIN transaction_headers ph ON p.header_id = ph.id
        WHERE p.applied_to_txn_id = ? AND ph.is_deleted = 0 AND ph.status NOT IN ('void', 'voided', 'draft')
    ");
    $stmt_pay2->execute([$doc_header_id]);
    $total_paid2 = (float) ($stmt_pay2->fetch(PDO::FETCH_ASSOC)['total_paid2'] ?? 0.00);

    $actual_paid = max($total_paid, $total_paid2);

    if ($txn_type === 'customer_invoice') {
        $stmt_inv = $pdo->prepare("SELECT total_amount FROM customer_invoices WHERE header_id = ?");
        $stmt_inv->execute([$doc_header_id]);
        $inv = $stmt_inv->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $total_amount = (float) $inv['total_amount'];
            $new_amount_paid = min($total_amount, $actual_paid);
            $new_balance_due = max(0.00, $total_amount - $actual_paid);

            $pay_status = 'unpaid';
            $hdr_status = 'open';
            if ($new_balance_due <= 0.01) {
                $pay_status = 'paid';
                $hdr_status = 'paid';
            } elseif ($new_amount_paid > 0.01) {
                $pay_status = 'partial';
                $hdr_status = 'partial';
            }

            $pdo->prepare("UPDATE customer_invoices SET amount_paid = ?, balance_due = ?, payment_status = ? WHERE header_id = ?")
                ->execute([$new_amount_paid, $new_balance_due, $pay_status, $doc_header_id]);
            $pdo->prepare("UPDATE transaction_headers SET status = ? WHERE id = ?")
                ->execute([$hdr_status, $doc_header_id]);
        }
    } elseif ($txn_type === 'vendor_bill') {
        $stmt_bill = $pdo->prepare("SELECT total_amount FROM vendor_bills WHERE header_id = ?");
        $stmt_bill->execute([$doc_header_id]);
        $bill = $stmt_bill->fetch(PDO::FETCH_ASSOC);
        if ($bill) {
            $total_amount = (float) $bill['total_amount'];
            $new_amount_paid = min($total_amount, $actual_paid);
            $new_balance_due = max(0.00, $total_amount - $actual_paid);

            $pay_status = 'unpaid';
            $hdr_status = 'open';
            if ($new_balance_due <= 0.01) {
                $pay_status = 'paid';
                $hdr_status = 'paid';
            } elseif ($new_amount_paid > 0.01) {
                $pay_status = 'partial';
                $hdr_status = 'partial';
            }

            $pdo->prepare("UPDATE vendor_bills SET amount_paid = ?, balance_due = ?, payment_status = ? WHERE header_id = ?")
                ->execute([$new_amount_paid, $new_balance_due, $pay_status, $doc_header_id]);
            $pdo->prepare("UPDATE transaction_headers SET status = ? WHERE id = ?")
                ->execute([$hdr_status, $doc_header_id]);
        }
    } elseif (in_array($txn_type, ['Journal', 'journal_entry'])) {
        $stmt_je = $pdo->prepare("
            SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as net_debit,
                   SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE 0 END) as total_debit,
                   SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE 0 END) as total_credit
            FROM journal_entries j WHERE j.header_id = ?
        ");
        $stmt_je->execute([$doc_header_id]);
        $je = $stmt_je->fetch(PDO::FETCH_ASSOC);

        $party_type = $header['party_type'] ?? '';
        if ($party_type === 'customer') {
            $total_amount = (float) ($je['total_debit'] ?? 0.00);
        } elseif ($party_type === 'vendor') {
            $total_amount = (float) ($je['total_credit'] ?? 0.00);
        } else {
            $total_amount = abs((float) ($je['net_debit'] ?? 0.00));
        }

        $new_balance_due = max(0.00, $total_amount - $actual_paid);
        $hdr_status = 'posted';
        if ($actual_paid >= ($total_amount - 0.01) && $total_amount > 0) {
            $hdr_status = 'paid';
        } elseif ($actual_paid > 0.01) {
            $hdr_status = 'partial';
        }

        $pdo->prepare("UPDATE transaction_headers SET status = ? WHERE id = ?")
            ->execute([$hdr_status, $doc_header_id]);
    }
    clear_dashboard_cache();
}

/**
 * Clears dashboard KPI cache so that dashboard data updates in real-time
 * whenever any transaction is created, updated, or deleted.
 */
if (!function_exists('clear_dashboard_cache')) {
    function clear_dashboard_cache()
    {
        try {
            $db = db();
            $db->execute("DELETE FROM dashboard_kpi_cache");
        } catch (Exception $e) {
            // Silently ignore if table doesn't exist
        }
    }
}

/**
 * ══════════════════════════════════════════════════════════════════
 * UNIFORM REUSABLE DROPDOWN HELPERS
 * ══════════════════════════════════════════════════════════════════
 */
if (!function_exists('get_dropdown_options')) {
    function get_dropdown_options($type, $db = null)
    {
        if (!$db)
            $db = db();
        switch ($type) {
            case 'categories':
                return $db->fetchAll("SELECT id, name, code FROM reference_codes WHERE type = 'category' AND is_active = 1 ORDER BY name ASC");
            case 'units':
                return $db->fetchAll("SELECT id, name, code FROM reference_codes WHERE type IN ('unit', 'units') AND is_active = 1 ORDER BY name ASC");
            case 'tax_codes':
                return $db->fetchAll("SELECT id, name, value FROM reference_codes WHERE type = 'tax_code' AND is_active = 1 ORDER BY value ASC");
            case 'customers':
                return $db->fetchAll("SELECT id, full_name as name, phone, customer_type FROM customers WHERE is_deleted = 0 AND is_active = 1 ORDER BY full_name ASC");
            case 'vendors':
                return $db->fetchAll("SELECT id, company_name as name, phone FROM vendors WHERE is_deleted = 0 AND is_active = 1 ORDER BY company_name ASC");
            case 'accounts':
                return $db->fetchAll("SELECT id, account_code, account_name as name, account_type, account_subtype, normal_balance FROM accounts WHERE is_deleted = 0 AND is_active = 1 ORDER BY account_name ASC");
            default:
                return [];
        }
    }
}

if (!function_exists('get_user_default_location_id')) {
    function get_user_default_location_id(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION['location_id'])) {
            return $_SESSION['location_id'];
        }
        $user_id = $_SESSION['user_id'] ?? null;
        if ($user_id) {
            $db = db();
            $user_loc = $db->fetchOne("SELECT location_id FROM users WHERE id = ?", [$user_id]);
            if (!empty($user_loc['location_id'])) {
                $_SESSION['location_id'] = $user_loc['location_id'];
                return $_SESSION['location_id'];
            }
        }
        // Fallback to system accounting preference
        if (function_exists('get_accounting_preference')) {
            $def = get_accounting_preference('default_location_id');
            if (!empty($def)) {
                $_SESSION['location_id'] = $def;
                return $_SESSION['location_id'];
            }
        }
        $loc = db()->fetchOne("SELECT id FROM locations WHERE is_default = 1 AND is_deleted = 0 LIMIT 1");
        if ($loc) {
            $_SESSION['location_id'] = $loc['id'];
            return $_SESSION['location_id'];
        }
        $loc2 = db()->fetchOne("SELECT id FROM locations WHERE is_active = 1 AND is_deleted = 0 ORDER BY name ASC LIMIT 1");
        $fallback = $loc2['id'] ?? '';
        if ($fallback) {
            $_SESSION['location_id'] = $fallback;
        }
        return $fallback;
    }
}

if (!function_exists('sync_and_get_item_inventory_balances')) {
    function sync_and_get_item_inventory_balances($db, string $item_id): array {
        $locations = $db->fetchAll("SELECT id, name, type FROM locations WHERE is_deleted = 0 AND is_active = 1 ORDER BY name ASC");
        $item = $db->fetchOne("SELECT cost_price FROM items WHERE id = ?", [$item_id]);
        $cost_price = (float)($item['cost_price'] ?? 0.00);

        foreach ($locations as $loc) {
            $loc_id = $loc['id'];

            // 1. Calculate live stock on hand for this location (including inventory transfers)
            $hdr_stock = (float)($db->fetchOne("
                SELECT COALESCE(SUM(CASE 
                    WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') AND h.location_id = ? THEN l.quantity 
                    WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') AND h.location_id = ? THEN -l.quantity 
                    WHEN h.txn_type = 'inventory_transfer' AND h.party_id = ? THEN l.quantity 
                    WHEN h.txn_type = 'inventory_transfer' AND h.location_id = ? THEN -l.quantity 
                    ELSE 0 END), 0) as qty
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                WHERE l.item_id = ? 
                  AND (h.location_id = ? OR (h.txn_type = 'inventory_transfer' AND h.party_id = ?))
                  AND h.is_deleted = 0 
                  AND h.status NOT IN ('void', 'voided', 'draft')
            ", [$loc_id, $loc_id, $loc_id, $loc_id, $item_id, $loc_id, $loc_id])['qty'] ?? 0);

            // Add POS Entries if matched by location
            // IMPORTANT: Only include POS entries whose items are NOT already in transaction_lines
            // (INV-POS-* consolidated invoices already capture daily POS items in hdr_stock above)
            // Match: pos_entry for same date/location where item appears in transaction_lines of an INV-POS header
            $pos_stock = (float)($db->fetchOne("
                SELECT COALESCE(SUM(-pi.quantity), 0) as qty
                FROM pos_items pi
                JOIN pos_entry pe ON pi.pos_id = pe.id
                LEFT JOIN users u ON pe.created_by = u.id
                WHERE pi.item_id = ? AND (pe.location_id = ? OR u.location_id = ?) AND pe.is_deleted = 0
                AND NOT EXISTS (
                    SELECT 1 FROM transaction_headers th
                    JOIN transaction_lines tl ON tl.header_id = th.id
                    WHERE th.txn_type = 'customer_invoice'
                      AND th.txn_number LIKE 'INV-POS%'
                      AND th.location_id = ?
                      AND DATE(th.txn_date) = DATE(pe.date_time)
                      AND tl.item_id = pi.item_id
                      AND th.is_deleted = 0
                )
            ", [$item_id, $loc_id, $loc_id, $loc_id])['qty'] ?? 0);

            $on_hand = max(0, $hdr_stock + $pos_stock);

            // 2. Committed Qty (open sales invoices / orders not completed)
            $committed = (float)($db->fetchOne("
                SELECT COALESCE(SUM(l.quantity), 0) as qty
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                WHERE l.item_id = ? AND h.location_id = ? AND h.txn_type IN ('customer_invoice', 'sales_order') AND h.status = 'draft' AND h.is_deleted = 0
            ", [$item_id, $loc_id])['qty'] ?? 0);

            // 3. On Order Qty (open purchase orders / vendor bills in draft or pending)
            $on_order = (float)($db->fetchOne("
                SELECT COALESCE(SUM(l.quantity), 0) as qty
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                WHERE l.item_id = ? AND h.location_id = ? AND h.txn_type IN ('vendor_bill', 'purchase_order') AND h.status = 'draft' AND h.is_deleted = 0
            ", [$item_id, $loc_id])['qty'] ?? 0);

            $available = max(0, $on_hand - $committed);

            // Upsert into inventory_balances table
            $existing = $db->fetchOne("SELECT id FROM inventory_balances WHERE item_id = ? AND location_id = ?", [$item_id, $loc_id]);
            if ($existing) {
                $db->execute("
                    UPDATE inventory_balances 
                    SET quantity_on_hand = ?, available_qty = ?, committed_qty = ?, on_order_qty = ?, average_cost = ?, last_updated = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ", [$on_hand, $available, $committed, $on_order, $cost_price, $existing['id']]);
            } else {
                $rec_id = generate_uuid();
                $db->execute("
                    INSERT INTO inventory_balances (id, item_id, location_id, quantity_on_hand, available_qty, committed_qty, on_order_qty, average_cost) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ", [$rec_id, $item_id, $loc_id, $on_hand, $available, $committed, $on_order, $cost_price]);
            }
        }

        // Sync total current_stock on items table
        $db->execute("
            UPDATE items 
            SET current_stock = (SELECT COALESCE(SUM(quantity_on_hand), 0) FROM inventory_balances WHERE item_id = ?) 
            WHERE id = ?
        ", [$item_id, $item_id]);

        // Return formatted inventory balances join locations
        return $db->fetchAll("
            SELECT ib.*, loc.name as location_name, loc.type as location_type
            FROM inventory_balances ib
            JOIN locations loc ON ib.location_id = loc.id
            WHERE ib.item_id = ? AND loc.is_deleted = 0
            ORDER BY loc.name ASC
        ", [$item_id]);
    }
}

/**
 * Auto-syncs POS transactions to Items & Customer Invoices every 5 minutes (300 seconds).
 */
if (!function_exists('auto_sync_pos_items_and_invoices')) {
    function auto_sync_pos_items_and_invoices(bool $force = false) {
        try {
            $db = db();
            $last_sync = 0;
            $row = $db->fetchOne("SELECT value FROM system_info WHERE `key` = 'last_pos_sync_timestamp'");
            if ($row) {
                $last_sync = (int)$row['value'];
            }

            $now = time();
            if ($force || ($now - $last_sync) >= 300) { // 5 minutes interval
                $today = date('Y-m-d');
                sync_daily_pos_summary($today);

                // Sync inventory balances for active items
                $items = $db->fetchAll("SELECT id FROM items WHERE is_deleted = 0 AND is_active = 1");
                foreach ($items as $it) {
                    sync_and_get_item_inventory_balances($db, $it['id']);
                }

                // Save last sync timestamp
                if ($row) {
                    $db->execute("UPDATE system_info SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE `key` = 'last_pos_sync_timestamp'", [(string)$now]);
                } else {
                    $db->execute("INSERT INTO system_info (id, `key`, value) VALUES (?, 'last_pos_sync_timestamp', ?)", [generate_uuid(), (string)$now]);
                }
            }
        } catch (Exception $e) {
            // Silently ignore exception during auto sync
        }
    }
}
?>