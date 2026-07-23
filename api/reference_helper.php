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

/**
 * Synchronizes the opening balances of accounts from the accounts table
 * to a balanced, posted journal entry header ('OPENING-BALANCES').
 */
function sync_opening_balance_journal_entries($pdo, $date = null) {
    // 1. Fetch all accounts with non-zero opening balance
    $stmt = $pdo->prepare("SELECT id, account_name, normal_balance, opening_balance FROM accounts WHERE opening_balance != 0.00 AND is_deleted = 0");
    $stmt->execute();
    $opening_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Check if the OPENING-BALANCES transaction header exists
    $stmt = $pdo->prepare("SELECT id FROM transaction_headers WHERE txn_number = 'OPENING-BALANCES'");
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
            (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, created_by, net_amount) 
            VALUES (?, 'OPENING-BALANCES', 'Journal', ?, ?, ?, ?, 'posted', 'System Opening Balances', ?, 0.00)
        ");
        $stmt->execute([$header_id, $txn_date, $fiscal_year, $fiscal_month, $fiscal_period, $userId]);
    } else {
        // Clear existing lines for this header
        $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$header_id]);
        // Update header details just in case
        $stmt = $pdo->prepare("
            UPDATE transaction_headers 
            SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$txn_date, $fiscal_year, $fiscal_month, $fiscal_period, $header_id]);
    }

    $total_debit = 0.00;
    $total_credit = 0.00;
    $entries = [];

    // 3. Prepare journal entry rows for each account
    foreach ($opening_accounts as $acc) {
        $balance = (float)$acc['opening_balance'];
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

        if ($balance == 0.00) continue;

        if ($entry_type === 'debit') {
            $total_debit += $balance;
        } else {
            $total_credit += $balance;
        }

        $entries[] = [
            'id' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
            'account_id' => $acc['id'],
            'entry_type' => $entry_type,
            'amount' => $balance,
            'memo' => 'Opening Balance for ' . $acc['account_name']
        ];
    }

    // 4. Handle double-entry balancing using Opening Balance account (code 'open') or Owner Capital (acc-3100)
    $difference = $total_debit - $total_credit;
    if (abs($difference) > 0.001) {
        // Find offset account (account_code = 'open' first)
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE account_code = 'open' OR account_name = 'Opening Balance'");
        $stmt->execute();
        $offset_acc = $stmt->fetch(PDO::FETCH_ASSOC);
        $offset_id = $offset_acc ? $offset_acc['id'] : null;

        if (!$offset_id) {
            // Fallback: search for Owner Capital (acc-3100 or another equity account)
            $stmt = $pdo->prepare("SELECT id FROM accounts WHERE id = 'acc-3100' OR account_code = '3100'");
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
            'id' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
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
function sync_daily_pos_summary($date) {
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
    
    // Resolve daily summary IDs
    if ($existing_inv) {
        $invoice_header_id = $existing_inv['id'];
    } else {
        $invoice_header_id = generate_uuid();
        $customer_id = $pos_entries[0]['customer_id'];
        
        $pdo->prepare("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type)
            VALUES (?, ?, 'customer_invoice', ?, ?, ?, ?, 'paid', ?, ?, 'customer')
        ")->execute([$invoice_header_id, $summary_invoice_no, $date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $user_id, $customer_id]);
    }
    
    if ($existing_pay) {
        $payment_header_id = $existing_pay['id'];
    } else {
        $payment_header_id = generate_uuid();
        $customer_id = $pos_entries[0]['customer_id'];
        
        $pdo->prepare("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type)
            VALUES (?, ?, 'customer_payment', ?, ?, ?, ?, 'posted', ?, ?, 'customer')
        ")->execute([$payment_header_id, $summary_payment_no, $date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $user_id, $customer_id]);
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
        $qty     = (float)$item['total_qty'];
        $gross   = (float)$item['total_gross'];
        $disc    = (float)$item['total_discount'];
        $tax     = (float)$item['total_tax'];
        $net     = (float)$item['total_net'];
        $cogs    = (float)$item['total_cogs'];
        
        $summary_subtotal += $gross;
        $summary_discount += $disc;
        $summary_tax      += $tax;
        $summary_total    += $net;
        $summary_cogs     += $cogs;
        
        $line_income_account = get_effective_account($item_id, 'income') ?: 'acc-4100';
        $line_cogs_account   = get_effective_account($item_id, 'cogs') ?: 'acc-5100';
        $line_inv_account    = get_effective_account($item_id, 'inventory') ?: 'acc-1200';
        
        $sales_distributions[$line_income_account] = ($sales_distributions[$line_income_account] ?? 0) + $gross;
        if ($cogs > 0) {
            $cogs_distributions[$line_cogs_account] = ($cogs_distributions[$line_cogs_account] ?? 0) + $cogs;
            $inv_distributions[$line_inv_account]   = ($inv_distributions[$line_inv_account] ?? 0) + $cogs;
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
        $pay_amount = (float)$pay['total_amount'];
        
        $acc_info = $db->fetchOne("SELECT account_name FROM accounts WHERE id = ?", [$acc_id]);
        $account_name = strtolower($acc_info['account_name'] ?? '');
        
        $mapped_method = 'bank_transfer';
        if (strpos($account_name, 'cash') !== false) {
            $mapped_method = 'cash';
        } elseif (strpos($account_name, 'esewa') !== false) {
            $mapped_method = 'esewa';
        } elseif (strpos($account_name, 'khalti') !== false) {
            $mapped_method = 'khalti';
        }
        
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
        $payment_total += (float)$pay['total_amount'];
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
function check_fiscal_year_lock($date) {
    if (empty($date)) return;
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
function has_permission($permission) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $role = $_SESSION['role'] ?? '';
    
    if (empty($role)) return false;
    if ($role === 'admin') return true;
    
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
function get_report_start_date($as_of) {
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
?>


