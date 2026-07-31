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
    // 1. Table creation check for credit_memos
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS credit_memos (
            id VARCHAR(36) PRIMARY KEY,
            header_id VARCHAR(36) NOT NULL,
            customer_id VARCHAR(36) NOT NULL,
            memo_number VARCHAR(50) NOT NULL,
            memo_date DATE NOT NULL,
            invoice_id VARCHAR(36) NULL,
            return_to_stock TINYINT(1) DEFAULT 1,
            subtotal DECIMAL(14,2) DEFAULT 0,
            tax_amount DECIMAL(14,2) DEFAULT 0,
            total_amount DECIMAL(14,2) DEFAULT 0,
            remaining_credit DECIMAL(14,2) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'open',
            created_by VARCHAR(36),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY (header_id),
            KEY (customer_id),
            KEY (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $id              = $_POST['id'] ?? null;
    $txn_number      = trim($_POST['txn_number'] ?? '');
    $txn_date        = $_POST['txn_date'] ?? date('Y-m-d');
    $customer_id     = trim($_POST['party_id'] ?? '');
    $invoice_id      = !empty($_POST['invoice_id']) ? trim($_POST['invoice_id']) : null;
    $location_id     = !empty($_POST['location_id']) ? trim($_POST['location_id']) : get_user_default_location_id();
    $memo            = trim($_POST['memo'] ?? '');
    $return_to_stock = isset($_POST['return_to_stock']) ? (int)$_POST['return_to_stock'] : 1;

    $item_ids   = $_POST['item_id']      ?? [];
    $qtys       = $_POST['qty']          ?? [];
    $rates      = $_POST['rate']         ?? [];
    $tax_pcts   = $_POST['tax_pct']      ?? [];
    $units      = $_POST['unit']         ?? [];

    if (empty($customer_id)) {
        throw new Exception("Please select a Customer for the Credit Memo.");
    }
    if (empty($item_ids) || count($item_ids) === 0) {
        throw new Exception("Please add at least one item line.");
    }

    check_fiscal_year_lock($txn_date);
    $fiscal = calculate_fiscal_info($txn_date);

    $pdo->beginTransaction();

    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('credit_memo');
    }

    // Process & validate line items
    $subtotal   = 0;
    $tax_total  = 0;
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

        // Fetch item cost price for inventory return valuation
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
        throw new Exception("Please add valid line items to the Credit Memo.");
    }

    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $grand_total     = max(0, $subtotal + $tax_total - $discount_amount);

    if (!$id) {
        $id = generate_uuid();

        // 1. Transaction Header
        $db->execute("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, net_amount, party_type, party_id, location_id, created_by)
            VALUES (?, ?, 'credit_memo', ?, ?, ?, ?, 'open', ?, ?, ?, 'customer', ?, ?, ?)
        ", [
            $id, $txn_number, $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $txn_number, $memo, $grand_total, $customer_id, $location_id, $user_id
        ]);

        // 2. Credit Memos Table Record
        $cm_id = generate_uuid();
        $db->execute("
            INSERT INTO credit_memos (id, header_id, customer_id, memo_number, memo_date, invoice_id, return_to_stock, subtotal, tax_amount, total_amount, remaining_credit, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)
        ", [
            $cm_id, $id, $customer_id, $txn_number, $txn_date, $invoice_id, $return_to_stock, $subtotal, $tax_total, $grand_total, $grand_total, $user_id
        ]);

        incrementTransactionNumber('credit_memo');
    } else {
        // Reverse previous transaction lines & journal entries for edit
        $db->execute("
            UPDATE transaction_headers 
            SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, memo = ?, net_amount = ?, party_id = ?, location_id = ?
            WHERE id = ?
        ", [$txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $memo, $grand_total, $customer_id, $location_id, $id]);

        $db->execute("
            UPDATE credit_memos 
            SET customer_id = ?, memo_date = ?, invoice_id = ?, return_to_stock = ?, subtotal = ?, tax_amount = ?, total_amount = ?, remaining_credit = ?
            WHERE header_id = ?
        ", [$customer_id, $txn_date, $invoice_id, $return_to_stock, $subtotal, $tax_total, $grand_total, $grand_total, $id]);

        $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    // Default GL accounts
    $ar_account_id      = 'acc-1100'; // Accounts Receivable
    $sales_ret_account  = 'acc-4100'; // Sales Return / Revenue Account
    $tax_account_id     = 'acc-2200'; // Sales Tax / VAT Payable
    $inv_account_id     = 'acc-1200'; // Inventory Asset
    $cogs_account_id    = 'acc-5100'; // Cost of Goods Sold

    $total_cogs_valuation = 0;

    // 3. Insert transaction lines & sync stock if returned
    foreach ($valid_lines as $idx => $line) {
        $item_id   = $line['item_id'];
        $item_acc  = get_effective_account($item_id, 'income') ?: $sales_ret_account;

        $db->execute("
            INSERT INTO transaction_lines (id, header_id, line_number, item_id, account_id, location_id, quantity, unit_price, cost_price, tax_rate, tax_amount, line_total, gross_profit)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ", [
            generate_uuid(), $id, $idx + 1, $item_id, $item_acc, $location_id,
            $line['qty'], $line['rate'], $line['cost_price'], $line['tax_pct'], $line['tax_amt'], $line['line_total']
        ]);

        $total_cogs_valuation += round($line['qty'] * $line['cost_price'], 2);

        // If returned to inventory, update items current_stock & sync balances
        if ($return_to_stock === 1) {
            sync_and_get_item_inventory_balances($db, $item_id);
        }
    }

    // 4. Create General Ledger (Journal Entries) for Credit Memo
    // DR: Sales Return / Sales Revenue (Subtotal)
    if ($subtotal > 0) {
        $db->execute("
            INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year)
            VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)
        ", [generate_uuid(), $id, $sales_ret_account, $subtotal, 'Credit Memo Sales Return - ' . $txn_number, $user_id, $txn_date, $fiscal['period'], $fiscal['year']]);
    }

    // DR: Tax Collected / VAT Payable (Tax Amount)
    if ($tax_total > 0) {
        $db->execute("
            INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year)
            VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)
        ", [generate_uuid(), $id, $tax_account_id, $tax_total, 'Credit Memo Tax Return - ' . $txn_number, $user_id, $txn_date, $fiscal['period'], $fiscal['year']]);
    }

    // CR: Accounts Receivable (Grand Total Credit)
    if ($grand_total > 0) {
        $db->execute("
            INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year)
            VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)
        ", [generate_uuid(), $id, $ar_account_id, $grand_total, 'Credit Memo AR Credit - ' . $txn_number, $user_id, $txn_date, $fiscal['period'], $fiscal['year']]);
    }

    // Inventory GL Impact if stock returned: DR Inventory Asset / CR COGS
    if ($return_to_stock === 1 && $total_cogs_valuation > 0) {
        $db->execute("
            INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year)
            VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)
        ", [generate_uuid(), $id, $inv_account_id, $total_cogs_valuation, 'Credit Memo Restock Inventory DR - ' . $txn_number, $user_id, $txn_date, $fiscal['period'], $fiscal['year']]);

        $db->execute("
            INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year)
            VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)
        ", [generate_uuid(), $id, $cogs_account_id, $total_cogs_valuation, 'Credit Memo COGS Reversal CR - ' . $txn_number, $user_id, $txn_date, $fiscal['period'], $fiscal['year']]);
    }

    $pdo->commit();

    // Trigger instant stock & dashboard cache refresh
    auto_sync_pos_items_and_invoices(true);
    clear_dashboard_cache();

    ob_end_clean();
    echo json_encode([
        'status'  => 'success',
        'message' => 'Credit Memo saved successfully.',
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
