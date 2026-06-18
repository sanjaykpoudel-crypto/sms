<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Only block direct access to this helper file, not when it's included
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'reference_helper.php') {
    if (PHP_SAPI !== 'cli' && !isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
        exit;
    }
}
/**
 * Helper functions for auto-generated transaction numbering
 */

function getNextTransactionNumber($type) {
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
            'account_transfer' => 'XFER'
        ];
        $prefix = $defaults[$type] ?? 'TXN';
    }
    
    $sep = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'ref_{$type}_sep'")['meta_value'] ?? '-';
    $next = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'ref_{$type}_next'")['meta_value'] ?? '1';
    $pad = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'ref_{$type}_pad'")['meta_value'] ?? '4';
    
    return $prefix . $sep . str_pad($next, (int)$pad, '0', STR_PAD_LEFT);
}

/**
 * Increments the next number in system_info
 */
function incrementTransactionNumber($type) {
    $db = db();
    $key = "ref_{$type}_next";
    
    $row = $db->fetchOne("SELECT id, meta_value FROM system_info WHERE meta_field = ?", [$key]);
    
    if ($row) {
        $next = (int)$row['meta_value'] + 1;
        $db->execute("UPDATE system_info SET meta_value = ? WHERE id = ?", [$next, $row['id']]);
    } else {
        // If it doesn't exist, start from 2 (since 1 was just used)
        $db->execute("INSERT INTO system_info (meta_field, meta_value) VALUES (?, '2')", [$key]);
    }
}

/**
 * Gets a preference value from system_info
 */
function get_accounting_preference($key) {
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
function get_effective_account($master_id, $type) {
    $db = db();
    $pref_key = "default_{$type}_account";
    
    // Map internal types to column names and tables
    $mapping = [
        'income'     => ['table' => 'items',     'col' => 'income_account_id'],
        'cogs'       => ['table' => 'items',     'col' => 'cogs_account_id'],
        'inventory'  => ['table' => 'items',     'col' => 'inventory_account_id'],
        'receivable' => ['table' => 'customers', 'col' => 'receivable_account_id'],
        'payable'    => ['table' => 'vendors',   'col' => 'payable_account_id'],
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
        'payable'    => 'default_ap_account',
        'inventory'  => 'default_asset_account' // existing naming in code
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
    function generate_uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

/**
 * Calculates fiscal year, month and period string from date
 */
function calculate_fiscal_info($date) {
    $time = strtotime($date);
    return [
        'year'   => date('Y', $time),
        'month'  => date('m', $time),
        'period' => date('Y-m', $time)
    ];
}




/**
 * Converts a number into words (South Asian System: Lakhs/Crores)
 */
function amount_in_words($number) {
    $no = (int)floor($number);
    $point = (int)round(($number - $no) * 100);
    $hundred = null;
    $digits_1 = strlen($no);
    $i = 0;
    $str = array();
    $words = array('0' => '', '1' => 'One', '2' => 'Two',
        '3' => 'Three', '4' => 'Four', '5' => 'Five', '6' => 'Six',
        '7' => 'Seven', '8' => 'Eight', '9' => 'Nine',
        '10' => 'Ten', '11' => 'Eleven', '12' => 'Twelve',
        '13' => 'Thirteen', '14' => 'Fourteen',
        '15' => 'Fifteen', '16' => 'Sixteen', '17' => 'Seventeen',
        '18' => 'Eighteen', '19' => 'Nineteen', '20' => 'Twenty',
        '30' => 'Thirty', '40' => 'Forty', '50' => 'Fifty',
        '60' => 'Sixty', '70' => 'Seventy',
        '80' => 'Eighty', '90' => 'Ninety');
    $digits = array('', 'Hundred', 'Thousand', 'Lakh', 'Crore');
    while ($i < $digits_1) {
        $divider = ($i == 2) ? 10 : 100;
        $number = (int)floor($no % $divider);
        $no = (int)floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number) {
            $hundred = (count($str) == 1 && $str[0]) ? ' and ' : null;
            $str [] = ($number < 21) ? $words[$number] .
                " " . $digits[count($str)] . " " . $hundred
                :
                $words[floor($number / 10) * 10]
                . " " . $words[$number % 10] . " "
                . $digits[count($str)] . " " . $hundred;
        } else $str[] = null;
    }
    $str = array_reverse($str);
    $result = trim(implode('', $str));
    $points = ($point) ?
        "and " . ($words[floor($point / 10) * 10] . " " . $words[$point % 10]) . " Paisa " : '';
    
    if (empty($result)) $result = "Zero";
    
    return $result . " Rupees " . $points . "Only";
}
