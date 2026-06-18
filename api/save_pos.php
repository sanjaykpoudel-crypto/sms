<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$db  = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $pos_id          = generate_uuid();
    $txn_number      = $data['txn_number']      ?? ('POS-' . date('Ymd') . '-' . rand(1000, 9999));
    $txn_date_time   = date('Y-m-d H:i:s');
    $txn_date        = date('Y-m-d');
    
    $gross_amount    = (float)($data['gross_amount']    ?? 0);
    $discount_type   = $data['discount_type']           ?? 'fixed';
    $discount_value  = (float)($data['discount_value']  ?? 0);
    $discount_total  = (float)($data['discount_amount'] ?? 0);
    $tax_amount      = (float)($data['tax_amount']      ?? 0);
    $net_amount      = (float)($data['net_amount']      ?? 0);
    
    $items           = $data['items']    ?? [];
    $payments        = $data['payments'] ?? [];
    $customer_id     = $data['customer_id'] ?? null;

    $fiscal = calculate_fiscal_info($txn_date);

    // 1. Resolve Customer (Walk-in fallback)
    if (!$customer_id) {
        $default_customer = get_accounting_preference('default_customer_id');
        if ($default_customer) {
            $check = $db->fetchOne("SELECT id FROM customers WHERE id = ?", [$default_customer]);
            if ($check) $customer_id = $default_customer;
        }
        if (!$customer_id) {
            $first = $db->fetchOne("SELECT id FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY created_at ASC LIMIT 1");
            if ($first) $customer_id = $first['id'];
        }
    }

    if (!$customer_id) {
        throw new Exception("No active customer found. Please create a 'Walk-in Customer' first.");
    }

    // 2. Insert into pos_entry (Always unique per transaction)
    $db->execute(
        "INSERT INTO pos_entry (id, invoice_no, date_time, customer_id, gross_amount, discount_type, discount_value, discount_amount, tax_amount, net_amount, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)",
        [$pos_id, $txn_number, $txn_date_time, $customer_id, $gross_amount, $discount_type, $discount_value, $discount_total, $tax_amount, $net_amount, $_SESSION['user_id']]
    );

    // 3. ERP Summary Logic - Find or Create Daily Summary Header
    $summary_txn_number = "POS-SUM-" . date('Ymd', strtotime($txn_date));
    $payment_txn_number = "POS-PAY-" . date('Ymd', strtotime($txn_date));

    $summary_header = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_invoice' AND is_deleted = 0", [$summary_txn_number]);
    $payment_header = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_payment' AND is_deleted = 0", [$payment_txn_number]);

    // Sales Header
    if ($summary_header) {
        $header_id = $summary_header['id'];
    } else {
        $header_id = generate_uuid();
        $db->execute(
            "INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type)
             VALUES (?, ?, 'customer_invoice', ?, ?, ?, ?, 'posted', ?, ?, 'customer')",
            [$header_id, $summary_txn_number, $txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $_SESSION['user_id'], $customer_id]
        );
        
        $db->execute(
            "INSERT INTO customer_invoices (id, header_id, customer_id, invoice_date, due_date, invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status, sale_type)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 'paid', 'cash')",
            [generate_uuid(), $header_id, $customer_id, $txn_date, $txn_date, $summary_txn_number]
        );
    }

    // Payment Header
    if ($payment_header) {
        $pay_header_id = $payment_header['id'];
    } else {
        $pay_header_id = generate_uuid();
        $db->execute(
            "INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type)
             VALUES (?, ?, 'customer_payment', ?, ?, ?, ?, 'posted', ?, ?, 'customer')",
            [$pay_header_id, $payment_txn_number, $txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $_SESSION['user_id'], $customer_id]
        );
    }

    // Update Customer Invoice Totals
    $db->execute(
        "UPDATE customer_invoices SET subtotal = subtotal + ?, discount_amount = discount_amount + ?, tax_amount = tax_amount + ?, total_amount = total_amount + ?, amount_paid = amount_paid + ? WHERE header_id = ?",
        [$gross_amount, $discount_total, $tax_amount, $net_amount, $net_amount, $header_id]
    );

    // 4. Items Processing (pos_items + ERP Lines + Stock)
    $total_cost = 0;
    $grouped_items = [];
    foreach ($items as $item) {
        $id = $item['id'];
        if (!isset($grouped_items[$id])) {
            $grouped_items[$id] = [
                'id' => $id,
                'qty' => 0,
                'rate' => (float)$item['price'],
                'disc' => 0,
                'tax' => 0,
                'net' => 0
            ];
        }
        $grouped_items[$id]['qty']  += (float)$item['qty'];
        $grouped_items[$id]['disc'] += (float)($item['discount'] ?? 0);
        $grouped_items[$id]['tax']  += (float)($item['tax']      ?? 0);
        $grouped_items[$id]['net']  += (float)($item['net']      ?? 0);

        // Individual pos_items for audit
        $db->execute(
            "INSERT INTO pos_items (id, pos_id, item_id, quantity, rate, amount, discount, tax, net_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $pos_id, $id, (float)$item['qty'], (float)$item['price'], (float)$item['qty'] * (float)$item['price'], (float)($item['discount'] ?? 0), (float)($item['tax'] ?? 0), (float)($item['net'] ?? 0)]
        );
    }

    foreach ($grouped_items as $item_id => $g_item) {
        $qty       = $g_item['qty'];
        $rate      = $g_item['rate'];
        $line_tax  = $g_item['tax'];
        $line_net  = $g_item['net'];

        $item_info  = $db->fetchOne("SELECT cost_price FROM items WHERE id = ?", [$item_id]);
        $cost_price = (float)($item_info['cost_price'] ?? 0);
        $total_cost += ($cost_price * $qty);

        // ERP transaction_lines (Upsert)
        $existing_line = $db->fetchOne("SELECT id, quantity, tax_amount, line_total, gross_profit FROM transaction_lines WHERE header_id = ? AND item_id = ?", [$header_id, $item_id]);
        if ($existing_line) {
            $db->execute(
                "UPDATE transaction_lines SET quantity = quantity + ?, tax_amount = tax_amount + ?, line_total = line_total + ?, gross_profit = gross_profit + ? WHERE id = ?",
                [$qty, $line_tax, $line_net, $line_net - ($cost_price * $qty), $existing_line['id']]
            );
        } else {
            $line_income_account = get_effective_account($item_id, 'income');
            // Get current max line number
            $max_line = $db->fetchOne("SELECT MAX(line_number) as max_ln FROM transaction_lines WHERE header_id = ?", [$header_id]);
            $next_ln = ($max_line['max_ln'] ?? 0) + 1;

            $db->execute(
                "INSERT INTO transaction_lines (id, header_id, item_id, account_id, line_number, quantity, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [generate_uuid(), $header_id, $item_id, $line_income_account, $next_ln, $qty, $rate, ($line_tax/max(0.01,($qty*$rate)))*100, $line_tax, $line_net, $cost_price, $line_net - ($cost_price * $qty), $_SESSION['user_id']]
            );
        }

        // Real-time Stock Deduction
        $db->execute("UPDATE items SET current_stock = current_stock - ? WHERE id = ?", [$qty, $item_id]);
    }

    // 5. Payments (Aggregate by mode)
    foreach ($payments as $pay) {
        $pay_amount = (float)($pay['amount'] ?? 0);
        if ($pay_amount <= 0) continue;
        
        // Resolve payment account name and map to correct method & mode
        $acc_info = $db->fetchOne("SELECT account_name FROM accounts WHERE id = ?", [$pay['account_id']]);
        $account_name = strtolower($acc_info['account_name'] ?? '');
        
        $mapped_method = 'bank_transfer';
        $mapped_mode = 'bank';
        
        if (strpos($account_name, 'cash') !== false) {
            $mapped_method = 'cash';
            $mapped_mode = 'cash';
        } elseif (strpos($account_name, 'esewa') !== false) {
            $mapped_method = 'esewa';
            $mapped_mode = 'qr';
        } elseif (strpos($account_name, 'khalti') !== false) {
            $mapped_method = 'khalti';
            $mapped_mode = 'qr';
        }
        
        // Individual pos_payments
        $db->execute(
            "INSERT INTO pos_payments (id, pos_id, payment_mode, account_id, amount, reference_no)
             VALUES (?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $pos_id, $mapped_mode, $pay['account_id'], $pay['amount'], $pay['reference'] ?? null]
        );

        // ERP payments (Upsert by method) - Linked to Payment Header and Applied to Daily Sales Invoice
        $existing_pay = $db->fetchOne("SELECT id FROM payments WHERE header_id = ? AND payment_method = ? AND bank_account_id = ?", [$pay_header_id, $mapped_method, $pay['account_id']]);
        if ($existing_pay) {
            $db->execute("UPDATE payments SET amount = amount + ?, applied_to_txn_id = ? WHERE id = ?", [$pay_amount, $header_id, $existing_pay['id']]);
        } else {
            $db->execute(
                "INSERT INTO payments (id, header_id, payment_type, customer_id, payment_method, bank_account_id, amount, payment_date, created_by, applied_to_txn_id)
                 VALUES (?, ?, 'customer_payment', ?, ?, ?, ?, ?, ?, ?)",
                [generate_uuid(), $pay_header_id, $customer_id, $mapped_method, $pay['account_id'], $pay_amount, $txn_date, $_SESSION['user_id'], $header_id]
            );
        }

        // Record / update transaction link
        $existing_link = $db->fetchOne("SELECT id, link_type FROM transaction_links WHERE parent_id = ? AND child_id = ?", [$pay_header_id, $header_id]);
        if ($existing_link) {
            $existing_amt = (float)(explode(':', $existing_link['link_type'])[1] ?? 0);
            $new_amt = $existing_amt + $pay_amount;
            $db->execute("UPDATE transaction_links SET link_type = ? WHERE id = ?", ['payment:' . $new_amt, $existing_link['id']]);
        } else {
            $db->execute("INSERT INTO transaction_links (id, parent_id, child_id, link_type) VALUES (?, ?, ?, ?)", [
                generate_uuid(), $pay_header_id, $header_id, 'payment:' . $pay_amount
            ]);
        }

        // GL Impact: Dr Cash/Bank, Cr AR (On Payment Header)
        $ar_account = get_effective_account($customer_id, 'receivable');
        upsert_journal_entry($db, $pay_header_id, $pay['account_id'], 'debit', $pay_amount, 'POS Summary Payment - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
        upsert_journal_entry($db, $pay_header_id, $ar_account, 'credit', $pay_amount, 'POS Summary Payment - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
    }

    // Handle Change Due (If any)
    $total_tendered = array_sum(array_column($payments, 'amount'));
    $change_due = $total_tendered - $net_amount;
    if ($change_due > 0.01) {
        $change_account = get_accounting_preference('default_change_account') ?: 'acc-1010';
        
        // ERP payment (Negative) - Linked to Payment Header and Applied to Daily Sales Invoice
        $existing_change = $db->fetchOne("SELECT id FROM payments WHERE header_id = ? AND payment_method = 'cash' AND amount < 0", [$pay_header_id]);
        if ($existing_change) {
            $db->execute("UPDATE payments SET amount = amount - ?, applied_to_txn_id = ? WHERE id = ?", [$change_due, $header_id, $existing_change['id']]);
        } else {
            $db->execute(
                "INSERT INTO payments (id, header_id, payment_type, customer_id, payment_method, bank_account_id, amount, payment_date, created_by, applied_to_txn_id)
                 VALUES (?, ?, 'customer_payment', ?, 'cash', ?, ?, ?, ?, ?)",
                [generate_uuid(), $pay_header_id, $customer_id, $change_account, -$change_due, $txn_date, $_SESSION['user_id'], $header_id]
            );
        }

        // GL Impact: Cr Cash (from Payment Header), Dr AR
        upsert_journal_entry($db, $pay_header_id, $change_account, 'credit', $change_due, 'POS Summary Change Due - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
        upsert_journal_entry($db, $pay_header_id, $ar_account, 'debit', $change_due, 'POS Summary Change Due - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
    }

    // 6. GL Impact: Credits (Revenue, Tax) & Dr (Discount)
    $sales_account    = get_accounting_preference('default_income_account')   ?: 'acc-4100';
    $tax_account      = get_accounting_preference('default_tax_account')      ?: 'acc-2200';
    $discount_account = get_accounting_preference('default_discount_account') ?: 'acc-6160';
    $cogs_account     = get_accounting_preference('default_cogs_account')     ?: 'acc-5100';
    $inventory_account = get_accounting_preference('default_asset_account')    ?: 'acc-1200';

    if ($gross_amount > 0) {
        $ar_account = get_effective_account($customer_id, 'receivable');
        upsert_journal_entry($db, $header_id, $sales_account, 'credit', $gross_amount, 'POS Summary Sales - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
        upsert_journal_entry($db, $header_id, $ar_account, 'debit', $net_amount, 'POS Summary Sales - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
    }
    if ($tax_amount > 0) {
        upsert_journal_entry($db, $header_id, $tax_account, 'credit', $tax_amount, 'POS Summary VAT - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
    }
    if ($discount_total > 0) {
        upsert_journal_entry($db, $header_id, $discount_account, 'debit', $discount_total, 'POS Summary Discount - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
    }

    // COGS Impact
    if ($total_cost > 0) {
        upsert_journal_entry($db, $header_id, $cogs_account, 'debit', $total_cost, 'POS Summary COGS - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
        upsert_journal_entry($db, $header_id, $inventory_account, 'credit', $total_cost, 'POS Summary Inventory Out - ' . date('Y-m-d'), $_SESSION['user_id'], $txn_date, $fiscal);
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'POS Transaction saved successfully.', 'txn_number' => $txn_number, 'pos_id' => $pos_id, 'summary_invoice' => $summary_txn_number]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Helper to upsert journal entries for summary aggregation
 */
function upsert_journal_entry($db, $header_id, $account_id, $type, $amount, $memo, $user_id, $date, $fiscal) {
    $existing = $db->fetchOne("SELECT id FROM journal_entries WHERE header_id = ? AND account_id = ? AND entry_type = ?", [$header_id, $account_id, $type]);
    if ($existing) {
        $db->execute("UPDATE journal_entries SET amount = amount + ? WHERE id = ?", [$amount, $existing['id']]);
    } else {
        $db->execute(
            "INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $header_id, $account_id, $type, $amount, $memo, $user_id, $date, $fiscal['period'], $fiscal['year']]
        );
    }
}
