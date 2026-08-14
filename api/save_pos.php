<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';
require_once __DIR__ . '/InventoryEngine.php';
require_once __DIR__ . '/UnitConversionEngine.php';
require_once __DIR__ . '/PromotionEngine.php';

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
    $customer_id     = !empty($data['customer_id']) ? $data['customer_id'] : get_default_pos_customer_id();

    $fiscal = calculate_fiscal_info($txn_date);

    // 1. If updating, fetch old details, reverse stock, delete old items & payments
    if ($pos_id) {
        $old_pos = $db->fetchOne("SELECT * FROM pos_entry WHERE id = ?", [$pos_id]);
        if ($old_pos) {
            $is_update = true;
            $old_invoice_no = $old_pos['invoice_no'];
            $old_date = date('Y-m-d', strtotime($old_pos['date_time']));
            
            // Revert Stock via InventoryEngine
            InventoryEngine::getInstance()->reverseMovementsForHeader($pos_id, 'POS Sale Edit Reversal');
            
            // Delete child items and payments of this entry
            $db->execute("DELETE FROM pos_items WHERE pos_id = ?", [$pos_id]);
            $db->execute("DELETE FROM pos_payments WHERE pos_id = ?", [$pos_id]);
        }
    }

    if (!$pos_id) {
        $pos_id = generate_uuid();
    }

    $user_id = $_SESSION['user_id'] ?? ($_SESSION['userdata']['id'] ?? '');
    $user_info = $db->fetchOne("SELECT * FROM users WHERE id = CAST(? AS CHAR) OR username = ? LIMIT 1", [$user_id, $user_id]);
    $is_admin = ($user_info && strtolower($user_info['role'] ?? '') === 'admin');

    $raw_location = $data['location_id'] ?? ($_POST['location_id'] ?? '');
    if (!$is_admin || empty($raw_location)) {
        $raw_location = $user_info['location_id'] ?? (function_exists('get_user_default_location_id') ? get_user_default_location_id() : '1');
    }
    $location_id = resolve_location_id($raw_location);

    // Generate unique POS invoice number for individual POS log
    if ($is_update && $old_invoice_no) {
        $txn_number = $old_invoice_no;
    } else {
        $txn_number = getNextTransactionNumber('pos_entry', $location_id);
        incrementTransactionNumber('pos_entry');
    }

    // 3. Save or Update pos_entry
    if ($is_update) {
        $db->execute(
            "UPDATE pos_entry SET date_time = ?, customer_id = ?, gross_amount = ?, discount_type = ?, discount_value = ?, discount_amount = ?, tax_amount = ?, net_amount = ?, location_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$txn_date_time, $customer_id, $gross_amount, $discount_type, $discount_value, $discount_total, $tax_amount, $net_amount, $location_id, $pos_id]
        );
    } else {
        $db->execute(
            "INSERT INTO pos_entry (invoice_no, date_time, customer_id, gross_amount, discount_type, discount_value, discount_amount, tax_amount, net_amount, status, created_by, location_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)",
            [$txn_number, $txn_date_time, $customer_id, $gross_amount, $discount_type, $discount_value, $discount_total, $tax_amount, $net_amount, $user_id, $location_id]
        );
        $pos_id = $pdo->lastInsertId();
    }

    // 4. Save items & deduct stock via InventoryEngine
    foreach ($items as $item) {
        $item_id = $item['id'] ?? $item['item_id'] ?? null;
        $qty     = (float)($item['qty'] ?? $item['quantity'] ?? 0);
        $rate    = (float)($item['price'] ?? $item['unit_price'] ?? $item['rate'] ?? 0);
        
        if (empty($item_id) || $qty <= 0) {
            continue;
        }

        $line_amount = round($qty * $rate, 2);
        $line_disc = (float)($item['discount'] ?? $item['discount_amount'] ?? 0);
        if ($line_disc == 0 && $discount_total > 0 && $gross_amount > 0) {
            $line_disc = round(($line_amount / $gross_amount) * $discount_total, 2);
        }

        $line_tax  = (float)($item['tax'] ?? $item['tax_amount'] ?? 0);
        if ($line_tax == 0 && $tax_amount > 0 && $gross_amount > 0) {
            $line_tax = round(($line_amount / $gross_amount) * $tax_amount, 2);
        }

        $line_net  = round($line_amount - $line_disc + $line_tax, 2);

        $raw_unit  = $item['unit'] ?? 'PCS';
        $unit_info = uce_resolve_unit($item_id, $raw_unit);
        $conversion_factor = (float)$unit_info['conversion_factor'];
        $base_qty  = uce_calculate_base_qty($qty, $conversion_factor);

        // Stock Validation against user's location via InventoryEngine (in base_qty PCS)
        $available = InventoryEngine::getInstance()->getAvailableStock($item_id, $location_id);
        if ($available < $base_qty && !isset($data['force_save'])) {
            $item_info  = $db->fetchOne("SELECT item_name FROM items WHERE id = ?", [$item_id]);
            $itemName = $item_info['item_name'] ?? ('Item #' . $item_id);
            throw new Exception("Stock Warning: Item '" . $itemName . "' has only " . number_format($available, 2) . " PCS available at this location (Requested: " . number_format($base_qty, 2) . " PCS / " . $qty . " " . $unit_info['unit_name'] . ").");
        }

        $promo_id   = !empty($item['promotion_id']) ? (int)$item['promotion_id'] : null;
        $promo_code = !empty($item['promo_code']) ? $item['promo_code'] : null;
        $mrp_sale   = (float)($item['mrp_at_sale'] ?? 0);
        $norm_sell  = (float)($item['normal_selling_price_at_sale'] ?? 0);
        $promo_disc = (float)($item['promo_discount_amount'] ?? 0);

        // Server-side promotion re-validation: evaluate the best applicable promotion
        $serverPromoEval = PromotionEngine::getInstance()->evaluateItemPromotion($item_id, $location_id, $qty, $mrp_sale ?: null, $norm_sell ?: null);
        if ($serverPromoEval['has_promotion']) {
            // Use server-validated promo values (authoritative)
            $promo_id   = $serverPromoEval['promotion']['id'];
            $promo_code = $serverPromoEval['promotion']['promo_code'];
            $promo_disc = $serverPromoEval['discount_amount_per_unit'];
            if ($mrp_sale == 0)   $mrp_sale  = $serverPromoEval['mrp'];
            if ($norm_sell == 0)  $norm_sell = $serverPromoEval['normal_selling_price'];
            // If client sent the exact promo price, keep it; else override with server price
            $expected_promo_price = round($serverPromoEval['promotional_selling_price'], 2);
            $client_rate = round($rate, 2);
            if (abs($client_rate - $expected_promo_price) > 0.02) {
                // Override rate to server-computed promotional price (tamper protection)
                $rate = $expected_promo_price;
            }
        } else {
            // No server promotion, clear promo fields for integrity
            $promo_id   = null;
            $promo_code = null;
            $promo_disc = 0;
        }

        if ($mrp_sale == 0 || $norm_sell == 0) {
            $master_item = $db->fetchOne("SELECT mrp, selling_price FROM items WHERE id = ?", [$item_id]);
            if ($master_item) {
                if ($mrp_sale == 0) $mrp_sale = (float)$master_item['mrp'];
                if ($norm_sell == 0) $norm_sell = (float)$master_item['selling_price'];
            }
        }

        // pos_items
        $db->execute(
            "INSERT INTO pos_items (pos_id, item_id, promotion_id, promo_code, quantity, txn_unit, conversion_factor, base_qty, rate, mrp_at_sale, normal_selling_price_at_sale, promo_discount_amount, amount, discount, tax, net_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$pos_id, $item_id, $promo_id, $promo_code, $qty, $unit_info['unit_name'], $conversion_factor, $base_qty, $rate, $mrp_sale, $norm_sell, $promo_disc, $qty * $rate, $line_disc, $line_tax, $line_net]
        );

        // Issue stock via InventoryEngine using base_qty
        InventoryEngine::getInstance()->issueStock($item_id, $location_id, $base_qty, $pos_id, null, 'POS', $rate, date('Y-m-d', strtotime($txn_date_time)), [
            'txn_number' => $txn_number,
            'force_issue' => isset($data['force_save'])
        ]);
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
            "INSERT INTO pos_payments (pos_id, payment_mode, account_id, amount, reference_no)
             VALUES (?, ?, ?, ?, ?)",
            [$pos_id, $mapped_mode, $pay['account_id'], $pay_amount, $pay['reference'] ?? null]
        );
    }

    // 6. Handle Change Due (if any)
    $change_due = $total_tendered - $net_amount;
    if ($change_due > 0.01) {
        $change_account = AccountingEngine::getInstance()->resolveAccount('default_cash_account');

        // Insert negative cash change payment in pos_payments
        $db->execute(
            "INSERT INTO pos_payments (pos_id, payment_mode, account_id, amount, reference_no)
             VALUES (?, 'cash', ?, ?, 'Change Return')",
            [$pos_id, $change_account, -$change_due]
        );
    }

    // 7. Regenerate Daily Summary Invoices and Payments (INSIDE transaction block)
    $dates_to_sync = array_unique(array_filter([$txn_date, $old_date]));
    foreach ($dates_to_sync as $sync_date) {
        sync_daily_pos_summary($sync_date);
    }

    $pdo->commit();
    clear_dashboard_cache();

    // Look up the daily summary invoice header ID to return in response
    $today_str = date('Ymd', strtotime($txn_date));
    $summary_invoice_no = "INV-POS-" . $today_str;
    $summary_header = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_invoice'", [$summary_invoice_no]);
    $summary_header_id = $summary_header ? $summary_header['id'] : null;

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'code' => 200,
        'message' => 'POS Transaction saved successfully.',
        'txn_number' => $txn_number,
        'pos_id' => $pos_id,
        'invoice_id' => $summary_header_id
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'code' => 400,
        'message' => $e->getMessage()
    ]);
}
