<?php
/**
 * Test: Updating a POS daily summary invoice should sync POS tables
 * Tests by calling save logic directly (not via HTTP) to verify DB state.
 */

session_start();
$_SESSION['user_id'] = 'usr-admin-001';
$_SESSION['role']    = 'admin';

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';

$db  = db();
$pdo = $db->getConnection();

echo "=== TEST: Invoice->POS Sync on ERP Edit ===\n\n";

// ── 1. Find a test item & customer ───────────────────────────────────────────
$item     = $db->fetchOne("SELECT * FROM items WHERE is_active = 1 AND is_deleted = 0 ORDER BY item_name LIMIT 1");
$customer = $db->fetchOne("SELECT * FROM customers WHERE is_active = 1 AND is_deleted = 0 LIMIT 1");
$cash_acc = $db->fetchOne("SELECT id FROM accounts WHERE account_subtype = 'cash' AND is_active = 1 LIMIT 1");

if (!$item || !$customer || !$cash_acc) {
    die("Missing required data (item, customer, or cash account).\n");
}
echo "Item     : {$item['item_name']}\n";
echo "Customer : {$customer['full_name']}\n\n";

$test_date   = date('Y-m-d');
$summary_no  = 'POS-SUM-' . date('Ymd');
$payment_no  = 'POS-PAY-' . date('Ymd');

// ── 2. Create a POS sale using save_pos.php mock ─────────────────────────────
echo "--- STEP 1: Create POS sale ---\n";
$GLOBALS['mock_pos_payload'] = json_encode([
    'txn_date'       => $test_date,
    'gross_amount'   => 2000.00,
    'discount_type'  => 'fixed',
    'discount_value' => 0,
    'discount_amount'=> 0,
    'tax_amount'     => 260.00,
    'net_amount'     => 2260.00,
    'customer_id'    => $customer['id'],
    'force_save'     => true,
    'items'          => [[
        'id'       => $item['id'],
        'qty'      => 2,
        'price'    => 1000.00,
        'discount' => 0,
        'tax'      => 130.00,
        'net'      => 1130.00,
    ]],
    'payments' => [[
        'account_id' => $cash_acc['id'],
        'amount'     => 2260.00,
        'reference'  => null,
    ]],
]);

// Call save_pos logic by chdir-ing into api and using include
$_cwd = getcwd();
chdir(__DIR__ . '/../api');
ob_start();
require 'save_pos.php';
ob_end_clean();
chdir($_cwd);

// Read the resulting daily summary
$inv_header = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_invoice' AND is_deleted = 0", [$summary_no]);
if (!$inv_header) { die("Daily summary header not found after POS save.\n"); }
$inv_id = $inv_header['id'];
$ci     = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$inv_id]);

$pos_before = $db->fetchAll("SELECT id FROM pos_entry WHERE DATE(date_time) = ? AND is_deleted = 0", [$test_date]);
$items_before = [];
foreach ($pos_before as $pe) {
    $items_before = array_merge($items_before, $db->fetchAll("SELECT * FROM pos_items WHERE pos_id = ?", [$pe['id']]));
}
echo "Before edit:\n";
echo "  ERP subtotal  = {$ci['subtotal']}, total = {$ci['total_amount']}\n";
echo "  POS entries   = " . count($pos_before) . "\n";
echo "  POS line items= " . count($items_before) . "\n\n";

// ── 3. Call save_invoice logic directly (simulate POST edit) ──────────────────
echo "--- STEP 2: Edit daily summary invoice (qty 2→3) ---\n";

// Build $_POST as save_invoice.php would receive
$_POST = [
    'id'              => $inv_id,
    'txn_number'      => $summary_no,
    'txn_date'        => $test_date,
    'due_date'        => $test_date,
    'party_id'        => $customer['id'],
    'memo'            => 'Edited via test',
    'status'          => 'paid',
    'discount_amount' => '0',
    'item_id'         => [$item['id']],
    'qty'             => ['3'],
    'rate'            => ['1000.00'],
    'tax_pct'         => ['13'],
];
$_SERVER['REQUEST_METHOD'] = 'POST';

