<?php
define('TESTING', true);
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$db = db();
$pdo = $db->getConnection();

echo "=== STARTING POS AGGREGATION VERIFICATION ===\n\n";

// Helper function to mock save_pos API call
function mock_save_pos($payload) {
    $temp_payload_file = __DIR__ . '/pos_payload.json';
    file_put_contents($temp_payload_file, json_encode($payload));
    
    $runner_code = "<?php
session_start();
\$_SESSION['user_id'] = 'usr-admin-001';
\$_SESSION['role'] = 'admin';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$payload_file = \$argv[1] ?? '';
if (\$payload_file && file_exists(\$payload_file)) {
    \$GLOBALS['mock_pos_payload'] = file_get_contents(\$payload_file);
}
chdir(__DIR__ . '/../api');
include 'save_pos.php';
";
    
    $temp_runner_file = __DIR__ . '/run_save_pos.php';
    file_put_contents($temp_runner_file, $runner_code);
    
    $cmd = "c:\\xampp\\php\\php.exe -d html_errors=off -d display_errors=stderr " . escapeshellarg($temp_runner_file) . " " . escapeshellarg($temp_payload_file);
    $output = shell_exec($cmd);
    
    if (file_exists($temp_payload_file)) unlink($temp_payload_file);
    if (file_exists($temp_runner_file)) unlink($temp_runner_file);
    
    return json_decode($output, true);
}

// Find a test item
$item = $db->fetchOne("SELECT id, item_name, current_stock, cost_price FROM items LIMIT 1");
if (!$item) {
    echo "No items in database!\n";
    exit(1);
}
$item_id = $item['id'];
$initial_stock = (float)$item['current_stock'];
$cost_price = (float)$item['cost_price'];
echo "Using test item: '{$item['item_name']}' (ID: {$item_id}), Initial Stock: {$initial_stock}, Cost Price: {$cost_price}\n\n";

$customer = $db->fetchOne("SELECT id FROM customers WHERE is_active = 1 AND is_deleted = 0 LIMIT 1");
$customer_id = $customer['id'];

$cash_account = 'acc-1010';

$today_date = date('Y-m-d');
$today_str = date('Ymd', strtotime($today_date));
$summary_invoice_no = "POS-SUM-" . $today_str;
$summary_payment_no = "POS-PAY-" . $today_str;

// Cleanup any existing daily summary for today first
$existing_pay = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ?", [$summary_payment_no]);
if ($existing_pay) {
    $pay_id = $existing_pay['id'];
    $db->execute("DELETE FROM payments WHERE header_id = ?", [$pay_id]);
    $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$pay_id]);
    $db->execute("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?", [$pay_id, $pay_id]);
    $db->execute("DELETE FROM transaction_headers WHERE id = ?", [$pay_id]);
}
$existing_inv = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ?", [$summary_invoice_no]);
if ($existing_inv) {
    $inv_id = $existing_inv['id'];
    $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$inv_id]);
    $db->execute("DELETE FROM customer_invoices WHERE header_id = ?", [$inv_id]);
    $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$inv_id]);
    $db->execute("DELETE FROM transaction_headers WHERE id = ?", [$inv_id]);
}
// Soft-delete existing pos_entry for today to start fresh
$db->execute("UPDATE pos_entry SET is_deleted = 1 WHERE DATE(date_time) = ?", [$today_date]);

// --- TEST 1: Save First POS Sale ---
echo "--- TEST 1: Save First POS Sale ---\n";
$payload1 = [
    'force_save' => true,
    'gross_amount' => 1000.00,
    'discount_type' => 'fixed',
    'discount_value' => 100.00,
    'discount_amount' => 100.00,
    'tax_amount' => 117.00,
    'net_amount' => 1017.00,
    'customer_id' => $customer_id,
    'items' => [
        [
            'id' => $item_id,
            'qty' => 2.0,
            'price' => 500.00,
            'discount' => 100.00,
            'tax' => 117.00,
            'net' => 1017.00
        ]
    ],
    'payments' => [
        [
            'account_id' => $cash_account,
            'amount' => 1017.00,
            'reference' => 'CASH-REF-1'
        ]
    ]
];

$res1 = mock_save_pos($payload1);
if (isset($res1['status']) && $res1['status'] === 'success') {
    echo "First POS Sale Saved Successfully!\n";
    $pos_id_1 = $res1['pos_id'];
    echo "POS ID: {$pos_id_1}, Txn Number: {$res1['txn_number']}\n";
    
    // Check stock
    $stock_after_1 = (float)$db->fetchOne("SELECT current_stock FROM items WHERE id = ?", [$item_id])['current_stock'];
    echo "Stock after POS 1: {$stock_after_1} (Expected: " . ($initial_stock - 2) . ")\n";
    
    // Check daily summary invoice
    $inv_h = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number = ?", [$summary_invoice_no]);
    if ($inv_h) {
        echo "Daily Summary Invoice Created. ID: {$inv_h['id']}\n";
        $cust_inv = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$inv_h['id']]);
        echo "Summary Invoice Totals -> Subtotal: {$cust_inv['subtotal']}, Discount: {$cust_inv['discount_amount']}, Tax: {$cust_inv['tax_amount']}, Total: {$cust_inv['total_amount']}\n";
    } else {
        echo "Error: Daily summary invoice not created!\n";
    }
} else {
    echo "Test 1 Failed: " . ($res1['message'] ?? 'Unknown error') . "\n";
    print_r($res1);
}
echo "\n";

