<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';
require_once __DIR__ . '/InventoryEngine.php';
require_once __DIR__ . '/UnitConversionEngine.php';
if (!function_exists('sysinfo_get')) {
    require_once __DIR__ . '/system_cache.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}
enforce_csrf_protection();

$db = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : get_user_default_location_id();
    $id = $_POST['id'] ?? null;
    $txn_number = $_POST['txn_number'] ?? '';
    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('vendor_bill', $location_id);
        incrementTransactionNumber('vendor_bill');
    }
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');

    // Check closed fiscal year lock
    if ($id) {
        $old_header = $db->fetchOne("SELECT txn_date FROM transaction_headers WHERE id = ?", [$id]);
        if ($old_header) {
            check_fiscal_year_lock($old_header['txn_date']);
        }
    }
    check_fiscal_year_lock($txn_date);

    $due_date = $_POST['due_date'] ?? $txn_date;
    $party_id = $_POST['party_id'] ?? null;
    $ref_number = !empty($_POST['ref_number']) ? $_POST['ref_number'] : $txn_number;
    $memo = $_POST['memo'] ?? '';
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    
    // Status preservation for edit mode
    if ($id) {
        $existing_hdr = $db->fetchOne("SELECT status FROM transaction_headers WHERE id = ?", [$id]);
        $status = $_POST['status'] ?? ($existing_hdr['status'] ?? 'posted');
    } else {
        $status = $_POST['status'] ?? 'posted';
    }
    
    if (!$party_id) throw new Exception("Vendor is required");

    $fiscal = calculate_fiscal_info($txn_date);

    if (!$id) {
        $id = generate_uuid();
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, created_by, location_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $id, $txn_number, 'vendor_bill', $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $status, $ref_number, $memo, $_SESSION['user_id'], $location_id
        ]);
        incrementTransactionNumber('vendor_bill');
    } else {
        $db->execute("UPDATE transaction_headers SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, reference_number = ?, memo = ?, status = ?, location_id = ? WHERE id = ?", [
            $txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $ref_number, $memo, $status, $location_id, $id
        ]);
        
        // Reverse old stock via InventoryEngine
        if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
            InventoryEngine::getInstance()->reverseMovementsForHeader($id, 'Vendor Bill Edit Reversal');
        }
        
        $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM vendor_bills WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    $item_ids = $_POST['item_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $amounts = $_POST['amount'] ?? [];
    $tax_rates = $_POST['tax_pct'] ?? [];
    $mrps = $_POST['mrp'] ?? [];
    
    $subtotal = 0;
    $tax_total = 0;
    $gl_items = [];
    $synced_items = [];

    foreach ($item_ids as $idx => $item_id) {
        if (empty($item_id)) continue;
        $qty = (float)$qtys[$idx];
        $rate = (float)$rates[$idx];
        $tax_rate = (float)$tax_rates[$idx];
        
        $post_amount = isset($amounts[$idx]) && is_numeric($amounts[$idx]) ? (float)$amounts[$idx] : null;
        $line_amount = $post_amount !== null ? round($post_amount, 2) : round($qty * $rate, 2);
        $tax_amount = round($line_amount * ($tax_rate / 100), 2);
        $line_total = round($line_amount + $tax_amount, 2);

        $subtotal += $line_amount;
        $tax_total += $tax_amount;

        $line_account_id = !empty($_POST['account_id'][$idx] ?? null) ? $_POST['account_id'][$idx] : get_effective_account($item_id, 'inventory');

        $unit_raw  = $_POST['unit'][$idx] ?? 'PCS';
        $unit_info = uce_resolve_unit($item_id, $unit_raw);
        $conversion_factor = (float)$unit_info['conversion_factor'];
        $base_qty  = uce_calculate_base_qty($qty, $conversion_factor);
        $base_cost = uce_calculate_base_unit_cost($line_amount, $base_qty);

        $db->execute("INSERT INTO transaction_lines (id, header_id, item_id, account_id, line_number, quantity, unit, conversion_factor, base_qty, base_unit_price, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $item_id, $line_account_id, $idx + 1, $qty, $unit_info['unit_name'], $conversion_factor, $base_qty, $base_cost, $rate, $tax_rate, $tax_amount, $line_total, $base_cost, 0
        ]);
        
        // Add new stock and update moving-average cost via InventoryEngine (using base_qty and base_cost)
        if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
            $item_sku = $db->fetchOne("SELECT sku FROM items WHERE id = ?", [$item_id])['sku'] ?? '';
            $new_cost = ($item_sku === 'I-00013') ? 0.00 : $base_cost;
            
            InventoryEngine::getInstance()->receiveStock($item_id, $location_id, $base_qty, $new_cost, $id, null, 'PURCHASE', $txn_date, [
                'txn_number' => $txn_number
            ]);

            // Update MRP on item master if provided
            $mrp_val = isset($mrps[$idx]) && is_numeric($mrps[$idx]) ? (float)$mrps[$idx] : 0;
            if ($mrp_val > 0) {
                $db->execute("UPDATE items SET mrp = ? WHERE id = ?", [$mrp_val, $item_id]);
                $db->execute("UPDATE inventory_balances SET mrp = ? WHERE item_id = ? AND location_id = ?", [$mrp_val, $item_id, $location_id]);
            }
        }

        $gl_items[] = [
            'item_id' => $item_id,
            'inv_acc' => get_effective_account($item_id, 'inventory') ?: 'acc-1200',
            'amount' => $line_amount
        ];
        $synced_items[] = $item_id;
    }

    $grand_total = $subtotal + $tax_total - $discount_amount;

    // Calculate total payments applied to this bill from transaction_links
    $applied_payments = $db->fetchAll("SELECT * FROM transaction_links WHERE child_id = ? AND link_type LIKE 'payment:%'", [$id]);
    $existing_payment_total = 0.0;
    foreach ($applied_payments as $link) {
        $existing_payment_total += (float)(explode(':', $link['link_type'])[1] ?? 0);
    }

    // If only one payment is linked/created for this bill, and the bill is edited,
    // modify that payment and its GL impact to match the new total if it has a single payment method line.
    if (count($applied_payments) === 1) {
        $pay_header_id = $applied_payments[0]['parent_id'];
        
        // Verify this payment is only applied to this bill and has exactly one payment line/method
        $other_applies = $db->fetchAll("SELECT id FROM transaction_links WHERE parent_id = ?", [$pay_header_id]);
        $payment_rows = $db->fetchAll("SELECT id FROM payments WHERE header_id = ?", [$pay_header_id]);
        if (count($other_applies) === 1 && count($payment_rows) === 1) {
            // Update transaction_links amount
            $db->execute("UPDATE transaction_links SET link_type = ? WHERE parent_id = ? AND child_id = ?", ['payment:' . $grand_total, $pay_header_id, $id]);
            
            // Update payments table amount
            $db->execute("UPDATE payments SET amount = ? WHERE header_id = ?", [$grand_total, $pay_header_id]);
            
            // Update journal entries amount
            $db->execute("UPDATE journal_entries SET amount = ? WHERE header_id = ?", [$grand_total, $pay_header_id]);
            
            // Set amount_paid to grand_total
            $existing_payment_total = $grand_total;
        }
    }

    $amount_paid = $existing_payment_total;
    $balance_due = max(0.0, $grand_total - $amount_paid);
    
    $payment_status = 'unpaid';
    if ($balance_due <= 0.01) {
        $payment_status = 'paid';
    } elseif ($amount_paid > 0.01) {
        $payment_status = 'partial';
    }

    // If payment status is paid/partial, update the transaction header status as well
    if ($payment_status !== 'unpaid' && in_array($status, ['posted', 'paid', 'partial', 'open'])) {
        $status = $payment_status;
        $db->execute("UPDATE transaction_headers SET status = ? WHERE id = ?", [$status, $id]);
    }

    $db->execute("INSERT INTO vendor_bills (id, header_id, vendor_id, bill_date, due_date, vendor_invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $party_id, $txn_date, $due_date, $ref_number, $subtotal, $discount_amount, $tax_total, $grand_total, $amount_paid, $balance_due, $payment_status
    ]);

    // GL Impact
    if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
        $engine = AccountingEngine::getInstance();
        $ap_account  = $engine->resolveVendorAPAccount($party_id);
        $tax_account = $engine->resolveAccount('default_tax_account');

        // Dr Inventory (per item)
        foreach ($gl_items as $gi) {
            if ($gi['amount'] > 0) {
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $gi['inv_acc'], $gi['item_id'], $gi['amount'], 'Bill ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
            }
        }
        if ($tax_total > 0) {
            $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                generate_uuid(), $id, $tax_account, $tax_total, 'VAT ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
        if ($discount_amount > 0) {
            $disc_account = $engine->resolveAccount('default_discount_account');
            $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                generate_uuid(), $id, $disc_account, $discount_amount, 'Discount ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
        if ($grand_total > 0) {
            $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                generate_uuid(), $id, $ap_account, $grand_total, 'Bill ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
    }

    $pdo->commit();
    // Sync inventory balances (stock + cost) across all locations for each affected item
    foreach (array_unique($synced_items) as $sync_item_id) {
        sync_and_get_item_inventory_balances($db, $sync_item_id);
    }
    require_once __DIR__ . '/reference_helper.php';
    if (function_exists('auto_sync_pos_items_and_invoices')) {
        auto_sync_pos_items_and_invoices(true);
    }
    clear_dashboard_cache();
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['status' => 'success', 'code' => 200, 'message' => 'Vendor Bill has been saved successfully.', 'id' => $id]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 400, 'message' => $e->getMessage()]);
    exit;
}