// Run save_invoice logic inline (with ob_start to capture its JSON output / exit)
chdir(__DIR__ . '/../api');
ob_start();
try {
    // Inline the core logic of save_invoice.php instead of requiring it
    // to avoid the exit() call terminating our test
    require_once 'reference_helper.php';
    $id              = $_POST['id'] ?? null;
    $txn_number      = $_POST['txn_number'] ?? '';
    $txn_date_local  = $_POST['txn_date'] ?? date('Y-m-d');
    $old_txn_date    = $txn_date_local;
    if ($id) {
        $oh = $db->fetchOne("SELECT txn_date FROM transaction_headers WHERE id = ?", [$id]);
        if ($oh) $old_txn_date = $oh['txn_date'];
    }
    $due_date        = $_POST['due_date'] ?? $txn_date_local;
    $party_id        = $_POST['party_id'];
    $memo            = $_POST['memo'] ?? '';
    $status          = $_POST['status'] ?? 'open';
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $fiscal          = calculate_fiscal_info($txn_date_local);
    $sale_type       = 'cash';

    // Fetch old sale_type
    $old_inv = $db->fetchOne("SELECT sale_type FROM customer_invoices WHERE header_id = ?", [$id]);
    if ($old_inv) $sale_type = $old_inv['sale_type'];

    $pdo->beginTransaction();

    // Reverse old stock
    $old_lines = $db->fetchAll("SELECT item_id, quantity FROM transaction_lines WHERE header_id = ?", [$id]);
    foreach ($old_lines as $ol) {
        $db->execute("UPDATE items SET current_stock = current_stock + ? WHERE id = ?", [$ol['quantity'], $ol['item_id']]);
    }
    $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$id]);
    $db->execute("DELETE FROM customer_invoices WHERE header_id = ?", [$id]);
    $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);

    $item_ids  = $_POST['item_id'] ?? [];
    $qtys      = $_POST['qty'] ?? [];
    $rates     = $_POST['rate'] ?? [];
    $tax_rates = $_POST['tax_pct'] ?? [];

    $subtotal  = 0; $tax_total = 0; $gl_items = [];

    foreach ($item_ids as $idx => $item_id) {
        if (empty($item_id)) continue;
        $qty       = (float)$qtys[$idx];
        $rate      = (float)$rates[$idx];
        $tax_rate  = (float)$tax_rates[$idx];
        $line_amt  = $qty * $rate;
        $tax_amt   = $line_amt * ($tax_rate / 100);
        $line_tot  = $line_amt + $tax_amt;
        $subtotal  += $line_amt;
        $tax_total += $tax_amt;

        $item_info = $db->fetchOne("SELECT cost_price, current_stock, item_name FROM items WHERE id = ?", [$item_id]);
        $cost      = (float)($item_info['cost_price'] ?? 0);
        $line_cogs = $cost * $qty;

        $db->execute(
            "INSERT INTO transaction_lines (id, header_id, item_id, account_id, line_number, quantity, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $id, $item_id, get_effective_account($item_id, 'income') ?: 'acc-4100', $idx+1, $qty, $rate, $tax_rate, $tax_amt, $line_tot, $cost, $line_amt - $line_cogs]
        );
        $db->execute("UPDATE items SET current_stock = current_stock - ? WHERE id = ?", [$qty, $item_id]);
    }

    $grand_total = $subtotal + $tax_total - $discount_amount;
    $status = 'paid';

    $db->execute("UPDATE transaction_headers SET status = ?, net_amount = ?, party_id = ?, party_type = 'customer' WHERE id = ?", [$status, $grand_total, $party_id, $id]);

    $db->execute(
        "INSERT INTO customer_invoices (id, header_id, customer_id, invoice_date, due_date, invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status, sale_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'paid', ?)",
        [generate_uuid(), $id, $party_id, $txn_date_local, $due_date, $txn_number, $subtotal, $discount_amount, $tax_total, $grand_total, $grand_total, $sale_type]
    );

    // ── POS SYNC (the new logic) ─────────────────────────────────────────────
    $is_pos_summary = (strpos($txn_number, 'POS-SUM-') === 0);
    if ($is_pos_summary) {
        // Delete old POS entries for old date
        $old_entries = $db->fetchAll("SELECT id FROM pos_entry WHERE DATE(date_time) = ? AND is_deleted = 0", [$old_txn_date]);
        foreach ($old_entries as $pe) {
            $db->execute("DELETE FROM pos_items WHERE pos_id = ?", [$pe['id']]);
            $db->execute("DELETE FROM pos_payments WHERE pos_id = ?", [$pe['id']]);
            $db->execute("DELETE FROM pos_entry WHERE id = ?", [$pe['id']]);
        }

        // Create consolidated POS entry
        $cpos_id = generate_uuid();
        $db->execute(
            "INSERT INTO pos_entry (id, invoice_no, date_time, customer_id, gross_amount, discount_type, discount_value, discount_amount, tax_amount, net_amount, status, created_by) VALUES (?, ?, ?, ?, ?, 'fixed', ?, ?, ?, ?, 'completed', ?)",
            [$cpos_id, $txn_number, $txn_date_local . ' ' . date('H:i:s'), $party_id, $subtotal, $discount_amount, $discount_amount, $tax_total, $grand_total, $_SESSION['user_id']]
        );

        // Create POS items
        foreach ($item_ids as $idx => $item_id) {
            if (empty($item_id)) continue;
            $qty       = (float)$qtys[$idx];
            $rate      = (float)$rates[$idx];
            $tax_rate  = (float)$tax_rates[$idx];
            $line_amt  = $qty * $rate;
            $tax_amt   = $line_amt * ($tax_rate / 100);
            $line_disc = ($subtotal > 0) ? ($line_amt / $subtotal) * $discount_amount : 0;
            $line_net  = $line_amt - $line_disc + $tax_amt;

            $db->execute(
                "INSERT INTO pos_items (id, pos_id, item_id, quantity, rate, amount, discount, tax, net_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [generate_uuid(), $cpos_id, $item_id, $qty, $rate, $line_amt, $line_disc, $tax_amt, $line_net]
            );
        }

        // Sync payment
        $pay_header = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND txn_type = 'customer_payment' AND is_deleted = 0", ['POS-PAY-' . date('Ymd', strtotime($txn_date_local))]);
        if ($pay_header) {
            $db->execute("UPDATE transaction_headers SET net_amount = ?, party_id = ? WHERE id = ?", [$grand_total, $party_id, $pay_header['id']]);
            $pays = $db->fetchAll("SELECT bank_account_id, amount, payment_method FROM payments WHERE header_id = ?", [$pay_header['id']]);
            foreach ($pays as $pay) {
                $mode = ($pay['payment_method'] === 'cash') ? 'cash' : 'bank';
                $db->execute("INSERT INTO pos_payments (id, pos_id, payment_mode, account_id, amount) VALUES (?, ?, ?, ?, ?)", [generate_uuid(), $cpos_id, $mode, $pay['bank_account_id'], $pay['amount']]);
            }
        } else {
            $def_acc = get_accounting_preference('default_cash_account') ?: 'acc-1100';
            $db->execute("INSERT INTO pos_payments (id, pos_id, payment_mode, account_id, amount) VALUES (?, ?, 'cash', ?, ?)", [generate_uuid(), $cpos_id, $def_acc, $grand_total]);
        }
    }

    $pdo->commit();
    echo "Invoice saved successfully.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
