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
$json = $GLOBALS['mock_pos_payload'] ?? file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$db  = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $pos_id          = $data['id'] ?? null;
    $is_update       = false;
    $old_invoice_no  = null;
    $old_date        = null;

    $txn_date        = date('Y-m-d');
    if (isset($data['txn_date']) && !empty($data['txn_date'])) {
        $txn_date = date('Y-m-d', strtotime($data['txn_date']));
    }

    // Check closed fiscal year lock
    if ($pos_id) {
        $old_pos = $db->fetchOne("SELECT date_time FROM pos_entry WHERE id = ?", [$pos_id]);
        if ($old_pos) {
            check_fiscal_year_lock(date('Y-m-d', strtotime($old_pos['date_time'])));
        }
    }
    check_fiscal_year_lock($txn_date);
    
    $txn_date_time   = $txn_date . ' ' . date('H:i:s');
    
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

    // 1. If updating, fetch old details, reverse stock, delete old items & payments
    if ($pos_id) {
        $old_pos = $db->fetchOne("SELECT * FROM pos_entry WHERE id = ?", [$pos_id]);
        if ($old_pos) {
            $is_update = true;
            $old_invoice_no = $old_pos['invoice_no'];
            $old_date = date('Y-m-d', strtotime($old_pos['date_time']));
            
            // Revert Stock
            $old_items = $db->fetchAll("SELECT item_id, quantity FROM pos_items WHERE pos_id = ?", [$pos_id]);
            foreach ($old_items as $oi) {
                $db->execute("UPDATE items SET current_stock = current_stock + ? WHERE id = ?", [$oi['quantity'], $oi['item_id']]);
            }
            
            // Delete child items and payments of this entry
            $db->execute("DELETE FROM pos_items WHERE pos_id = ?", [$pos_id]);
            $db->execute("DELETE FROM pos_payments WHERE pos_id = ?", [$pos_id]);
        }
    }

    if (!$pos_id) {
        $pos_id = generate_uuid();
    }

    // Generate unique POS invoice number for individual POS log
    if ($is_update && $old_invoice_no) {
        $txn_number = $old_invoice_no;
    } else {
        $txn_number = 'POS-' . date('Ymd', strtotime($txn_date)) . '-' . mt_rand(1000, 9999);
    }

    // 2. Resolve Customer (Walk-in fallback)
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

    // 3. Save or Update pos_entry
    if ($is_update) {
        $db->execute(
            "UPDATE pos_entry SET date_time = ?, customer_id = ?, gross_amount = ?, discount_type = ?, discount_value = ?, discount_amount = ?, tax_amount = ?, net_amount = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$txn_date_time, $customer_id, $gross_amount, $discount_type, $discount_value, $discount_total, $tax_amount, $net_amount, $pos_id]
        );
    } else {
        $db->execute(
            "INSERT INTO pos_entry (id, invoice_no, date_time, customer_id, gross_amount, discount_type, discount_value, discount_amount, tax_amount, net_amount, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)",
            [$pos_id, $txn_number, $txn_date_time, $customer_id, $gross_amount, $discount_type, $discount_value, $discount_total, $tax_amount, $net_amount, $_SESSION['user_id']]
        );
    }

    // 4. Save items & deduct stock
    foreach ($items as $item) {
        $item_id = $item['id'];
        $qty     = (float)$item['qty'];
        $rate    = (float)$item['price'];
        $line_amount = round($qty * $rate, 2);
        $line_disc = (float)($item['discount'] ?? 0);
        if ($line_disc == 0 && $discount_total > 0 && $gross_amount > 0) {
            $line_disc = round(($line_amount / $gross_amount) * $discount_total, 2);
        }

        $line_tax  = (float)($item['tax']      ?? 0);
        if ($line_tax == 0 && $tax_amount > 0 && $gross_amount > 0) {
            $line_tax = round(($line_amount / $gross_amount) * $tax_amount, 2);
        }

        $line_net  = round($line_amount - $line_disc + $line_tax, 2);

        $item_info  = $db->fetchOne("SELECT cost_price, item_name, current_stock FROM items WHERE id = ?", [$item_id]);
        $available = (float)($item_info['current_stock'] ?? 0);

        // Stock Validation
        if ($available < $qty && !isset($data['force_save'])) {
            throw new Exception("Stock Warning: Item '" . $item_info['item_name'] . "' has only " . number_format($available, 4) . " available.");
        }

        // pos_items
        $db->execute(
            "INSERT INTO pos_items (id, pos_id, item_id, quantity, rate, amount, discount, tax, net_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $pos_id, $item_id, $qty, $rate, $qty * $rate, $line_disc, $line_tax, $line_net]
        );

        // Real-time Stock Deduction
        $db->execute("UPDATE items SET current_stock = current_stock - ? WHERE id = ?", [$qty, $item_id]);
    }

    // 5. Save Payments
    $total_tendered = 0.0;
    foreach ($payments as $pay) {
        $pay_amount = (float)($pay['amount'] ?? 0);
        if ($pay_amount <= 0) continue;
        
        $total_tendered += $pay_amount;

        $acc_info = $db->fetchOne("SELECT account_name FROM accounts WHERE id = ?", [$pay['account_id']]);
        $account_name = strtolower($acc_info['account_name'] ?? '');
        
        $mapped_mode = 'bank';
        if (strpos($account_name, 'cash') !== false) {
            $mapped_mode = 'cash';
        } elseif (strpos($account_name, 'esewa') !== false || strpos($account_name, 'khalti') !== false) {
            $mapped_mode = 'qr';
        }

        // pos_payments
        $db->execute(
            "INSERT INTO pos_payments (id, pos_id, payment_mode, account_id, amount, reference_no)
             VALUES (?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $pos_id, $mapped_mode, $pay['account_id'], $pay_amount, $pay['reference'] ?? null]
        );
    }

    // 6. Handle Change Due (if any)
    $change_due = $total_tendered - $net_amount;
    if ($change_due > 0.01) {
        $change_account = get_accounting_preference('default_change_account') ?: 'acc-1010';

        // Insert negative cash change payment in pos_payments
        $db->execute(
            "INSERT INTO pos_payments (id, pos_id, payment_mode, account_id, amount, reference_no)
             VALUES (?, ?, 'cash', ?, ?, 'Change Return')",
            [generate_uuid(), $pos_id, $change_account, -$change_due]
        );
    }

    $pdo->commit();

    // 7. Regenerate Daily Summary Invoices and Payments
    // We synchronize the summary for the transaction date.
    // If it's an update and the date changed, we also synchronize the old date.
    $dates_to_sync = array_unique(array_filter([$txn_date, $old_date]));
    foreach ($dates_to_sync as $sync_date) {
        sync_daily_pos_summary($sync_date);
    }

    // Look up the daily summary invoice header ID to return in response
    $today_str = date('Ymd', strtotime($txn_date));
    $summary_invoice_no = "INV-POS-" . $today_str;
    $summary_header = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_invoice'", [$summary_invoice_no]);
    $summary_header_id = $summary_header ? $summary_header['id'] : null;

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'POS Transaction saved successfully.',
        'txn_number' => $txn_number,
        'pos_id' => $pos_id,
        'invoice_id' => $summary_header_id // Return the daily summary invoice header ID
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
