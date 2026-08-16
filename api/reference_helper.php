<?php
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        @ini_set('session.cookie_secure', '1');
    }
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
// Load tiered cache layer (safe to include multiple times)
if (!function_exists('sysinfo_get')) {
    require_once __DIR__ . '/system_cache.php';
}

/**
 * Security: CSRF Protection Engine
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function enforce_csrf_protection(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if ($token !== null && !verify_csrf_token($token)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Security Error: CSRF token validation failed.']);
            exit;
        }
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

/**
 * =========================================================================
 * SINGLE SOURCE OF TRUTH SUBLEDGER BALANCE FUNCTIONS
 * Guarantees 100% identical data across Dashboard KPI tiles, Customer Overview,
 * Supplier Overview, Master Lists, and Financial Reports.
 * =========================================================================
 */
function get_customer_net_balance($db, $customer_id, ?string $as_of = null, ?string $location_id = null): float {
    if (!$as_of) $as_of = date('Y-m-d');

    $loc_sql = "";
    if (!empty($location_id) && $location_id !== 'all') {
        $loc_sql = " AND th.location_id = " . $db->getConnection()->quote($location_id) . " ";
    }

    $sales = (float)($db->fetchOne("
        SELECT ((
            SELECT COALESCE(SUM(ci.total_amount), 0) 
            FROM customer_invoices ci 
            JOIN transaction_headers th ON ci.header_id = th.id 
            WHERE ci.customer_id = ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_date <= ? {$loc_sql}
        ) + (
            SELECT COALESCE(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END), 0)
            FROM journal_entries j
            JOIN transaction_headers th ON j.header_id = th.id
            WHERE j.party_id = ? AND j.party_type = 'customer'
              AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') 
              AND th.txn_type IN ('Journal', 'journal_entry', 'Opening Balance', 'Opening_Balance', 'opening_balance') AND th.txn_date <= ? {$loc_sql}
        ) + (
            SELECT COALESCE(SUM(p.amount), 0)
            FROM payments p
            JOIN transaction_headers th ON p.header_id = th.id
            WHERE p.customer_id = ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND p.payment_date <= ? {$loc_sql}
              AND th.id IN (
                  SELECT tl.parent_id FROM transaction_links tl
                  JOIN transaction_headers ch ON tl.child_id = ch.id
                  WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
              )
        ) - (
            SELECT COALESCE(SUM(COALESCE(cm.total_amount, th.net_amount)), 0)
            FROM transaction_headers th
            JOIN credit_memos cm ON cm.header_id = th.id
            WHERE cm.customer_id = ?
              AND th.txn_type IN ('credit_memo', 'Credit Memo')
              AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_date <= ? {$loc_sql}
        )) as total
    ", [$customer_id, $as_of, $customer_id, $as_of, $customer_id, $as_of, $customer_id, $as_of])['total'] ?? 0);

    $paid = (float)($db->fetchOne("
        SELECT COALESCE(SUM(p.amount), 0) as total
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id 
        WHERE p.customer_id = ?
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND p.payment_date <= ? {$loc_sql}
          AND th.id NOT IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ", [$customer_id, $as_of])['total'] ?? 0);

    return round($sales - $paid, 2);
}

function get_vendor_net_balance($db, $vendor_id, ?string $as_of = null, ?string $location_id = null): float {
    if (!$as_of) $as_of = date('Y-m-d');

    $loc_sql = "";
    if (!empty($location_id) && $location_id !== 'all') {
        $loc_sql = " AND th.location_id = " . $db->getConnection()->quote($location_id) . " ";
    }

    $purchases = (float)($db->fetchOne("
        SELECT ((
            SELECT COALESCE(SUM(vb.total_amount), 0) 
            FROM vendor_bills vb 
            JOIN transaction_headers th ON vb.header_id = th.id 
            WHERE vb.vendor_id = ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_date <= ? {$loc_sql}
        ) + (
            SELECT COALESCE(SUM(CASE WHEN j.entry_type='credit' THEN j.amount ELSE -j.amount END), 0)
            FROM journal_entries j
            JOIN transaction_headers th ON j.header_id = th.id
            WHERE j.party_id = ? AND (j.party_type = 'vendor' OR j.party_type IS NULL)
              AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') 
              AND th.txn_type IN ('Journal', 'journal_entry', 'Opening Balance', 'Opening_Balance', 'opening_balance') AND th.txn_date <= ? {$loc_sql}
        )) as total
    ", [$vendor_id, $as_of, $vendor_id, $as_of])['total'] ?? 0);

    $paid = (float)($db->fetchOne("
        SELECT COALESCE(SUM(p.amount), 0) as total
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id 
        WHERE p.vendor_id = ?
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND p.payment_date <= ? {$loc_sql}
    ", [$vendor_id, $as_of])['total'] ?? 0);

    return round($purchases - $paid, 2);
}

function get_total_receivables_balance($db, ?string $as_of = null, ?string $location_id = null): float {
    if (!$as_of) $as_of = date('Y-m-d');
    $customers = $db->fetchAll("SELECT id FROM customers WHERE is_deleted = 0 AND is_active = 1");
    $total = 0.0;
    foreach ($customers as $c) {
        $bal = get_customer_net_balance($db, $c['id'], $as_of, $location_id);
        if ($bal > 0) $total += $bal;
    }
    return round($total, 2);
}

function get_total_payables_balance($db, ?string $as_of = null, ?string $location_id = null): float {
    if (!$as_of) $as_of = date('Y-m-d');
    $vendors = $db->fetchAll("SELECT id FROM vendors WHERE is_deleted = 0 AND is_active = 1");
    $total = 0.0;
    foreach ($vendors as $v) {
        $bal = get_vendor_net_balance($db, $v['id'], $as_of, $location_id);
        if ($bal > 0) $total += $bal;
    }
    return round($total, 2);
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

function getNextTransactionNumber($type, $location_id = null)
{
    $db = db();

    // Default prefixes
    $defaults = [
        'customer_invoice'    => 'SI',
        'vendor_bill'         => 'VI',
        'customer_payment'    => 'CPAY',
        'vendor_payment'      => 'VPAY',
        'journal_entry'       => 'JV',
        'expense'             => 'EXP',
        'purchase_order'      => 'PO',
        'item'                => 'ITM',
        'customer'            => 'CUST',
        'vendor'              => 'VEND',
        'inventory_adjustment'=> 'ADJ',
        'account_transfer'    => 'XFER',
        'inventory_transfer'  => 'ITR',
        'credit_memo'         => 'CM',
        'vendor_credit'       => 'VC',
    ];

    $keys = [
        "ref_{$type}_prefix",
        "ref_{$type}_loc",
        "ref_{$type}_sep",
        "ref_{$type}_next",
        "ref_{$type}_pad",
        "ref_{$type}_suffix"
    ];
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    $rows = $db->fetchAll(
        "SELECT meta_field, meta_value FROM system_info WHERE meta_field IN ({$ph})",
        $keys
    );
    $settings = [];
    foreach ($rows as $r) {
        $settings[$r['meta_field']] = $r['meta_value'];
    }

    $prefix  = $settings["ref_{$type}_prefix"] ?? ($defaults[$type] ?? 'TXN');
    $locMode = $settings["ref_{$type}_loc"]    ?? 'none';
    $sep     = $settings["ref_{$type}_sep"]    ?? '-';
    $next    = $settings["ref_{$type}_next"]   ?? '1';
    $pad     = $settings["ref_{$type}_pad"]    ?? '5';
    $suffix  = $settings["ref_{$type}_suffix"] ?? '';

    // Location short code lookup
    $locCode = '';
    if ($locMode !== 'none' || strpos($suffix, '{LOC}') !== false) {
        if (!$location_id && function_exists('get_user_default_location_id')) {
            $location_id = get_user_default_location_id();
        }
        if ($location_id) {
            $loc = $db->fetchOne("SELECT code, name FROM locations WHERE id = ?", [$location_id]);
            if ($loc) {
                $locCode = !empty($loc['code']) ? strtoupper(trim($loc['code'])) : strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $loc['name']), 0, 4));
            }
        }
    }

    $parts = [];
    if ($locMode === 'prefix' && !empty($locCode)) $parts[] = $locCode;
    if (!empty($prefix))                           $parts[] = strtoupper($prefix);
    if ($locMode === 'mid' && !empty($locCode))    $parts[] = $locCode;

    $numStr = str_pad($next, (int)$pad, '0', STR_PAD_LEFT);
    $parts[] = $numStr;

    if (!empty($suffix)) {
        $cleanSuffix = strtoupper(trim(str_replace('{LOC}', $locCode, $suffix)));
        if (!empty($cleanSuffix)) $parts[] = $cleanSuffix;
    }
    if ($locMode === 'suffix' && !empty($locCode)) $parts[] = $locCode;

    return implode($sep, array_filter($parts, function($v) { return $v !== ''; }));
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
 * Gets a preference value from system_info — with static per-request cache.
 */
function get_accounting_preference($key)
{
    // Static per-request cache — avoids repeated DB hits within the same request
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    // Try tiered cache (APCu/session) first
    if (function_exists('sysinfo_get')) {
        $val = sysinfo_get($key);
        $cache[$key] = $val;
        return $val;
    }
    // Fallback: direct DB lookup
    try {
        $db  = db();
        $row = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = ?", [$key]);
        $val = $row['meta_value'] ?? null;
    } catch (Exception $e) {
        $val = null;
    }
    $cache[$key] = $val;
    return $val;
}

/**
 * Resolves the effective account for a given master record and preference type.
 * Priority: Master Record -> System Preference
 * 
 * Uses per-request + APCu caching to avoid repeated DB hits for the same
 * item/customer/vendor on multi-line invoices.
 *
 * $type can be: 'income', 'cogs', 'inventory', 'receivable', 'payable'
 */
function get_effective_account($master_id, $type)
{
    // Check per-request/APCu cache first
    if (!empty($master_id) && function_exists('account_cache_get')) {
        $cached = account_cache_get($master_id, $type);
        if ($cached !== null) {
            return $cached;
        }
    }

    $db = db();

    // Map internal types to column names and tables
    $mapping = [
        'income'     => ['table' => 'items',     'col' => 'income_account_id'],
        'cogs'       => ['table' => 'items',     'col' => 'cogs_account_id'],
        'inventory'  => ['table' => 'items',     'col' => 'inventory_account_id'],
        'receivable' => ['table' => 'customers', 'col' => 'receivable_account_id'],
        'payable'    => ['table' => 'vendors',   'col' => 'payable_account_id'],
    ];

    $resolved = null;

    if (!empty($master_id) && isset($mapping[$type])) {
        $m   = $mapping[$type];
        $col = $m['col'];
        $tbl = $m['table'];

        $row = $db->fetchOne("SELECT `{$col}` FROM `{$tbl}` WHERE id = ?", [$master_id]);
        if ($row && !empty($row[$col])) {
            $resolved = $row[$col];
        }
    }

    if ($resolved === null) {
        // Fallback to system preference
        $special_prefs = [
            'receivable' => 'default_ar_account',
            'payable'    => 'default_ap_account',
            'inventory'  => 'default_asset_account',
        ];
        $pref_key = $special_prefs[$type] ?? "default_{$type}_account";
        $pref = get_accounting_preference($pref_key);
        if (!empty($pref)) {
            $resolved = $pref;
        }
    }

    if ($resolved === null) {
        throw new Exception("Account of type '{$type}' is not configured for record '{$master_id}', and the default system preference is missing.");
    }

    // Store in cache for subsequent calls within this request
    if (!empty($master_id) && function_exists('account_cache_set')) {
        account_cache_set($master_id, $type, $resolved);
    }

    return $resolved;
}

/**
 * Universal UUID Generator
 */
if (!function_exists('generate_uuid')) {
    function generate_uuid()
    {
        static $seq = null;
        if ($seq === null) {
            $seq = time() % 1000000 + rand(1000, 9999);
        }
        return (string)(++$seq);
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
                (id, account_name, account_type, account_subtype, normal_balance, parent_account_id, currency, is_active) 
                VALUES ('acc-open', 'Opening Balance', 'equity', 'other', 'credit', NULL, 'NPR', 1)
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
 * Returns a fallback default POS customer ID (e.g. Walk IN / Cash Customer)
 */
function get_default_pos_customer_id()
{
    $db = db();
    $cust = $db->fetchOne("SELECT id FROM customers WHERE (LOWER(full_name) LIKE '%walk%' OR LOWER(full_name) LIKE '%cash%') AND is_deleted = 0 LIMIT 1");
    if (!$cust) {
        $cust = $db->fetchOne("SELECT id FROM customers WHERE is_deleted = 0 ORDER BY created_at ASC LIMIT 1");
    }
    if (!$cust) {
        $id = generate_uuid();
        $db->execute("INSERT INTO customers (id, customer_code, full_name, customer_type, is_active, is_deleted) VALUES (?, 'CUST-WALKIN', 'Walk IN', 'retail', 1, 0)", [$id]);
        return $id;
    }
    return $cust['id'];
}

/**
 * Regenerates the daily POS summary invoice and payment for a given date
 */
function sync_daily_pos_summary($date)
{
    $db = db();
    $pdo = $db->getConnection();
    $engine = AccountingEngine::getInstance();

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

    $raw_u = $_SESSION['user_id'] ?? ($_SESSION['userdata']['id'] ?? 2);
    $user_id = (is_numeric($raw_u) && (int)$raw_u > 0) ? (int)$raw_u : 2;
    $def_location_id = get_user_default_location_id();

    // Resolve non-null customer_id
    $customer_id = null;
    foreach ($pos_entries as $pe) {
        if (!empty($pe['customer_id'])) {
            $customer_id = $pe['customer_id'];
            break;
        }
    }
    if (empty($customer_id)) {
        $customer_id = get_default_pos_customer_id();
    }

    // Resolve daily summary IDs
    if ($existing_inv) {
        $invoice_header_id = $existing_inv['id'];
        $inv_hdr = $db->fetchOne("SELECT source FROM transaction_headers WHERE id = ?", [$invoice_header_id]);
        if ($inv_hdr && $inv_hdr['source'] === 'manual') {
            // User has manually edited and saved this invoice — preserve manual edits
            return;
        }
        $pdo->prepare("UPDATE transaction_headers SET location_id = ? WHERE id = ? AND (location_id IS NULL OR location_id = 0 OR CAST(location_id AS CHAR) = '')")->execute([$def_location_id, $invoice_header_id]);
    } else {
        $invoice_header_id = generate_uuid();
        $pdo->prepare("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type, location_id, source)
            VALUES (?, ?, 'customer_invoice', ?, ?, ?, ?, 'paid', ?, ?, 'customer', ?, 'pos_sync')
        ")->execute([$invoice_header_id, $summary_invoice_no, $date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $user_id, $customer_id, $def_location_id]);
    }

    if ($existing_pay) {
        $payment_header_id = $existing_pay['id'];
        $pdo->prepare("UPDATE transaction_headers SET location_id = ? WHERE id = ? AND (location_id IS NULL OR location_id = 0 OR CAST(location_id AS CHAR) = '')")->execute([$def_location_id, $payment_header_id]);
    } else {
        $payment_header_id = generate_uuid();
        $pdo->prepare("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type, location_id)
            VALUES (?, ?, 'customer_payment', ?, ?, ?, ?, 'posted', ?, ?, 'customer', ?)
        ")->execute([$payment_header_id, $summary_payment_no, $date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $user_id, $customer_id, $def_location_id]);
    }

    // Clear child details (rebuild them dynamically)
    $pdo->prepare("DELETE FROM transaction_lines WHERE header_id = ?")->execute([$invoice_header_id]);
    $pdo->prepare("DELETE FROM customer_invoices WHERE header_id = ? OR invoice_number = ?")->execute([$invoice_header_id, $summary_invoice_no]);
    $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$invoice_header_id]);

    $pdo->prepare("DELETE FROM payments WHERE header_id = ?")->execute([$payment_header_id]);
    $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$payment_header_id]);
    $pdo->prepare("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?")->execute([$payment_header_id, $payment_header_id]);

    // 1. Aggregate Items
    $agg_items = $db->fetchAll("
        SELECT 
            pi.item_id, 
            COALESCE(NULLIF(pi.txn_unit, ''), 'PCS') as unit,
            COALESCE(NULLIF(pi.conversion_factor, 0), 1) as conversion_factor,
            SUM(pi.quantity) as total_qty, 
            SUM(COALESCE(NULLIF(pi.base_qty, 0), pi.quantity * COALESCE(pi.conversion_factor, 1))) as total_base_qty,
            SUM(pi.amount) as total_gross, 
            SUM(pi.discount) as total_discount, 
            SUM(pi.tax) as total_tax, 
            SUM(pi.net_amount) as total_net, 
            SUM(COALESCE(NULLIF(pi.base_qty, 0), pi.quantity * COALESCE(pi.conversion_factor, 1)) * i.cost_price) as total_cogs 
        FROM pos_items pi 
        JOIN pos_entry pe ON pi.pos_id = pe.id 
        JOIN items i ON pi.item_id = i.id 
        WHERE DATE(pe.date_time) = ? AND pe.is_deleted = 0 
        GROUP BY pi.item_id, COALESCE(NULLIF(pi.txn_unit, ''), 'PCS'), COALESCE(NULLIF(pi.conversion_factor, 0), 1)
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
        $unit = $item['unit'];
        $conv = (float)$item['conversion_factor'];
        $qty = (float) $item['total_qty'];
        $base_qty = (float) $item['total_base_qty'];
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
        $base_cost_price = $base_qty > 0 ? $cogs / $base_qty : 0;
        $gross_profit_excl_tax = ($net - $tax) - $cogs; // true margin, tax excluded
        $pdo->prepare("
            INSERT INTO transaction_lines (header_id, item_id, account_id, line_number, quantity, unit, conversion_factor, base_qty, base_unit_price, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$invoice_header_id, $item_id, $line_income_account, $max_line, $qty, $unit, $conv, $base_qty, $base_cost_price, $unit_price_full, ($gross > 0) ? ($tax / $gross) * 100 : 0, $tax, $gross, $base_cost_price, $gross_profit_excl_tax, $user_id]);
    }

    // Write customer_invoices record
    if (empty($customer_id)) {
        $customer_id = get_default_pos_customer_id();
    }
    $pdo->prepare("
        INSERT INTO customer_invoices (header_id, customer_id, invoice_date, due_date, invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status, sale_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'paid', 'cash')
    ")->execute([$invoice_header_id, $customer_id, $date, $date, $summary_invoice_no, $summary_subtotal, $summary_discount, $summary_tax, $summary_total, $summary_total]);

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
            INSERT INTO payments (header_id, payment_type, customer_id, payment_method, bank_account_id, amount, payment_date, created_by, applied_to_txn_id)
            VALUES (?, 'customer_payment', ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$payment_header_id, $customer_id, $mapped_method, $acc_id, $pay_amount, $date, $user_id, $invoice_header_id]);
    }

    // Insert link
    $pdo->prepare("
        INSERT INTO transaction_links (parent_id, child_id, link_type)
        VALUES (?, ?, ?)
    ")->execute([$payment_header_id, $invoice_header_id, 'payment:' . $summary_total]);

    // 3. Invoice GL
    $ar_account = $engine->resolveAccount('default_ar_account');
    $tax_account = $engine->resolveAccount('default_tax_account');
    $disc_account = $engine->resolveAccount('default_discount_account');

    $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?)")
        ->execute([$invoice_header_id, $ar_account, $summary_total, 'Daily POS Sales Invoice ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);

    if ($summary_discount > 0) {
        $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?)")
            ->execute([$invoice_header_id, $disc_account, $summary_discount, 'Daily POS Invoice Discount ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    foreach ($sales_distributions as $inc_acct => $amt) {
        $inc_acct_id = is_numeric($inc_acct) ? (int)$inc_acct : $engine->resolveAccount('default_sales_account');
        $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, ?)")
            ->execute([$invoice_header_id, $inc_acct_id, $amt, 'Daily POS Invoice Sales ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    if ($summary_tax > 0) {
        $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, ?)")
            ->execute([$invoice_header_id, $tax_account, $summary_tax, 'Daily POS Invoice VAT ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    foreach ($cogs_distributions as $cogs_acct => $amt) {
        $cogs_acct_id = is_numeric($cogs_acct) ? (int)$cogs_acct : $engine->resolveAccount('default_cogs_account');
        $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?)")
            ->execute([$invoice_header_id, $cogs_acct_id, $amt, 'Daily POS Invoice COGS ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }
    foreach ($inv_distributions as $inv_acct => $amt) {
        $inv_acct_id = is_numeric($inv_acct) ? (int)$inv_acct : $engine->resolveAccount('default_inventory_asset_account');
        $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, ?)")
            ->execute([$invoice_header_id, $inv_acct_id, $amt, 'Daily POS Invoice Inventory Out ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
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
            $pay_acct_id = is_numeric($pay['account_id']) ? (int)$pay['account_id'] : 2; // 2 = Cash
            $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$payment_header_id, $pay_acct_id, $entry_type, $abs_amount, 'Daily POS Invoice Payment ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
        }
    }

    if (abs($discrepancy) > 0.005) {
        $misc_expense_acct = 37; // Miscellaneous Expenses ID: 37
        $entry_type = ($discrepancy > 0) ? 'debit' : 'credit'; // Positive is shortage (debit expense), negative is overage (credit)
        $abs_discrepancy = abs($discrepancy);
        $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$payment_header_id, $misc_expense_acct, $entry_type, $abs_discrepancy, 'POS Daily Cash Discrepancy ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);
    }

    $pdo->prepare("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, ?)")
        ->execute([$payment_header_id, $ar_account, $summary_total, 'POS Daily Payment ' . $summary_invoice_no, $user_id, $date, $fiscal['period'], $fiscal['year']]);

    // 5. Update transaction_headers with the correct net_amount and customer
    $old_inv_hdr = $db->fetchOne("SELECT net_amount FROM transaction_headers WHERE id = ?", [$invoice_header_id]);
    $old_pay_hdr = $db->fetchOne("SELECT net_amount FROM transaction_headers WHERE id = ?", [$payment_header_id]);

    $pdo->prepare("UPDATE transaction_headers SET net_amount = ?, party_id = ?, updated_by = ? WHERE id = ?")
        ->execute([$summary_total, $customer_id, $user_id, $invoice_header_id]);
    $pdo->prepare("UPDATE transaction_headers SET net_amount = ?, party_id = ?, updated_by = ? WHERE id = ?")
        ->execute([$summary_total, $customer_id, $user_id, $payment_header_id]);

    // 6. Record audit logs for daily POS rollup invoice & payment headers
    $log_inv_info = json_encode(['txn_number' => $summary_invoice_no, 'amount' => (float)$summary_total, 'type' => 'daily_pos_rollup']);
    $log_pay_info = json_encode(['txn_number' => $summary_payment_no, 'amount' => (float)$summary_total, 'type' => 'daily_pos_rollup']);

    $inv_has_log = $db->fetchOne("SELECT id FROM audit_logs WHERE record_id = ?", [$invoice_header_id]);
    if (!$inv_has_log) {
        $pdo->prepare("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES ('transaction_headers', 'create', ?, NULL, ?, ?)")
            ->execute([$invoice_header_id, $log_inv_info, $user_id]);
    } else if ($old_inv_hdr && (float)$old_inv_hdr['net_amount'] != (float)$summary_total) {
        $old_inv_info = json_encode(['txn_number' => $summary_invoice_no, 'amount' => (float)$old_inv_hdr['net_amount'], 'type' => 'daily_pos_rollup']);
        $pdo->prepare("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES ('transaction_headers', 'update', ?, ?, ?, ?)")
            ->execute([$invoice_header_id, $old_inv_info, $log_inv_info, $user_id]);
    }

    $pay_has_log = $db->fetchOne("SELECT id FROM audit_logs WHERE record_id = ?", [$payment_header_id]);
    if (!$pay_has_log) {
        $pdo->prepare("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES ('transaction_headers', 'create', ?, NULL, ?, ?)")
            ->execute([$payment_header_id, $log_pay_info, $user_id]);
    } else if ($old_pay_hdr && (float)$old_pay_hdr['net_amount'] != (float)$summary_total) {
        $old_pay_info = json_encode(['txn_number' => $summary_payment_no, 'amount' => (float)$old_pay_hdr['net_amount'], 'type' => 'daily_pos_rollup']);
        $pdo->prepare("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES ('transaction_headers', 'update', ?, ?, ?, ?)")
            ->execute([$payment_header_id, $old_pay_info, $log_pay_info, $user_id]);
    }
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
        ensure_period_closing_schema();
        
        // 1. Check accounting_periods
        $p = $db->fetchOne("
            SELECT period_name, status FROM accounting_periods 
            WHERE ? BETWEEN start_date AND end_date 
              AND status IN ('soft_closed', 'closed', 'locked', 'archived')
            LIMIT 1
        ", [$date]);
        
        if ($p) {
            $st = strtoupper($p['status']);
            throw new Exception("Transaction blocked: Date '{$date}' falls within the Accounting Period '{$p['period_name']}' which is {$st}.");
        }

        // 2. Check fiscal_years
        $fy = $db->fetchOne("
            SELECT name, status FROM fiscal_years 
            WHERE ? BETWEEN start_date AND end_date 
              AND status IN ('closed', 'locked', 'soft_closed')
            LIMIT 1
        ", [$date]);

        if ($fy) {
            $st = strtoupper($fy['status']);
            throw new Exception("Transaction blocked: Date '{$date}' falls within the Fiscal Year '{$fy['name']}' which is {$st}.");
        }
    } catch (PDOException $e) {
        // If tables do not exist, ignore
    }
}

/**
 * Role Permission Engine Functions
 */
function get_user_role_permissions()
{
    static $cached_role_data = null;
    static $cached_role_code = null;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $role_code = $_SESSION['role'] ?? '';
    if ($cached_role_data !== null && $cached_role_code === $role_code) {
        return $cached_role_data;
    }
    $cached_role_code = $role_code;
    if (empty($role_code)) {
        $cached_role_data = [
            'access_level' => 'hide',
            'permissions' => []
        ];
        return $cached_role_data;
    }

    if ($role_code === 'admin') {
        $cached_role_data = [
            'access_level' => 'full',
            'permissions' => []
        ];
        return $cached_role_data;
    }

    try {
        $db = db();
        $row = $db->fetchOne("SELECT access_level, permissions FROM roles WHERE role_code = ? AND is_active = 1", [$role_code]);
        if ($row) {
            $perms = [];
            if (!empty($row['permissions'])) {
                $perms = json_decode($row['permissions'], true) ?: [];
            }
            $cached_role_data = [
                'access_level' => strtolower($row['access_level'] ?? 'custom'),
                'permissions' => $perms
            ];
        } else {
            $cached_role_data = [
                'access_level' => 'custom',
                'permissions' => []
            ];
        }
    } catch (Exception $e) {
        $cached_role_data = [
            'access_level' => 'custom',
            'permissions' => []
        ];
    }

    return $cached_role_data;
}

function get_feature_permission($item_key)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') {
        return 'allow';
    }

    $role_data = get_user_role_permissions();
    $access_level = $role_data['access_level'];

    if ($access_level === 'full') {
        return 'allow';
    }
    if ($access_level === 'readonly') {
        return 'readonly';
    }

    $perms = $role_data['permissions'];
    if (isset($perms[$item_key])) {
        return $perms[$item_key];
    }

    return 'allow';
}

function can_access_feature($item_key)
{
    return get_feature_permission($item_key) !== 'hide';
}

function can_edit_feature($item_key)
{
    return get_feature_permission($item_key) === 'allow';
}

function resolve_page_permission_key($page)
{
    $p = strtolower(trim($page, '/'));
    $parts = explode('/', $p);

    $is_edit = false;
    $last = end($parts);
    if ($last === 'manage' || $last === 'edit' || strpos($p, '/manage') !== false) {
        $is_edit = true;
    }

    $key = '';

    if (strpos($p, 'transactions/pos') !== false) {
        $key = 'pos';
    } elseif (strpos($p, 'transactions/invoice') !== false) {
        $key = 'invoice';
    } elseif (strpos($p, 'transactions/credit_memo') !== false) {
        $key = 'credit_memo';
    } elseif (strpos($p, 'transactions/bill_credit') !== false) {
        $key = 'bill_credit';
    } elseif (strpos($p, 'transactions/bill') !== false) {
        $key = 'bill';
    } elseif (strpos($p, 'transactions/payment') !== false) {
        $key = 'payment';
    } elseif (strpos($p, 'transactions/expense') !== false) {
        $key = 'expense';
    } elseif (strpos($p, 'transactions/journal') !== false) {
        $key = 'journal';
    } elseif (strpos($p, 'transactions/cash_denom') !== false) {
        $key = 'cash_denom';
    } elseif (strpos($p, 'transactions/adjustment') !== false) {
        $key = 'adjustment';
    } elseif (strpos($p, 'transactions/transfer') !== false) {
        $key = 'transfer';
    } elseif (strpos($p, 'transactions/inventory_transfer') !== false) {
        $key = 'inventory_transfer';
    } elseif ($p === 'transactions/transactions_list' || $p === 'transactions') {
        $key = 'any_transaction';
    } elseif (strpos($p, 'activity') === 0 || strpos($p, 'activity/') !== false) {
        $key = 'activity';
    } elseif (strpos($p, 'master/account/opening_balance') !== false) {
        $key = 'opening_balances';
    } elseif (strpos($p, 'master/account') !== false) {
        $key = 'accounts';
    } elseif (strpos($p, 'master/customer') !== false) {
        $key = 'customers';
    } elseif (strpos($p, 'master/vendor') !== false) {
        $key = 'vendors';
    } elseif (strpos($p, 'master/item') !== false) {
        $key = 'items';
    } elseif (strpos($p, 'system/users') !== false || strpos($p, 'system/user') !== false) {
        $key = 'users';
    } elseif ($p === 'master/master_list' || $p === 'master') {
        $key = 'any_master';
    } elseif (strpos($p, 'system/settings/location') !== false || strpos($p, 'settings/location') !== false) {
        $key = 'locations';
    } elseif (strpos($p, 'reports/pos_summary') !== false) {
        $key = 'rep_pos';
    } elseif (strpos($p, 'reports/financial/break_even_payback') !== false) {
        $key = 'rep_insights';
    } elseif (strpos($p, 'reports/financial') !== false) {
        $key = 'rep_financial';
    } elseif (strpos($p, 'reports/sales') !== false) {
        $key = 'rep_sales';
    } elseif (strpos($p, 'reports/purchases') !== false) {
        $key = 'rep_purchases';
    } elseif (strpos($p, 'reports/vendors') !== false) {
        $key = 'rep_vendors';
    } elseif (strpos($p, 'reports/inventory') !== false) {
        $key = 'rep_inventory';
    } elseif (strpos($p, 'reports/vat') !== false) {
        $key = 'rep_vat';
    } elseif (strpos($p, 'reports/customers') !== false) {
        $key = 'rep_customers';
    } elseif ($p === 'reports/reports_list' || $p === 'reports') {
        $key = 'any_report';
    } elseif (strpos($p, 'system/company') !== false) {
        $key = 'company_info';
    } elseif (strpos($p, 'system/roles') !== false || strpos($p, 'system/role') !== false) {
        $key = 'roles_perm';
    } elseif (strpos($p, 'system/fiscal_years') !== false) {
        $key = 'fiscal_years';
    } elseif (strpos($p, 'system/settings') !== false) {
        if (strpos($p, 'whatsapp') !== false) {
            $key = 'whatsapp';
        } else {
            $key = 'accounting_prefs';
        }
    } elseif (strpos($p, 'system/ref_codes') !== false) {
        $key = 'ref_codes';
    } elseif (strpos($p, 'system/import_export') !== false) {
        $key = 'import_export';
    } elseif (strpos($p, 'system/backup') !== false) {
        $key = 'backup_restore';
    }

    // --- Dynamic Auto-Resolution Fallbacks for any new transaction, report, or master page ---
    if (empty($key) && count($parts) >= 2) {
        if ($parts[0] === 'transactions' && !in_array($parts[1], ['transactions_list', 'view', 'print'])) {
            $key = $parts[1];
        } elseif ($parts[0] === 'reports' && !in_array($parts[1], ['reports_list', 'rpt_helpers'])) {
            $key = 'rep_' . str_replace(['.php', '_list'], '', $parts[1]);
        } elseif ($parts[0] === 'master' && !in_array($parts[1], ['master_list'])) {
            $key = $parts[1];
        }
    }

    return [$key, $is_edit];
}

function check_page_access($page)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin' || $page === 'home' || $page === 'home-v3' || $page === 'help_center' || $page === 'system/help_center') {
        return true;
    }

    list($key, $is_edit) = resolve_page_permission_key($page);
    if (empty($key)) {
        return true;
    }

    if ($key === 'any_transaction') {
        $txn_keys = ['pos', 'bill', 'bill_credit', 'invoice', 'credit_memo', 'payment', 'expense', 'journal', 'cash_denom', 'adjustment', 'transfer', 'inventory_transfer'];
        foreach ($txn_keys as $tk) {
            if (can_access_feature($tk)) return true;
        }
        return false;
    }

    if ($key === 'any_master') {
        $mst_keys = ['accounts', 'customers', 'vendors', 'items', 'users'];
        foreach ($mst_keys as $mk) {
            if (can_access_feature($mk)) return true;
        }
        return false;
    }

    if ($key === 'any_report') {
        $rpt_keys = ['rep_financial', 'rep_sales', 'rep_purchases', 'rep_vendors', 'rep_inventory', 'rep_vat', 'rep_customers', 'rep_pos', 'rep_insights'];
        foreach ($rpt_keys as $rk) {
            if (can_access_feature($rk)) return true;
        }
        return false;
    }

    $perm = get_feature_permission($key);
    if ($perm === 'hide') {
        return false;
    }

    if ($is_edit && $perm === 'readonly') {
        return false;
    }

    return true;
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

    // Direct feature key check
    if (can_access_feature($permission)) {
        return true;
    }

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
        SELECT COALESCE(SUM(ABS(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)))), 0.00) as total_paid
        FROM transaction_links tl
        JOIN transaction_headers ph ON tl.parent_id = ph.id
        WHERE tl.child_id = ? AND (tl.link_type LIKE 'payment:%' OR tl.link_type LIKE 'credit_memo_apply:%' OR tl.link_type LIKE 'bill_credit_apply:%' OR tl.link_type LIKE 'vendor_credit_apply:%')
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

    // Sum credit applications where THIS document is the credit source (parent_id)
    $stmt_credit_out = $pdo->prepare("
        SELECT COALESCE(SUM(ABS(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)))), 0.00) as total_applied_out
        FROM transaction_links tl
        JOIN transaction_headers ch ON tl.child_id = ch.id
        WHERE tl.parent_id = ? AND (tl.link_type LIKE 'credit_memo_apply:%' OR tl.link_type LIKE 'bill_credit_apply:%' OR tl.link_type LIKE 'vendor_credit_apply:%')
          AND ch.is_deleted = 0 AND ch.status NOT IN ('void', 'voided', 'draft')
    ");
    $stmt_credit_out->execute([$doc_header_id]);
    $total_applied_out = (float) ($stmt_credit_out->fetch(PDO::FETCH_ASSOC)['total_applied_out'] ?? 0.00);

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
    } elseif ($txn_type === 'credit_memo') {
        $stmt_cm = $pdo->prepare("SELECT total_amount FROM credit_memos WHERE header_id = ?");
        $stmt_cm->execute([$doc_header_id]);
        $cm = $stmt_cm->fetch(PDO::FETCH_ASSOC);
        if ($cm) {
            $total_amount = (float) $cm['total_amount'];
            $total_credit_used = $total_applied_out + $actual_paid;
            $new_applied = min($total_amount, $total_credit_used);
            $new_remaining = max(0.00, round($total_amount - $total_credit_used, 2));

            $cm_status = 'open';
            $hdr_status = 'open';
            if ($new_remaining <= 0.01) {
                $cm_status = 'closed';
                $hdr_status = 'closed';
            } elseif ($new_applied > 0.01) {
                $cm_status = 'partial';
                $hdr_status = 'partial';
            }

            $pdo->prepare("UPDATE credit_memos SET remaining_credit = ?, status = ? WHERE header_id = ?")
                ->execute([$new_remaining, $cm_status, $doc_header_id]);
            $pdo->prepare("UPDATE transaction_headers SET status = ? WHERE id = ?")
                ->execute([$hdr_status, $doc_header_id]);
        }
    } elseif ($txn_type === 'vendor_credit' || $txn_type === 'bill_credit') {
        $stmt_vc = $pdo->prepare("SELECT total_amount FROM vendor_credits WHERE header_id = ?");
        $stmt_vc->execute([$doc_header_id]);
        $vc = $stmt_vc->fetch(PDO::FETCH_ASSOC);
        if (!$vc) {
            // Also check vendor_bills for bill_credit if table differs
            $stmt_vc = $pdo->prepare("SELECT total_amount FROM vendor_bills WHERE header_id = ?");
            $stmt_vc->execute([$doc_header_id]);
            $vc = $stmt_vc->fetch(PDO::FETCH_ASSOC);
        }
        if ($vc) {
            $total_amount = (float) $vc['total_amount'];
            $total_credit_used = $total_applied_out + $actual_paid;
            $new_applied = min($total_amount, $total_credit_used);
            $new_remaining = max(0.00, round($total_amount - $total_credit_used, 2));

            $vc_status = 'open';
            $hdr_status = 'open';
            if ($new_remaining <= 0.01) {
                $vc_status = 'closed';
                $hdr_status = 'closed';
            } elseif ($new_applied > 0.01) {
                $vc_status = 'partial';
                $hdr_status = 'partial';
            }

            $pdo->prepare("UPDATE vendor_credits SET remaining_credit = ?, status = ? WHERE header_id = ?")
                ->execute([$new_remaining, $vc_status, $doc_header_id]);
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
/**
 * Clears all dashboard KPI caches so that data updates after any transaction.
 * Clears DB cache table + APCu cache if available.
 */
if (!function_exists('clear_dashboard_cache')) {
    function clear_dashboard_cache()
    {
        // Clear DB-based KPI cache table
        try {
            $db = db();
            $db->execute("DELETE FROM dashboard_kpi_cache");
        } catch (Exception $e) {
            // Silently ignore if table doesn't exist
        }
        // Clear APCu dashboard cache if available
        if (function_exists('dash_apcu_clear')) {
            dash_apcu_clear();
        }
        // Also invalidate system info cache (preferences may have changed)
        if (function_exists('sysinfo_invalidate')) {
            sysinfo_invalidate();
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
                return $db->fetchAll("SELECT id, REPLACE(id, 'acc-', '') as account_code, account_name as name, account_type, account_subtype, normal_balance FROM accounts WHERE is_deleted = 0 AND is_active = 1 ORDER BY account_name ASC");
            default:
                return [];
        }
    }
}

if (!function_exists('render_dropdown_options')) {
    function render_dropdown_options(array $options, $selected_val = ''): string
    {
        $html = '';
        foreach ($options as $opt) {
            $id = htmlspecialchars($opt['id'] ?? '');
            $name = htmlspecialchars($opt['name'] ?? '');
            $sel = (string)($opt['id'] ?? '') === (string)$selected_val ? ' selected' : '';
            $html .= "<option value=\"{$id}\"{$sel}>{$name}</option>";
        }
        return $html;
    }
}

function resolve_location_id($loc_id) {
    if (is_numeric($loc_id) && (int)$loc_id > 0) {
        return (int)$loc_id;
    }
    if (empty($loc_id)) {
        return 1;
    }
    $str = strtolower((string)$loc_id);
    if ($str === 'loc-main-retail' || strpos($str, 'retail') !== false || strpos($str, 'gokarna') !== false) {
        return 1;
    }
    if ($str === 'loc-main-wh' || strpos($str, 'wh') !== false || strpos($str, 'house') !== false) {
        return 2;
    }
    try {
        $db = db();
        $row = $db->fetchOne("SELECT id FROM locations WHERE id = CAST(? AS CHAR) OR code = ? OR name LIKE ? LIMIT 1", [$loc_id, $loc_id, "%$loc_id%"]);
        if ($row && is_numeric($row['id'])) {
            return (int)$row['id'];
        }
    } catch (\Throwable $e) {}
    return 1;
}

if (!function_exists('get_user_default_location_id')) {
    function get_user_default_location_id(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION['location_id']) && is_numeric($_SESSION['location_id'])) {
            return (string)$_SESSION['location_id'];
        }
        $user_id = $_SESSION['user_id'] ?? ($_SESSION['userdata']['id'] ?? null);
        if ($user_id) {
            $db = db();
            $user_loc = $db->fetchOne("SELECT location_id FROM users WHERE id = CAST(? AS CHAR) OR username = ? LIMIT 1", [$user_id, $user_id]);
            if (!empty($user_loc['location_id'])) {
                $resolved = resolve_location_id($user_loc['location_id']);
                $_SESSION['location_id'] = $resolved;
                return (string)$resolved;
            }
        }
        // Fallback to system default location
        $loc = db()->fetchOne("SELECT id FROM locations WHERE is_default = 1 AND is_deleted = 0 LIMIT 1");
        if ($loc) {
            $resolved = resolve_location_id($loc['id']);
            $_SESSION['location_id'] = $resolved;
            return (string)$resolved;
        }
        return "1";
    }
}

if (!function_exists('sync_and_get_item_inventory_balances')) {
    function sync_and_get_item_inventory_balances($db, string $item_id): array {
        $locations = $db->fetchAll("SELECT id, name, type FROM locations WHERE is_deleted = 0 AND is_active = 1 ORDER BY name ASC");
        $item = $db->fetchOne("SELECT cost_price FROM items WHERE id = ?", [$item_id]);
        $cost_price = (float)($item['cost_price'] ?? 0.00);

        foreach ($locations as $loc) {
            $loc_id = (int)$loc['id'];

            // 1. Calculate live stock on hand for this location (including inventory transfers)
            $is_loc = "(COALESCE(NULLIF(l.location_id, 0), h.location_id) = ?)";
            $is_party = "(h.party_id = ?)";

            $hdr_stock = (float)($db->fetchOne("
                SELECT COALESCE(SUM(CASE 
                    WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment', 'credit_memo') AND (COALESCE(NULLIF(l.location_id, 0), h.location_id) = ?) THEN l.quantity 
                    WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale', 'vendor_credit', 'bill_credit') AND (COALESCE(NULLIF(l.location_id, 0), h.location_id) = ?) THEN -l.quantity 
                    WHEN h.txn_type = 'inventory_transfer' AND COALESCE(it.to_location_id, h.party_id) = ? THEN l.quantity 
                    WHEN h.txn_type = 'inventory_transfer' AND COALESCE(it.from_location_id, h.location_id) = ? THEN -l.quantity 
                    ELSE 0 END), 0) as qty
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                LEFT JOIN inventory_transfers it ON it.header_id = h.id
                WHERE l.item_id = ? 
                  AND (
                     (COALESCE(NULLIF(l.location_id, 0), h.location_id) = ?) OR
                     (h.txn_type = 'inventory_transfer' AND (it.from_location_id = ? OR it.to_location_id = ? OR h.party_id = ?))
                  )
                  AND h.is_deleted = 0 
                  AND h.status NOT IN ('void', 'voided', 'draft')
            ", [
                $loc_id, $loc_id, $loc_id, $loc_id,
                $item_id,
                $loc_id, $loc_id, $loc_id, $loc_id
            ])['qty'] ?? 0);

            // Add POS Entries if matched by location
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
                      AND (th.txn_number LIKE 'INV-POS%' OR th.txn_number LIKE 'POS-SUM%')
                      AND th.location_id = ?
                      AND DATE(th.txn_date) = DATE(pe.date_time)
                      AND tl.item_id = pi.item_id
                      AND th.is_deleted = 0
                )
            ", [$item_id, $loc_id, $loc_id, $loc_id])['qty'] ?? 0);

            $on_hand = $hdr_stock + $pos_stock;

            // 2. Committed Qty
            $committed = (float)($db->fetchOne("
                SELECT COALESCE(SUM(l.quantity), 0) as qty
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                WHERE l.item_id = ? AND h.location_id = ? AND h.txn_type IN ('customer_invoice', 'sales_order') AND h.status = 'draft' AND h.is_deleted = 0
            ", [$item_id, $loc_id])['qty'] ?? 0);

            // 3. On Order Qty
            $on_order = (float)($db->fetchOne("
                SELECT COALESCE(SUM(l.quantity), 0) as qty
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                WHERE l.item_id = ? AND h.location_id = ? AND h.txn_type IN ('vendor_bill', 'purchase_order') AND h.status = 'draft' AND h.is_deleted = 0
            ", [$item_id, $loc_id])['qty'] ?? 0);

            $available = max(0, $on_hand - $committed);

            // Upsert into inventory_balances table using pure INT keys
            $existing = $db->fetchOne("SELECT id, average_cost FROM inventory_balances WHERE item_id = ? AND location_id = ?", [$item_id, $loc_id]);
            $effective_cost = ($cost_price > 0) ? $cost_price : (float)($existing['average_cost'] ?? 0.00);
            if ($existing) {
                $db->execute("
                    UPDATE inventory_balances 
                    SET quantity_on_hand = ?, available_qty = ?, committed_qty = ?, on_order_qty = ?, average_cost = ?, cost_price = ?, last_updated = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ", [$on_hand, $available, $committed, $on_order, $effective_cost, $effective_cost, $existing['id']]);
            } else {
                $db->execute("
                    INSERT INTO inventory_balances (item_id, location_id, quantity_on_hand, available_qty, committed_qty, on_order_qty, average_cost, cost_price) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ", [(int)$item_id, (int)$loc_id, $on_hand, $available, $committed, $on_order, $effective_cost, $effective_cost]);
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
    function auto_sync_pos_items_and_invoices(bool $force = true) {
        try {
            $db = db();
            $today = date('Y-m-d');
            sync_daily_pos_summary($today);

            // Real-time sync of inventory balances for all active items across locations
            $items = $db->fetchAll("SELECT id FROM items WHERE is_deleted = 0 AND is_active = 1");
            foreach ($items as $it) {
                sync_and_get_item_inventory_balances($db, $it['id']);
            }

            // Sync opening balances journal entries
            if (function_exists('sync_opening_balance_journal_entries')) {
                sync_opening_balance_journal_entries($db->getConnection());
            }

            // Reconcile Inventory Subledger Valuation with GL Asset balance
            if (file_exists(__DIR__ . '/InventoryEngine.php')) {
                require_once __DIR__ . '/InventoryEngine.php';
                if (class_exists('InventoryEngine')) {
                    InventoryEngine::getInstance()->reconcileInventoryValuationWithGL();
                }
            }

            // Save last sync timestamp
            $now = time();
            $row = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'last_pos_sync_timestamp'");
            if ($row) {
                $db->execute("UPDATE system_info SET meta_value = ? WHERE meta_field = 'last_pos_sync_timestamp'", [(string)$now]);
            } else {
                $db->execute("INSERT INTO system_info (id, meta_field, meta_value) VALUES (?, 'last_pos_sync_timestamp', ?)", [generate_uuid(), (string)$now]);
            }
        } catch (Throwable $e) {
            // Silently ignore exception during auto sync
        }
    }
}

/**
 * Ensures Period Close database tables exist.
 */
if (!function_exists('ensure_period_closing_schema')) {
    function ensure_period_closing_schema() {
        static $done = false;
        if ($done) return;
        try {
            $db = db();
            $pdo = $db->getConnection();
            if ($pdo->inTransaction()) {
                // Skip DDL schema modifications inside active transactions to avoid MySQL implicit transaction commits
                return;
            }
            $done = true;
            // 1. Expand status column in fiscal_years & fiscal_year_audit_logs to VARCHAR(50) to prevent ENUM truncation errors
            try {
                $db->execute("ALTER TABLE `fiscal_years` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'open'");
            } catch (Throwable $e) {}
            try {
                $db->execute("ALTER TABLE `fiscal_year_audit_logs` MODIFY COLUMN `previous_status` VARCHAR(50) DEFAULT NULL");
            } catch (Throwable $e) {}
            try {
                $db->execute("ALTER TABLE `fiscal_year_audit_logs` MODIFY COLUMN `new_status` VARCHAR(50) DEFAULT NULL");
            } catch (Throwable $e) {}

            // 2. Clean up any legacy artificial OPEN-FY opening balance journal entries so GL contains ONLY real transactions
            $db->execute("UPDATE transaction_headers SET is_deleted = 1 WHERE (source = 'Fiscal Year Opening' OR txn_number LIKE 'OPEN-FY%') AND is_deleted = 0");

            $db->execute("
                CREATE TABLE IF NOT EXISTS `accounting_periods` (
                  `id` varchar(64) NOT NULL,
                  `fiscal_year_id` varchar(64) NOT NULL,
                  `period_name` varchar(100) NOT NULL,
                  `start_date` date NOT NULL,
                  `end_date` date NOT NULL,
                  `status` enum('open','soft_closed','closed','locked','archived') NOT NULL DEFAULT 'open',
                  `closed_by` varchar(64) DEFAULT NULL,
                  `closed_at` datetime DEFAULT NULL,
                  `locked_by` varchar(64) DEFAULT NULL,
                  `locked_at` datetime DEFAULT NULL,
                  `reopened_by` varchar(64) DEFAULT NULL,
                  `reopened_at` datetime DEFAULT NULL,
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_fy_id` (`fiscal_year_id`),
                  KEY `idx_dates` (`start_date`,`end_date`),
                  KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $db->execute("
                CREATE TABLE IF NOT EXISTS `period_audit_logs` (
                  `id` varchar(64) NOT NULL,
                  `period_id` varchar(64) NOT NULL,
                  `user_id` varchar(64) DEFAULT NULL,
                  `action` varchar(100) NOT NULL,
                  `before_status` varchar(50) DEFAULT NULL,
                  `after_status` varchar(50) DEFAULT NULL,
                  `reason` text DEFAULT NULL,
                  `ip_address` varchar(45) DEFAULT NULL,
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_period` (`period_id`),
                  KEY `idx_user` (`user_id`),
                  KEY `idx_action` (`action`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $db->execute("
                CREATE TABLE IF NOT EXISTS `period_report_snapshots` (
                  `id` varchar(64) NOT NULL,
                  `period_id` varchar(64) NOT NULL,
                  `report_type` varchar(100) NOT NULL,
                  `report_name` varchar(255) NOT NULL,
                  `snapshot_data` longtext NOT NULL,
                  `created_by` varchar(64) DEFAULT NULL,
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_period_report` (`period_id`,`report_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $done = true;
        } catch (Throwable $e) {
            // Ignore if already created
        }
    }
}

/**
 * Checks if a transaction date falls into a Soft Closed, Closed, or Locked period.
 * Throws Exception if locked.
 */
if (!function_exists('check_period_lock')) {
    function check_period_lock($txn_date) {
        ensure_period_closing_schema();
        if (empty($txn_date)) return;
        $db = db();
        
        // 1. Check accounting_periods
        $p = $db->fetchOne("
            SELECT * FROM accounting_periods 
            WHERE ? BETWEEN start_date AND end_date 
              AND status IN ('soft_closed', 'closed', 'locked', 'archived')
            LIMIT 1
        ", [$txn_date]);
        
        if ($p) {
            $st = strtoupper($p['status']);
            throw new Exception("Transaction blocked: Date {$txn_date} falls in an Accounting Period '{$p['period_name']}' which is {$st}.");
        }

        // 2. Check fiscal_years
        $fy = $db->fetchOne("
            SELECT * FROM fiscal_years 
            WHERE ? BETWEEN start_date AND end_date 
              AND status IN ('closed', 'locked', 'soft_closed')
            LIMIT 1
        ", [$txn_date]);

        if ($fy) {
            $st = strtoupper($fy['status']);
            throw new Exception("Transaction blocked: Date {$txn_date} falls in a Fiscal Year '{$fy['fy_name']}' which is {$st}.");
        }
    }
}

/**
 * Universal Audit Logging Helper for All System Entities & Transactions.
 * Records CREATE, UPDATE, and DELETE actions into audit_logs and updates updated_by column.
 */
if (!function_exists('log_audit')) {
    function log_audit(string $tableName, string $action, string|int $recordId, ?array $oldValues = null, ?array $newValues = null, ?int $userId = null): void
    {
        try {
            $db = db();
            $pdo = $db->getPdo();
            $userId = $userId ?: ($_SESSION['user_id'] ?? 1);

            $oldJson = !empty($oldValues) ? json_encode($oldValues) : null;
            $newJson = !empty($newValues) ? json_encode($newValues) : null;

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$tableName, strtolower($action), (string)$recordId, $oldJson, $newJson, $userId]);

            if (in_array(strtolower($action), ['update', 'create'])) {
                if ($tableName === 'transaction_headers') {
                    $pdo->prepare("UPDATE transaction_headers SET updated_by = ? WHERE id = ?")->execute([$userId, $recordId]);
                } elseif ($tableName === 'items') {
                    $pdo->prepare("UPDATE items SET updated_by = ? WHERE id = ?")->execute([$userId, $recordId]);
                } elseif ($tableName === 'customers') {
                    $pdo->prepare("UPDATE customers SET updated_by = ? WHERE id = ?")->execute([$userId, $recordId]);
                } elseif ($tableName === 'vendors') {
                    $pdo->prepare("UPDATE vendors SET updated_by = ? WHERE id = ?")->execute([$userId, $recordId]);
                } elseif ($tableName === 'users') {
                    $pdo->prepare("UPDATE users SET updated_by = ? WHERE id = ?")->execute([$userId, $recordId]);
                }
            }
        } catch (Throwable $e) {
            @file_put_contents(sys_get_temp_dir() . '/audit_error.log', date('Y-m-d H:i:s') . ' - log_audit: ' . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
}

function clear_dashboard_kpi_cache(): void {
    try {
        db()->execute("TRUNCATE TABLE dashboard_kpi_cache");
    } catch (Throwable $e) {}
}
?>