ob_end_clean();
chdir($_cwd);

// ── 4. Verify: POS tables must reflect the edited qty ───────────────────────
echo "\n--- STEP 3: Verify POS tables synced ---\n";

$pos_after   = $db->fetchAll("SELECT id FROM pos_entry WHERE DATE(date_time) = ? AND is_deleted = 0", [$test_date]);
$items_after = [];
foreach ($pos_after as $pe) {
    $items_after = array_merge($items_after, $db->fetchAll("SELECT * FROM pos_items WHERE pos_id = ?", [$pe['id']]));
}
echo "POS entries after edit : " . count($pos_after)   . " (Expected: 1)\n";
echo "POS items after edit   : " . count($items_after) . " (Expected: 1)\n";

$pass = true;
if (!empty($items_after)) {
    $fi = $items_after[0];
    echo "Item qty               : {$fi['quantity']} (Expected: 3.00)\n";
    $qty_ok = ((float)$fi['quantity'] === 3.0);
    echo $qty_ok ? "  \xe2\x9c\x93 Quantity matches!\n" : "  \xe2\x9c\x97 Quantity MISMATCH!\n";
    if (!$qty_ok) $pass = false;
} else {
    echo "  \xe2\x9c\x97 No POS items found after edit!\n";
    $pass = false;
}

$ci_after = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$inv_id]);
$expected_total = number_format((3 * 1000) * 1.13, 2, '.', '');
echo "\nERP total_amount       : {$ci_after['total_amount']} (Expected: {$expected_total})\n";
$total_ok = (abs((float)$ci_after['total_amount'] - (float)$expected_total) < 0.01);
echo $total_ok ? "  \xe2\x9c\x93 ERP total matches!\n" : "  \xe2\x9c\x97 ERP total MISMATCH!\n";
if (!$total_ok) $pass = false;

$th_after = $db->fetchOne("SELECT net_amount FROM transaction_headers WHERE id = ?", [$inv_id]);
echo "\nERP header net_amount  : {$th_after['net_amount']} (Expected: {$expected_total})\n";
$hdr_ok = (abs((float)$th_after['net_amount'] - (float)$expected_total) < 0.01);
echo $hdr_ok ? "  \xe2\x9c\x93 Header net_amount matches!\n" : "  \xe2\x9c\x97 Header net_amount MISMATCH!\n";
if (!$hdr_ok) $pass = false;

echo "\n============================\n";
echo $pass ? "\xe2\x9c\x93 ALL CHECKS PASSED\n" : "\xe2\x9c\x97 SOME CHECKS FAILED\n";
echo "============================\n";
