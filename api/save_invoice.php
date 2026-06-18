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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$db = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $id = $_POST['id'] ?? null;
    $txn_number = $_POST['txn_number'] ?? '';
    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('customer_invoice');
    }
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? $txn_date;
    $party_id = $_POST['party_id'] ?? null;
    $memo = $_POST['memo'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    
    if (!$party_id) throw new Exception("Customer is required");

    $fiscal = calculate_fiscal_info($txn_date);

    $sale_type = 'credit';

    if (!$id) {
        $id = generate_uuid();
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $id, $txn_number, 'customer_invoice', $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $status, $memo, $_SESSION['user_id']
        ]);
        incrementTransactionNumber('customer_invoice');
    } else {
        // Fetch old sale_type before deleting
        $old_invoice = $db->fetchOne("SELECT sale_type FROM customer_invoices WHERE header_id = ?", [$id]);
        if ($old_invoice) {
            $sale_type = $old_invoice['sale_type'];
        }

        $db->execute("UPDATE transaction_headers SET txn_date = ?, memo = ?, status = ? WHERE id = ?", [$txn_date, $memo, $status, $id]);
        
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

    $item_ids = $_POST['item_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $tax_rates = $_POST['tax_pct'] ?? [];
    
    $subtotal = 0;
    $tax_total = 0;
    $total_cogs = 0;
    $gl_items = [];

    foreach ($item_ids as $idx => $item_id) {
        if (empty($item_id)) continue;
        $qty = (float)$qtys[$idx];
        $rate = (float)$rates[$idx];
        $tax_rate = (float)$tax_rates[$idx];
        
        $line_amount = $qty * $rate;
        $tax_amount = $line_amount * ($tax_rate / 100);
        $line_total = $line_amount + $tax_amount;

        $subtotal += $line_amount;
        $tax_total += $tax_amount;

        // Fetch current cost price and stock for validation
        $item_info = $db->fetchOne("SELECT cost_price, current_stock, item_name FROM items WHERE id = ?", [$item_id]);
        
        // Stock Validation
        if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
            $available = (float)($item_info['current_stock'] ?? 0);
            if ($available < $qty && !isset($_POST['force_save'])) {
                // Rollback before early exit so partial edits are not persisted
                if ($pdo->inTransaction()) $pdo->rollBack();
                ob_end_clean();
                $msg = "Item: " . $item_info['item_name'] . ". Available: " . number_format($available, 4) . ". Do you want to save anyway?";
                echo json_encode(['status' => 'stock_warning', 'message' => $msg]);
                exit;
            }
        }

        $cost_price = (float)($item_info['cost_price'] ?? 0);
        $line_cogs = $cost_price * $qty;
        $total_cogs += $line_cogs;
        $gross_profit = $line_amount - $line_cogs;

        $line_account_id = !empty($_POST['account_id'][$idx] ?? null) ? $_POST['account_id'][$idx] : get_effective_account($item_id, 'income');

        $db->execute("INSERT INTO transaction_lines (id, header_id, item_id, account_id, line_number, quantity, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $item_id, $line_account_id, $idx + 1, $qty, $rate, $tax_rate, $tax_amount, $line_total, $cost_price, $gross_profit
        ]);
        
        // Deduct new stock
        if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
            $db->execute("UPDATE items SET current_stock = current_stock - ? WHERE id = ?", [$qty, $item_id]);
        }

        $gl_items[] = [
            'item_id' => $item_id,
            'sales_acc' => get_effective_account($item_id, 'income') ?: 'acc-4100',
            'sales_amount' => $line_amount,
            'cogs_acc' => get_effective_account($item_id, 'cogs') ?: 'acc-5100',
            'cogs_amount' => $line_cogs,
            'inv_acc' => get_effective_account($item_id, 'inventory') ?: 'acc-1200'
        ];
    }

    $grand_total = $subtotal + $tax_total - $discount_amount;

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

    // If payment status is paid/partial, update the transaction header status as well
    if ($payment_status !== 'unpaid' && in_array($status, ['posted', 'paid', 'partial', 'open'])) {
        $status = $payment_status;
        $db->execute("UPDATE transaction_headers SET status = ? WHERE id = ?", [$status, $id]);
    }

    $db->execute("INSERT INTO customer_invoices (id, header_id, customer_id, invoice_date, due_date, invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status, sale_type) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        generate_uuid(), $id, $party_id, $txn_date, $due_date, $txn_number, $subtotal, $discount_amount, $tax_total, $grand_total, $amount_paid, $balance_due, $payment_status, $sale_type
    ]);

    // GL Impact
    if (in_array($status, ['posted', 'paid', 'partial', 'open'])) {
        $ar_account = get_effective_account($party_id, 'receivable');
        $tax_account = get_accounting_preference('default_tax_account') ?: 'acc-2200';
        $discount_account = get_accounting_preference('default_discount_account') ?: 'acc-6160';
        $cogs_account = get_accounting_preference('default_cogs_account') ?: 'acc-5100';
        $inventory_account = get_accounting_preference('default_asset_account') ?: 'acc-1200';

        // Dr Accounts Receivable
        if ($grand_total > 0) {
            $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                generate_uuid(), $id, $ar_account, $grand_total, 'Invoice ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
        // Dr Discount (if any)
        if ($discount_amount > 0) {
            $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                generate_uuid(), $id, $discount_account, $discount_amount, 'Discount ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
        // Cr Sales Revenue (per item)
        foreach ($gl_items as $gi) {
            if ($gi['sales_amount'] > 0) {
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $gi['sales_acc'], $gi['item_id'], $gi['sales_amount'], 'Invoice ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
            }
        }
        // Cr Tax Payable
        if ($tax_total > 0) {
            $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                generate_uuid(), $id, $tax_account, $tax_total, 'VAT ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
            ]);
        }
        // COGS and Inventory (per item)
        foreach ($gl_items as $gi) {
            if ($gi['cogs_amount'] > 0) {
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $gi['cogs_acc'], $gi['item_id'], $gi['cogs_amount'], 'COGS ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $gi['inv_acc'], $gi['item_id'], $gi['cogs_amount'], 'Inventory Out ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
            }
        }
    }

    $pdo->commit();
    ob_end_clean();
    echo json_encode(['status' => 'success', 'message' => 'Invoice has been saved successfully.', 'id' => $id]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    file_put_contents(__DIR__ . '/../scratch/api_error.log', date('Y-m-d H:i:s') . ' - save_invoice.php: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}