// --- TEST 2: Save Second POS Sale ---
echo "--- TEST 2: Save Second POS Sale ---\n";
$payload2 = [
    'force_save' => true,
    'gross_amount' => 500.00,
    'discount_type' => 'fixed',
    'discount_value' => 0.00,
    'discount_amount' => 0.00,
    'tax_amount' => 65.00,
    'net_amount' => 565.00,
    'customer_id' => $customer_id,
    'items' => [
        [
            'id' => $item_id,
            'qty' => 1.0,
            'price' => 500.00,
            'discount' => 0.00,
            'tax' => 65.00,
            'net' => 565.00
        ]
    ],
    'payments' => [
        [
            'account_id' => $cash_account,
            'amount' => 565.00,
            'reference' => 'CASH-REF-2'
        ]
    ]
];

$res2 = mock_save_pos($payload2);
if (isset($res2['status']) && $res2['status'] === 'success') {
    echo "Second POS Sale Saved Successfully!\n";
    $pos_id_2 = $res2['pos_id'];
    
    // Check stock
    $stock_after_2 = (float)$db->fetchOne("SELECT current_stock FROM items WHERE id = ?", [$item_id])['current_stock'];
    echo "Stock after POS 2: {$stock_after_2} (Expected: " . ($initial_stock - 3) . ")\n";
    
    // Check daily summary invoice
    $inv_h = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number = ?", [$summary_invoice_no]);
    $cust_inv = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$inv_h['id']]);
    echo "Daily Summary Invoice Totals -> Subtotal: {$cust_inv['subtotal']} (Expected: 1500.00), Discount: {$cust_inv['discount_amount']} (Expected: 100.00), Tax: {$cust_inv['tax_amount']} (Expected: 182.00), Total: {$cust_inv['total_amount']} (Expected: 1582.00)\n";
} else {
    echo "Test 2 Failed: " . ($res2['message'] ?? 'Unknown error') . "\n";
}
echo "\n";

// --- TEST 3: Update Second POS Sale ---
echo "--- TEST 3: Update Second POS Sale ---\n";
$payload3 = [
    'id' => $pos_id_2,
    'force_save' => true,
    'gross_amount' => 1000.00,
    'discount_type' => 'fixed',
    'discount_value' => 50.00,
    'discount_amount' => 50.00,
    'tax_amount' => 123.50,
    'net_amount' => 1073.50,
    'customer_id' => $customer_id,
    'items' => [
        [
            'id' => $item_id,
            'qty' => 2.0,
            'price' => 500.00,
            'discount' => 50.00,
            'tax' => 123.50,
            'net' => 1073.50
        ]
    ],
    'payments' => [
        [
            'account_id' => $cash_account,
            'amount' => 1073.50,
            'reference' => 'CASH-REF-2-UPD'
        ]
    ]
];

$res3 = mock_save_pos($payload3);
if (isset($res3['status']) && $res3['status'] === 'success') {
    echo "Second POS Sale Updated Successfully!\n";
    
    // Check stock
    $stock_after_3 = (float)$db->fetchOne("SELECT current_stock FROM items WHERE id = ?", [$item_id])['current_stock'];
    echo "Stock after POS Update: {$stock_after_3} (Expected: " . ($initial_stock - 4) . ")\n";
    
    // Check daily summary invoice
    $inv_h = $db->fetchOne("SELECT * FROM transaction_headers WHERE txn_number = ?", [$summary_invoice_no]);
    $cust_inv = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$inv_h['id']]);
    echo "Daily Summary Invoice Totals -> Subtotal: {$cust_inv['subtotal']} (Expected: 2000.00), Discount: {$cust_inv['discount_amount']} (Expected: 150.00), Tax: {$cust_inv['tax_amount']} (Expected: 240.50), Total: {$cust_inv['total_amount']} (Expected: 2090.50)\n";
} else {
    echo "Test 3 Failed: " . ($res3['message'] ?? 'Unknown error') . "\n";
}
echo "\n";

// --- TEST 4: Delete Daily Summary and Regenerate ---
echo "--- TEST 4: Delete Daily Summary and Regenerate ---\n";
// Simulated deletion of the daily summary invoice header
$inv_h = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ?", [$summary_invoice_no]);
$inv_id = $inv_h['id'];
$db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$inv_id]);
$db->execute("DELETE FROM customer_invoices WHERE header_id = ?", [$inv_id]);
$db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$inv_id]);
$db->execute("DELETE FROM payments WHERE applied_to_txn_id = ?", [$inv_id]);
$db->execute("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?", [$inv_id, $inv_id]);
$db->execute("DELETE FROM transaction_headers WHERE id = ?", [$inv_id]);
echo "Deleted Daily Summary Invoice Header {$inv_id}.\n";

