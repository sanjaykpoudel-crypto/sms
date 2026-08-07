<?php
ob_start(); // Buffer all output at the very beginning
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once '../database/DBConnection.php';
require_once 'reference_helper.php';
// Load system cache (provides sysinfo_get_batch, account_cache_get/set)
if (!function_exists('sysinfo_get')) {
    require_once __DIR__ . '/system_cache.php';
}
// Pre-fetch ALL system_info into memory in one query
sysinfo_prefetch();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$db = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : get_user_default_location_id();

    $id = $_POST['id'] ?? null;
    $txn_number = $_POST['txn_number'] ?? '';
    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('customer_invoice', $location_id);
        incrementTransactionNumber('customer_invoice');
    }
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $old_txn_date = $txn_date;
    if ($id) {
        $old_header = $db->fetchOne("SELECT txn_date FROM transaction_headers WHERE id = ?", [$id]);
        if ($old_header) {
            $old_txn_date = $old_header['txn_date'];
        }
    }

    // Check closed fiscal year lock
    if ($id && isset($old_txn_date)) {
        check_fiscal_year_lock($old_txn_date);
    }
    check_fiscal_year_lock($txn_date);
    $due_date = $_POST['due_date'] ?? $txn_date;
    $party_id = $_POST['party_id'] ?? null;
    $memo = $_POST['memo'] ?? '';
    
    // Status preservation for edit mode
    if ($id) {
        $existing_hdr = $db->fetchOne("SELECT status FROM transaction_headers WHERE id = ?", [$id]);
        $status = $_POST['status'] ?? ($existing_hdr['status'] ?? 'posted');
    } else {
        $status = $_POST['status'] ?? 'posted';
    }
    
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : get_user_default_location_id();
    
    if (!$party_id) throw new Exception("Customer is required");

    $fiscal = calculate_fiscal_info($txn_date);

    $sale_type = 'credit';

    if (!$id) {
        $db->execute("INSERT INTO transaction_headers (txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, created_by, location_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $txn_number, 'customer_invoice', $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $status, $memo, $_SESSION['user_id'], $location_id
        ]);
        $id = $pdo->lastInsertId();
        incrementTransactionNumber('customer_invoice');
    } else {
        // Fetch old sale_type before deleting
        $old_invoice = $db->fetchOne("SELECT sale_type FROM customer_invoices WHERE header_id = ?", [$id]);
        if ($old_invoice) {
            $sale_type = $old_invoice['sale_type'];
        }

        $db->execute("UPDATE transaction_headers SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, memo = ?, status = ?, location_id = ?, source = 'manual' WHERE id = ?", [
            $txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $memo, $status, $location_id, $id
        ]);
        
        // Reverse old stock
        if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
            $old_items = $db->fetchAll("SELECT item_id, quantity FROM transaction_lines WHERE header_id = ?", [$id]);
            foreach($old_items as $oi) {
                $db->execute("UPDATE items SET current_stock = current_stock + ? WHERE id = ?", [$oi['quantity'], $oi['item_id']]);
            }
        }
        
        $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM customer_invoices WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    $item_ids   = $_POST['item_id']  ?? [];
    $qtys       = $_POST['qty']      ?? [];
    $rates      = $_POST['rate']     ?? [];
    $amounts    = $_POST['amount']   ?? [];
    $tax_rates  = $_POST['tax_pct']  ?? [];

    // ── Batch-fetch ALL item data in ONE query (eliminates N per-line DB hits) ──
    $unique_item_ids = array_values(array_unique(array_filter($item_ids)));
    $item_data_map   = [];  // keyed by item id
    if (!empty($unique_item_ids)) {
        $ph = implode(',', array_fill(0, count($unique_item_ids), '?'));
        $batch_items = $db->fetchAll(
            "SELECT id, sku, cost_price, current_stock, item_name,
                    income_account_id, cogs_account_id, inventory_account_id
             FROM items WHERE id IN ({$ph}) AND is_deleted = 0",
            $unique_item_ids
        );
        foreach ($batch_items as $bi) {
            $item_data_map[$bi['id']] = $bi;
        }
    }

    // ── Pre-fetch accounting preferences in ONE batch query ──
    $acct_prefs = sysinfo_get_batch([
        'default_income_account', 'default_cogs_account',
        'default_asset_account',  'default_ar_account',
        'default_tax_account',    'default_discount_account',
    ]);
    $acct_defaults = [
        'income'    => $acct_prefs['default_income_account']  ?? 'acc-4100',
        'cogs'      => $acct_prefs['default_cogs_account']    ?? 'acc-5100',
        'inventory' => $acct_prefs['default_asset_account']   ?? 'acc-1200',
        'tax'       => $acct_prefs['default_tax_account']     ?? 'acc-2200',
        'discount'  => $acct_prefs['default_discount_account']?? 'acc-6160',
        'ar'        => $acct_prefs['default_ar_account']      ?? 'acc-1100',
    ];

    // ── Resolve AR account (customer-specific or system default) ──
    $ar_account = (!empty($party_id) ? ($db->fetchOne("SELECT receivable_account_id FROM customers WHERE id = ?", [$party_id])['receivable_account_id'] ?? null) : null)
                  ?? $acct_defaults['ar'];

    $subtotal = 0;
    $tax_total = 0;
    $total_cogs = 0;
    $gl_items = [];
    $synced_items = [];

    foreach ($item_ids as $idx => $item_id) {
        if (empty($item_id)) continue;
        $qty      = (float)$qtys[$idx];
        $rate     = (float)$rates[$idx];
        $tax_rate = (float)$tax_rates[$idx];

        $post_amount = isset($amounts[$idx]) && is_numeric($amounts[$idx]) ? (float)$amounts[$idx] : null;
        $line_amount = $post_amount !== null ? round($post_amount, 2) : round($qty * $rate, 2);
        $tax_amount  = round($line_amount * ($tax_rate / 100), 2);
        $line_total  = round($line_amount + $tax_amount, 2);

        $subtotal  += $line_amount;
        $tax_total += $tax_amount;

        // ── Use pre-fetched item data (no per-line DB query) ──
        $item_info = $item_data_map[$item_id] ?? null;
        if (!$item_info) {
            // Fallback: fetch individually if somehow not in batch (shouldn't happen)
            $item_info = $db->fetchOne("SELECT id, sku, cost_price, current_stock, item_name, income_account_id, cogs_account_id, inventory_account_id FROM items WHERE id = ?", [$item_id]);
        }

        // Stock Validation
        if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
            $available = (float)($item_info['current_stock'] ?? 0);
            if ($available < $qty && !isset($_POST['force_save'])) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                ob_end_clean();
                $msg = "Item: " . $item_info['item_name'] . ". Available: " . number_format($available, 4) . ". Do you want to save anyway?";
                echo json_encode(['status' => 'stock_warning', 'message' => $msg]);
                exit;
            }
        }

        $cost_price = ($item_info['sku'] ?? '') === 'I-00013' ? 0.00 : (float)($item_info['cost_price'] ?? 0);
        $line_cogs    = $cost_price * $qty;
        $total_cogs  += $line_cogs;
        $gross_profit = $line_amount - $line_cogs;

        // ── Resolve line accounts using pre-fetched data + cache ──
        $sales_acc = !empty($item_info['income_account_id'])    ? $item_info['income_account_id']    : $acct_defaults['income'];
        $cogs_acc  = !empty($item_info['cogs_account_id'])      ? $item_info['cogs_account_id']      : $acct_defaults['cogs'];
        $inv_acc   = !empty($item_info['inventory_account_id']) ? $item_info['inventory_account_id'] : $acct_defaults['inventory'];

        // Override with explicitly posted account if provided
        $line_account_id = !empty($_POST['account_id'][$idx] ?? null) ? $_POST['account_id'][$idx] : $sales_acc;

        $unit = $_POST['unit'][$idx] ?? '';
        $db->execute(
            "INSERT INTO transaction_lines (id, header_id, item_id, account_id, line_number, quantity, unit, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $id, $item_id, $line_account_id, $idx + 1, $qty, $unit, $rate, $tax_rate, $tax_amount, $line_total, $cost_price, $gross_profit]
        );

        // Deduct new stock and update inventory_balances for the selling location
        if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
            $db->execute("UPDATE items SET current_stock = current_stock - ? WHERE id = ?", [$qty, $item_id]);
            // Also update local batch map so subsequent validation in same request is accurate
            if (isset($item_data_map[$item_id])) {
                $item_data_map[$item_id]['current_stock'] -= $qty;
            }
            // Update cost_price in inventory_balances for the selling location (reflects COGS cost)
            if ($cost_price > 0) {
                $bal_exists = $db->fetchOne("SELECT id FROM inventory_balances WHERE item_id = ? AND location_id = ?", [$item_id, $location_id]);
                if ($bal_exists) {
                    $db->execute("UPDATE inventory_balances SET cost_price = ?, average_cost = ?, last_updated = NOW() WHERE item_id = ? AND location_id = ?", [$cost_price, $cost_price, $item_id, $location_id]);
                } else {
                    $db->execute("INSERT INTO inventory_balances (id, item_id, location_id, quantity_on_hand, available_qty, committed_qty, on_order_qty, average_cost, cost_price, last_updated) VALUES (?, ?, ?, 0, 0, 0, 0, ?, ?, NOW())", [generate_uuid(), $item_id, $location_id, $cost_price, $cost_price]);
                }
            }
        }
        $synced_items[] = $item_id;

        $gl_items[] = [
            'item_id'      => $item_id,
            'sales_acc'    => $sales_acc,
            'sales_amount' => $line_amount,
            'cogs_acc'     => $cogs_acc,
            'cogs_amount'  => $line_cogs,
            'inv_acc'      => $inv_acc,
        ];
    }

    $grand_total = $subtotal + $tax_total - $discount_amount;

    // Customer Credit Limit Validation
    if ($party_id && !isset($_POST['force_save'])) {
        $cust_data = $db->fetchOne("SELECT full_name, COALESCE(credit_limit, 0) as credit_limit FROM customers WHERE id = ?", [$party_id]);
        $credit_limit = (float)($cust_data['credit_limit'] ?? 0);
        if ($credit_limit > 0) {
            $cust_bal = (float)($db->fetchOne("
                SELECT COALESCE(SUM(ci.balance_due), 0) as current_balance
                FROM customer_invoices ci
                JOIN transaction_headers h ON ci.header_id = h.id
                WHERE ci.customer_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('voided', 'draft') AND h.id != ?
            ", [$party_id, $id ?? ''])['current_balance'] ?? 0);

            $new_total_balance = $cust_bal + $grand_total;
            if ($new_total_balance > $credit_limit) {
                $exceeded_amt = $new_total_balance - $credit_limit;
                if ($pdo->inTransaction()) $pdo->rollBack();
                ob_end_clean();
                $msg = "Credit limit exceeded for customer " . $cust_data['full_name'] . "!\nCredit Limit: Rs " . number_format($credit_limit, 2) . "\nCurrent Outstanding: Rs " . number_format($cust_bal, 2) . "\nThis Invoice: Rs " . number_format($grand_total, 2) . "\nNew Total Balance: Rs " . number_format($new_total_balance, 2) . " (Exceeds limit by Rs " . number_format($exceeded_amt, 2) . ").\n\nDo you want to proceed and save anyway?";
                echo json_encode(['status' => 'stock_warning', 'message' => $msg]);
                exit;
            }
        }
    }

    // Calculate total payments applied to this invoice from transaction_links
    $applied_payments = $db->fetchAll("SELECT * FROM transaction_links WHERE child_id = ? AND link_type LIKE 'payment:%'", [$id]);
    $existing_payment_total = 0.0;
    foreach ($applied_payments as $link) {
        $existing_payment_total += (float)(explode(':', $link['link_type'])[1] ?? 0);
    }

    $amount_paid = $existing_payment_total;
    $balance_due = max(0.0, $grand_total - $amount_paid);
    
    $payment_status = 'unpaid';
    if ($balance_due <= 0.01) {
        $payment_status = 'paid';
    } elseif ($amount_paid > 0.01) {
        $payment_status = 'partial';
    }

    // If payment status is paid/partial/unpaid, update the transaction header status, net_amount, and party_id to match
    if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
        $status = ($payment_status === 'paid') ? 'paid' : (($payment_status === 'partial') ? 'partial' : 'open');
        $db->execute("UPDATE transaction_headers SET status = ?, net_amount = ?, party_id = ?, party_type = 'customer' WHERE id = ?", [$status, $grand_total, $party_id, $id]);
    } else {
        $db->execute("UPDATE transaction_headers SET net_amount = ?, party_id = ?, party_type = 'customer' WHERE id = ?", [$grand_total, $party_id, $id]);
    }

    $db->execute("INSERT INTO customer_invoices (header_id, customer_id, invoice_date, due_date, invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status, sale_type) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        $id, $party_id, $txn_date, $due_date, $txn_number, $subtotal, $discount_amount, $tax_total, $grand_total, $amount_paid, $balance_due, $payment_status, $sale_type
    ]);

    // If it's a POS daily summary invoice, we need to update/recreate the underlying POS transactions.
    $is_pos_summary = (strpos($txn_number, 'POS-SUM-') === 0);
    if ($is_pos_summary) {
        // 1. Delete all old POS entries for the old date
        $old_pos_entries = $db->fetchAll("SELECT id FROM pos_entry WHERE DATE(date_time) = ? AND is_deleted = 0", [$old_txn_date]);
        foreach ($old_pos_entries as $pe) {
            $db->execute("DELETE FROM pos_items WHERE pos_id = ?", [$pe['id']]);
            $db->execute("DELETE FROM pos_payments WHERE pos_id = ?", [$pe['id']]);
            $db->execute("DELETE FROM pos_entry WHERE id = ?", [$pe['id']]);
        }

        // 2. Create new consolidated POS entry matching the updated invoice
        $db->execute(
            "INSERT INTO pos_entry (invoice_no, date_time, customer_id, gross_amount, discount_type, discount_value, discount_amount, tax_amount, net_amount, status, created_by)
             VALUES (?, ?, ?, ?, 'fixed', ?, ?, ?, ?, 'completed', ?)",
            [
                $txn_number,
                $txn_date . ' ' . date('H:i:s'),
                $party_id,
                $subtotal,
                $discount_amount,
                $discount_amount,
                $tax_total,
                $grand_total,
                $_SESSION['user_id']
            ]
        );
        $consolidated_pos_id = $pdo->lastInsertId();

        // 3. Create POS items
        foreach ($item_ids as $idx => $item_id) {
            if (empty($item_id)) continue;
            $qty = (float)$qtys[$idx];
            $rate = (float)$rates[$idx];
            $tax_rate = (float)$tax_rates[$idx];
            
            $line_amount = $qty * $rate;
            $tax_amount = $line_amount * ($tax_rate / 100);
            
            $line_discount = 0;
            if ($subtotal > 0) {
                $line_discount = ($line_amount / $subtotal) * $discount_amount;
            }
            $line_net = $line_amount - $line_discount + $tax_amount;

            $db->execute(
                "INSERT INTO pos_items (pos_id, item_id, quantity, rate, amount, discount, tax, net_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $consolidated_pos_id,
                    $item_id,
                    $qty,
                    $rate,
                    $line_amount,
                    $line_discount,
                    $tax_amount,
                    $line_net
                ]
            );
        }

        // 4. Recreate POS payments matching the daily payment summary
        $summary_payment_no = "POS-PAY-" . date('Ymd', strtotime($txn_date));
        $payment_header = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_payment' AND is_deleted = 0", [$summary_payment_no]);
        if ($payment_header) {
            $payment_header_id = $payment_header['id'];
            
            // Re-sync payment header net_amount and party_id too
            $db->execute("UPDATE transaction_headers SET net_amount = ?, party_id = ? WHERE id = ?", [$grand_total, $party_id, $payment_header_id]);
            
            $payments_list = $db->fetchAll("SELECT bank_account_id, amount, payment_method FROM payments WHERE header_id = ?", [$payment_header_id]);
            foreach ($payments_list as $pay) {
                $mapped_mode = 'bank';
                if ($pay['payment_method'] === 'cash') {
                    $mapped_mode = 'cash';
                } elseif (in_array($pay['payment_method'], ['esewa', 'khalti'])) {
                    $mapped_mode = 'qr';
                }
                
                $db->execute(
                    "INSERT INTO pos_payments (id, pos_id, payment_mode, account_id, amount)
                     VALUES (?, ?, ?, ?, ?)",
                    [
                        generate_uuid(),
                        $consolidated_pos_id,
                        $mapped_mode,
                        $pay['bank_account_id'],
                        $pay['amount']
                    ]
                );
            }
        } else {
            // Fallback cash payment
            $default_account = get_accounting_preference('default_cash_account') ?: 'acc-1100';
            $db->execute(
                "INSERT INTO pos_payments (id, pos_id, payment_mode, account_id, amount)
                 VALUES (?, ?, 'cash', ?, ?)",
                [
                    generate_uuid(),
                    $consolidated_pos_id,
                    $default_account,
                    $grand_total
                ]
            );
        }
    }

    // GL Impact
    if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
        // Use pre-fetched account IDs
        $tax_account      = $acct_defaults['tax'];
        $discount_account = $acct_defaults['discount'];
        $cogs_account     = $acct_defaults['cogs'];
        $inventory_account= $acct_defaults['inventory'];

        // Dr Accounts Receivable
        if ($grand_total > 0) {
            $db->execute("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                $id, $ar_account, $grand_total, 'Invoice ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
        // Dr Discount (if any)
        if ($discount_amount > 0) {
            $db->execute("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                $id, $discount_account, $discount_amount, 'Discount ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
        // Cr Sales Revenue (per item)
        foreach ($gl_items as $gi) {
            if ($gi['sales_amount'] > 0) {
                $db->execute("INSERT INTO journal_entries (header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                    $id, $gi['sales_acc'], $gi['item_id'], $gi['sales_amount'], 'Invoice ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
            }
        }
        // Cr Tax Payable
        if ($tax_total > 0) {
            $db->execute("INSERT INTO journal_entries (header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                $id, $tax_account, $tax_total, 'VAT ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
        // COGS and Inventory (per item)
        foreach ($gl_items as $gi) {
            if ($gi['cogs_amount'] > 0) {
                $db->execute("INSERT INTO journal_entries (header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                    $id, $gi['cogs_acc'], $gi['item_id'], $gi['cogs_amount'], 'COGS ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
                $db->execute("INSERT INTO journal_entries (header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                    $id, $gi['inv_acc'], $gi['item_id'], $gi['cogs_amount'], 'Inventory Out ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
            }
        }
    }

    $pdo->commit();
    // Sync inventory balances (stock + cost) across all locations for each affected item
    register_shutdown_function(function() use ($synced_items, $db) {
        try {
            foreach (array_unique($synced_items) as $sync_item_id) {
                sync_and_get_item_inventory_balances($db, $sync_item_id);
            }
            require_once __DIR__ . '/reference_helper.php';
            if (function_exists('auto_sync_pos_items_and_invoices')) {
                auto_sync_pos_items_and_invoices(true);
            }
        } catch (Throwable $e) { /* silent */ }
    });
    clear_dashboard_cache();
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['status' => 'success', 'code' => 200, 'message' => 'Invoice has been saved successfully.', 'id' => $id]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    file_put_contents(__DIR__ . '/../scratch/api_error.log', date('Y-m-d H:i:s') . ' - save_invoice.php: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 400, 'message' => $e->getMessage()]);
    exit;
}




