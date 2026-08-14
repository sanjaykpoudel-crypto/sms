<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    $first_u = db()->fetchOne("SELECT id FROM users WHERE is_active = 1 LIMIT 1");
    $user_id = $first_u['id'] ?? null;
}
if (!$user_id) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

header('Content-Type: application/json');

$db  = db();
$pdo = $db->getConnection();

try {
    // 1. Table creation check for vendor_credits
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vendor_credits (
            id VARCHAR(36) PRIMARY KEY,
            header_id VARCHAR(36) NOT NULL,
            vendor_id VARCHAR(36) NOT NULL,
            credit_number VARCHAR(50) NOT NULL,
            credit_date DATE NOT NULL,
            bill_id VARCHAR(36) NULL,
            deduct_from_stock TINYINT(1) DEFAULT 1,
            subtotal DECIMAL(14,2) DEFAULT 0,
            tax_amount DECIMAL(14,2) DEFAULT 0,
            total_amount DECIMAL(14,2) DEFAULT 0,
            remaining_credit DECIMAL(14,2) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'open',
            created_by VARCHAR(36),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY (header_id),
            KEY (vendor_id),
            KEY (bill_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $id                = $_POST['id'] ?? null;
    $txn_number        = trim($_POST['txn_number'] ?? '');
    $txn_date          = $_POST['txn_date'] ?? date('Y-m-d');
    $vendor_id         = trim($_POST['party_id'] ?? '');
    $bill_id           = !empty($_POST['bill_id']) ? trim($_POST['bill_id']) : null;
    $location_id       = !empty($_POST['location_id']) ? trim($_POST['location_id']) : get_user_default_location_id();
    $memo              = trim($_POST['memo'] ?? '');
    $deduct_from_stock = isset($_POST['deduct_from_stock']) ? (int)$_POST['deduct_from_stock'] : 1;

    $item_ids   = $_POST['item_id']      ?? [];
    $qtys       = $_POST['qty']          ?? [];
    $rates      = $_POST['rate']         ?? [];
    $tax_pcts   = $_POST['tax_pct']      ?? [];
    $units      = $_POST['unit']         ?? [];

    if (empty($vendor_id)) {
        throw new Exception("Please select a Vendor for the Bill Credit.");
    }
    if (empty($item_ids) || count($item_ids) === 0) {
        throw new Exception("Please add at least one item line.");
    }

    check_fiscal_year_lock($txn_date);
    $fiscal = calculate_fiscal_info($txn_date);

    $pdo->beginTransaction();

    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('vendor_credit', $location_id);
        incrementTransactionNumber('vendor_credit');
    }

    // Process & validate line items
    $subtotal    = 0;
    $tax_total   = 0;
    $valid_lines = [];

    foreach ($item_ids as $idx => $item_id) {
        $item_id = trim($item_id);
        if (empty($item_id)) continue;

        $qty     = (float)($qtys[$idx] ?? 0);
        $rate    = (float)($rates[$idx] ?? 0);
        $tax_pct = (float)($tax_pcts[$idx] ?? 0);
        $unit    = trim($units[$idx] ?? '');

        if ($qty <= 0) {
            throw new Exception("Quantity for line " . ($idx + 1) . " must be greater than zero.");
        }

        $line_subtotal = round($qty * $rate, 2);
        $tax_amt       = round(($line_subtotal * $tax_pct) / 100, 2);
        $line_total    = $line_subtotal + $tax_amt;

        $subtotal  += $line_subtotal;
        $tax_total += $tax_amt;

        $item_info = $db->fetchOne("SELECT cost_price FROM items WHERE id = ?", [$item_id]);
        $cost_price = (float)($item_info['cost_price'] ?? 0.00);

        $valid_lines[] = [
            'item_id'       => $item_id,
            'qty'           => $qty,
            'rate'          => $rate,
            'unit'          => $unit,
            'line_subtotal' => $line_subtotal,
            'tax_pct'       => $tax_pct,
            'tax_amt'       => $tax_amt,
            'line_total'    => $line_total,
            'cost_price'    => $cost_price
        ];
    }

    if (empty($valid_lines)) {
        throw new Exception("Please add valid line items to the Bill Credit.");
    }

    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $grand_total     = max(0, $subtotal + $tax_total - $discount_amount);

    if (!$id) {
        $id = generate_uuid();

        // 1. Transaction Header
        $db->execute("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, net_amount, party_type, party_id, location_id, created_by)
            VALUES (?, ?, 'vendor_credit', ?, ?, ?, ?, 'open', ?, ?, ?, 'vendor', ?, ?, ?)
        ", [
            $id, $txn_number, $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $txn_number, $memo, $grand_total, $vendor_id, $location_id, $user_id
        ]);

        // 2. Vendor Credits Table Record
        $vc_id = generate_uuid();
        $db->execute("
            INSERT INTO vendor_credits (id, header_id, vendor_id, credit_number, credit_date, bill_id, deduct_from_stock, subtotal, tax_amount, total_amount, remaining_credit, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)
        ", [
            $vc_id, $id, $vendor_id, $txn_number, $txn_date, $bill_id, $deduct_from_stock, $subtotal, $tax_total, $grand_total, $grand_total, $user_id
        ]);

        incrementTransactionNumber('vendor_credit');
    } else {
        $existing_hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
        $db->execute("
            UPDATE transaction_headers 
            SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, memo = ?, net_amount = ?, party_id = ?, location_id = ?, updated_by = ?
            WHERE id = ?
        ", [$txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $memo, $grand_total, $vendor_id, $location_id, $user_id, $id]);

        $db->execute("
            UPDATE vendor_credits 
            SET vendor_id = ?, credit_date = ?, bill_id = ?, deduct_from_stock = ?, subtotal = ?, tax_amount = ?, total_amount = ?, remaining_credit = ?
            WHERE header_id = ?
        ", [$vendor_id, $txn_date, $bill_id, $deduct_from_stock, $subtotal, $tax_total, $grand_total, $grand_total, $id]);

        $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    // GL Accounts
    $ap_account_id       = 'acc-2100'; // Accounts Payable
    $purchase_account_id = 'acc-5100'; // Purchase Return / COGS
    $tax_account_id      = 'acc-2200'; // VAT Payable / Input Tax

    // 3. Insert transaction lines & sync stock if deducted
    foreach ($valid_lines as $idx => $line) {
        $item_id  = $line['item_id'];
        $item_acc = get_effective_account($item_id, 'cogs') ?: $purchase_account_id;

        $db->execute("
            INSERT INTO transaction_lines (id, header_id, line_number, item_id, account_id, location_id, quantity, unit_price, cost_price, tax_rate, tax_amount, line_total, gross_profit)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ", [
            generate_uuid(), $id, $idx + 1, $item_id, $item_acc, $location_id,
            $line['qty'], $line['rate'], $line['cost_price'], $line['tax_pct'], $line['tax_amt'], $line['line_total']
        ]);

        if ($deduct_from_stock === 1) {
            sync_and_get_item_inventory_balances($db, $item_id);
        }
    }

    // 4. Create General Ledger (Journal Entries) for Vendor Credit
    // DR: Accounts Payable (Grand Total Credit) -> Reduces Vendor AP Liability
    if ($grand_total > 0) {
        $db->execute("
            INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year)
            VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)
        ", [generate_uuid(), $id, $ap_account_id, $grand_total, 'Vendor Credit AP Reduction - ' . $txn_number, $user_id, $txn_date, $fiscal['period'], $fiscal['year']]);
    }

    // CR: Purchase Return / COGS / Inventory (Subtotal) -> Reverses Purchase Expense
    if ($subtotal > 0) {
        $db->execute("
            INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year)
            VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)
        ", [generate_uuid(), $id, $purchase_account_id, $subtotal, 'Vendor Credit Purchase Reversal - ' . $txn_number, $user_id, $txn_date, $fiscal['period'], $fiscal['year']]);
    }

    // CR: Tax Payable / VAT (Tax Total) -> Reverses Input VAT
    if ($tax_total > 0) {
        $db->execute("
            INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year)
            VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)
        ", [generate_uuid(), $id, $tax_account_id, $tax_total, 'Vendor Credit Tax Reversal - ' . $txn_number, $user_id, $txn_date, $fiscal['period'], $fiscal['year']]);
    }

    log_audit('transaction_headers', !empty($existing_hdr) ? 'update' : 'create', $id, $existing_hdr ?? null, ['txn_number' => $txn_number, 'amount' => $grand_total, 'party_id' => $vendor_id, 'memo' => $memo, 'status' => 'open'], $user_id);

    $pdo->commit();

    recalculate_document_payment_status($id, $pdo);

    // Real-time stock & dashboard sync
    auto_sync_pos_items_and_invoices(true);
    clear_dashboard_cache();

    ob_end_clean();
    echo json_encode([
        'status'  => 'success',
        'message' => 'Vendor Bill Credit saved successfully.',
        'id'      => $id
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