// Save a third POS sale and verify a brand new daily summary invoice is created!
$payload4 = [
    'force_save' => true,
    'gross_amount' => 500.00,
    'discount_type' => 'fixed',
    'discount_value' => 0.00,
    'discount_amount' => 0.00,
    'tax_amount' => 65.00,
    'net_amount' => 565.00,
    'customer_id' => $customer_id,
    'items' => [
        [
            'id' => $item_id,
            'qty' => 1.0,
            'price' => 500.00,
            'discount' => 0.00,
            'tax' => 65.00,
            'net' => 565.00
        ]
    ],
    'payments' => [
        [
            'account_id' => $cash_account,
            'amount' => 565.00,
            'reference' => 'CASH-REF-3'
        ]
    ]
];

$res4 = mock_save_pos($payload4);
if (isset($res4['status']) && $res4['status'] === 'success') {
    echo "Third POS Sale Saved Successfully!\n";
    $pos_id_3 = $res4['pos_id'];
    
    // Check if new daily summary was created
    $new_inv_h = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND is_deleted = 0", [$summary_invoice_no]);
    if ($new_inv_h) {
        $new_inv_id = $new_inv_h['id'];
        echo "New Daily Summary Invoice Header Created successfully! New ID: {$new_inv_id}\n";
        
        $cust_inv = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$new_inv_id]);
        echo "Daily Summary Invoice Totals -> Subtotal: {$cust_inv['subtotal']} (Expected: 2500.00), Discount: {$cust_inv['discount_amount']} (Expected: 150.00), Tax: {$cust_inv['tax_amount']} (Expected: 305.50), Total: {$cust_inv['total_amount']} (Expected: 2655.50)\n";
    } else {
        echo "Error: Failed to recreate daily summary invoice!\n";
    }
} else {
    echo "Test 4 Failed: " . ($res4['message'] ?? 'Unknown error') . "\n";
}
echo "\n";

// --- TEST 5: Void POS Entry ---
echo "--- TEST 5: Void POS Entry ---\n";
// Use transaction_handler.php API mock to void the first POS sale (pos_id_1)
$void_payload = [
    'action' => 'delete',
    'table' => 'pos_entry',
    'primary_value' => $pos_id_1
];

$temp_void_file = __DIR__ . '/void_payload.json';
file_put_contents($temp_void_file, json_encode($void_payload));

$void_runner_code = "<?php
session_start();
\$_SESSION['user_id'] = 'usr-admin-001';
\$_SESSION['role'] = 'admin';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$input_file = \$argv[1];
\$GLOBALS['mock_input_payload'] = file_get_contents(\$input_file);
chdir(__DIR__ . '/../api');
// Mock input retrieval in transaction_handler.php
\$inputJSON = \$GLOBALS['mock_input_payload'];
\$input = json_decode(\$inputJSON, true);
include 'transaction_handler.php';
";

$temp_void_runner = __DIR__ . '/run_void.php';
file_put_contents($temp_void_runner, $void_runner_code);

$cmd = "c:\\xampp\\php\\php.exe -d html_errors=off -d display_errors=stderr " . escapeshellarg($temp_void_runner) . " " . escapeshellarg($temp_void_file);
$void_output = shell_exec($cmd);

if (file_exists($temp_void_file)) unlink($temp_void_file);
if (file_exists($temp_void_runner)) unlink($temp_void_runner);

$void_res = json_decode($void_output, true);
if (isset($void_res['status']) && $void_res['status'] === 'success') {
    echo "First POS Sale Voided Successfully!\n";
    
    // Check stock restored
    $stock_after_void = (float)$db->fetchOne("SELECT current_stock FROM items WHERE id = ?", [$item_id])['current_stock'];
    echo "Stock after Void: {$stock_after_void} (Expected: " . ($initial_stock - 3) . ")\n";
    
    // Check daily summary updated (first POS sale details removed)
    $inv_h = $db->fetchOne("SELECT id FROM transaction_headers WHERE txn_number = ? AND is_deleted = 0", [$summary_invoice_no]);
    $new_inv_id = $inv_h['id'];
    $cust_inv = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$new_inv_id]);
    echo "Daily Summary Invoice Totals after Void -> Subtotal: {$cust_inv['subtotal']} (Expected: 1500.00), Discount: {$cust_inv['discount_amount']} (Expected: 50.00), Tax: {$cust_inv['tax_amount']} (Expected: 188.50), Total: {$cust_inv['total_amount']} (Expected: 1638.50)\n";
} else {
    echo "Void Failed: " . ($void_res['message'] ?? 'Unknown error') . "\n";
    echo $void_output . "\n";
}
echo "\n";

// Clean up
$db->execute("UPDATE items SET current_stock = ? WHERE id = ?", [$initial_stock, $item_id]);
echo "Stock reset to {$initial_stock}.\n";
echo "=== VERIFICATION COMPLETED ===\n";
