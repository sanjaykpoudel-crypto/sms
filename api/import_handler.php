<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized access.']));
}

require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

// Turn off output buffering for streaming
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$db = db();
$pdo = $db->getConnection();
$userId = $_SESSION['user_id'];

$type = $_POST['type'] ?? '';
$file = $_FILES['file'] ?? null;

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'File upload failed.']);
    exit;
}

$handle = fopen($file['tmp_name'], 'r');
$header = fgetcsv($handle);

if (!$header) {
    echo json_encode(['status' => 'error', 'message' => 'Empty CSV file.']);
    exit;
}

// Map header to indices
$headerMap = array_flip($header);

$totalRows = 0;
while (fgetcsv($handle)) $totalRows++;
rewind($handle);
fgetcsv($handle); // Skip header again

$successCount = 0;
$failedCount = 0;
$currentRow = 0;
$errors = [];

// For transactions, we might need to group rows by txn_number
$txnBuffer = [];
$lastTxnNumber = null;

function sendProgress($current, $total, $success, $failed, $errors = []) {
    echo json_encode([
        'status' => 'progress',
        'current' => $current,
        'total' => $total,
        'percent' => ($current / $total) * 100,
        'success' => $success,
        'failed' => $failed,
        'errors' => $errors
    ]) . "\n";
    if (ob_get_level()) ob_flush();
    flush();
}

while (($row = fgetcsv($handle)) !== FALSE) {
    $currentRow++;
    $data = [];
    foreach ($headerMap as $col => $index) {
        $data[$col] = $row[$index] ?? '';
    }

    try {
        switch ($type) {
            case 'items':
                processItem($data, $db, $pdo, $userId);
                $successCount++;
                break;
            case 'customers':
                processCustomer($data, $db, $pdo, $userId);
                $successCount++;
                break;
            case 'vendors':
                processVendor($data, $db, $pdo, $userId);
                $successCount++;
                break;
            case 'accounts':
                processAccount($data, $db, $pdo, $userId);
                $successCount++;
                break;
            
            case 'vendor_bills':
            case 'customer_invoices':
            case 'journal_entries':
            case 'expenses':
                // For transactions, we group by txn_number
                $txnNum = $data['txn_number'] ?? '';
                if ($lastTxnNumber !== null && $lastTxnNumber !== $txnNum) {
                    // Flush buffer
                    processTransactionBuffer($txnBuffer, $type, $db, $pdo, $userId);
                    $successCount += count($txnBuffer);
                    $txnBuffer = [];
                }
                $txnBuffer[] = $data;
                $lastTxnNumber = $txnNum;
                break;

            default:
                throw new Exception("Unsupported import type: " . $type);
        }
    } catch (Exception $e) {
        $failedCount++;
        $errors[] = ['row' => $currentRow, 'message' => $e->getMessage()];
    }

    // Send progress every 5 rows or if errors exist
    if ($currentRow % 5 == 0 || !empty($errors)) {
        sendProgress($currentRow, $totalRows, $successCount, $failedCount, $errors);
        $errors = [];
    }
}

// Final flush for transactions
if (!empty($txnBuffer)) {
    try {
        processTransactionBuffer($txnBuffer, $type, $db, $pdo, $userId);
        $successCount += count($txnBuffer);
    } catch (Exception $e) {
        $failedCount += count($txnBuffer);
        $errors[] = ['row' => 'Final Batch', 'message' => $e->getMessage()];
    }
}

sendProgress($totalRows, $totalRows, $successCount, $failedCount, $errors);

fclose($handle);

// Helper Functions
function processItem($data, $db, $pdo, $userId) {
    // Basic validation
    if (empty($data['sku']) || empty($data['item_name'])) throw new Exception("SKU and Name are required");

    $existing = $db->fetchOne("SELECT id FROM items WHERE sku = ?", [$data['sku']]);
    
    // Helper to get account ID by code or default
    $getAccount = function($csvKey, $metaField, $defaultId) use ($data, $db) {
        $code = $data[$csvKey] ?? '';
        if (!empty($code)) {
            $acc = $db->fetchOne("SELECT id FROM accounts WHERE account_code = ?", [$code]);
            if ($acc) return $acc['id'];
            throw new Exception("Account code '$code' not found in system.");
        }
        
        $sysDefault = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = ?", [$metaField])['meta_value'] ?? $defaultId;
        // Verify default exists
        $acc = $db->fetchOne("SELECT id FROM accounts WHERE id = ?", [$sysDefault]);
        if ($acc) return $acc['id'];
        
        throw new Exception("Default account for $metaField ($sysDefault) not found. Please check System Settings.");
    };

    $cogsId = $getAccount('cogs_account_code', 'default_cogs_account', 'acc-5100');
    $incomeId = $getAccount('income_account_code', 'default_income_account', 'acc-4100');
    $inventoryId = $getAccount('inventory_account_code', 'default_asset_account', 'acc-1200');

    // Resolve category, unit, tax to reference_code IDs
    $cat_name  = $data['item_category'] ?? 'Other';
    $unit_name = $data['unit_type'] ?? 'Piece';
    $tax_rate  = (float)($data['tax_rate'] ?? 13);

    $cat_rec = $db->fetchOne("SELECT id FROM reference_codes WHERE type = 'category' AND LOWER(name) = LOWER(?)", [$cat_name]);
    if (!$cat_rec) {
        $cat_id = generate_uuid();
        $db->execute("INSERT INTO reference_codes (id, type, name, is_active) VALUES (?, 'category', ?, 1)", [$cat_id, $cat_name]);
    } else {
        $cat_id = $cat_rec['id'];
    }

    $unit_rec = $db->fetchOne("SELECT id FROM reference_codes WHERE type = 'units' AND LOWER(name) = LOWER(?)", [$unit_name]);
    if (!$unit_rec) {
        $unit_id = generate_uuid();
        $db->execute("INSERT INTO reference_codes (id, type, name, is_active) VALUES (?, 'units', ?, 1)", [$unit_id, $unit_name]);
    } else {
        $unit_id = $unit_rec['id'];
    }

    $tax_name = $tax_rate > 0 ? "VAT $tax_rate%" : "Non-Taxable";
    $tax_rec  = $db->fetchOne("SELECT id FROM reference_codes WHERE type = 'tax_code' AND value = ?", [$tax_rate]);
    if (!$tax_rec) {
        $tax_id = generate_uuid();
        $db->execute("INSERT INTO reference_codes (id, type, name, value, is_active) VALUES (?, 'tax_code', ?, ?, 1)", [$tax_id, $tax_name, $tax_rate]);
    } else {
        $tax_id = $tax_rec['id'];
    }

    $status_rec = $db->fetchOne("SELECT id FROM reference_codes WHERE type = 'status' AND name = 'Active'");
    $status_id  = $status_rec ? $status_rec['id'] : null;

    $payload = [
        'action' => $existing ? 'update' : 'save',
        'table'  => 'items',
        'primary_key'   => 'id',
        'primary_value' => $existing ? $existing['id'] : null,
        'data' => [
            'sku'                  => $data['sku'],
            'item_name'            => $data['item_name'],
            'item_category'        => $cat_id,
            'brand'                => $data['brand'] ?? '',
            'bottle_size_ml'       => $data['bottle_size_ml'] ?? 0,
            'unit_type'            => $unit_id,
            'units_per_case'       => $data['units_per_case'] ?? 1,
            'cost_price'           => $data['cost_price'] ?? 0,
            'selling_price'        => $data['selling_price'] ?? 0,
            'tax_rate'             => $tax_rate,
            'tax_id'               => $tax_id,
            'status_id'            => $status_id,
            'reorder_level'        => $data['reorder_level'] ?? 0,
            'reorder_qty'          => $data['reorder_qty'] ?? 0,
            'cogs_account_id'      => $cogsId,
            'income_account_id'    => $incomeId,
            'inventory_account_id' => $inventoryId
        ]
    ];
    
    callTransactionHandler($payload);
}

function processCustomer($data, $db, $pdo, $userId) {
    if (empty($data['full_name'])) throw new Exception("Full Name is required");
    $existing = null;
    if (!empty($data['customer_code'])) {
        $existing = $db->fetchOne("SELECT id FROM customers WHERE customer_code = ?", [$data['customer_code']]);
    }
    
    $ar = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_ar_account'")['meta_value'] ?? 'acc-1100';

    $payload = [
        'action' => $existing ? 'update' : 'save',
        'table' => 'customers',
        'primary_key' => 'id',
        'primary_value' => $existing ? $existing['id'] : null,
        'data' => [
            'customer_code' => $data['customer_code'] ?? '',
            'full_name' => $data['full_name'],
            'customer_type' => $data['customer_type'] ?? 'retail',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '',
            'pan_number' => $data['pan_number'] ?? '',
            'receivable_account_id' => $ar,
            'credit_limit' => $data['credit_limit'] ?? 0,
            'payment_terms_days' => $data['payment_terms_days'] ?? 0
        ]
    ];
    callTransactionHandler($payload);
}

function processVendor($data, $db, $pdo, $userId) {
    if (empty($data['company_name'])) throw new Exception("Company Name is required");
    $existing = null;
    if (!empty($data['vendor_code'])) {
        $existing = $db->fetchOne("SELECT id FROM vendors WHERE vendor_code = ?", [$data['vendor_code']]);
    }
    
    $ap = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_ap_account'")['meta_value'] ?? 'acc-2100';

    $payload = [
        'action' => $existing ? 'update' : 'save',
        'table' => 'vendors',
        'primary_key' => 'id',
        'primary_value' => $existing ? $existing['id'] : null,
        'data' => [
            'vendor_code' => $data['vendor_code'] ?? '',
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '',
            'address' => $data['address'] ?? '',
            'pan_number' => $data['pan_number'] ?? '',
            'vat_number' => $data['vat_number'] ?? '',
            'payable_account_id' => $ap,
            'payment_terms_days' => $data['payment_terms_days'] ?? 0,
            'credit_limit' => $data['credit_limit'] ?? 0
        ]
    ];
    callTransactionHandler($payload);
}

function processAccount($data, $db, $pdo, $userId) {
    if (empty($data['account_code']) || empty($data['account_name'])) throw new Exception("Code and Name are required");
    $existing = $db->fetchOne("SELECT id FROM accounts WHERE account_code = ?", [$data['account_code']]);

    $payload = [
        'action' => $existing ? 'update' : 'save',
        'table' => 'accounts',
        'primary_key' => 'id',
        'primary_value' => $existing ? $existing['id'] : null,
        'data' => [
            'account_code' => $data['account_code'],
            'account_name' => $data['account_name'],
            'account_type' => $data['account_type'] ?? 'expense',
            'account_subtype' => $data['account_subtype'] ?? 'other',
            'normal_balance' => $data['normal_balance'] ?? 'debit',
            'currency' => $data['currency'] ?? 'NPR'
        ]
    ];
    callTransactionHandler($payload);
}

function processTransactionBuffer($rows, $type, $db, $pdo, $userId) {
    if (empty($rows)) return;
    $headerRow = $rows[0];
    
    $txnDate = $headerRow['bill_date'] ?? $headerRow['invoice_date'] ?? $headerRow['txn_date'] ?? $headerRow['expense_date'] ?? date('Y-m-d');
    $status = 'posted';
    $headerId = generate_uuid();
    $subtotal = 0;
    $taxTotal = 0;
    $lines = [];

    if ($type === 'vendor_bills' || $type === 'customer_invoices') {
        // ... (existing logic for bills and invoices)
        $entityId = null;
        if ($type === 'vendor_bills') {
            $vendor = $db->fetchOne("SELECT id FROM vendors WHERE vendor_code = ?", [$headerRow['vendor_code']]);
            if (!$vendor) throw new Exception("Vendor not found: " . $headerRow['vendor_code']);
            $entityId = $vendor['id'];
        } else {
            $customer = $db->fetchOne("SELECT id FROM customers WHERE customer_code = ?", [$headerRow['customer_code']]);
            if (!$customer) throw new Exception("Customer not found: " . $headerRow['customer_code']);
            $entityId = $customer['id'];
        }

        foreach ($rows as $i => $row) {
            $item = $db->fetchOne("SELECT id, inventory_account_id, income_account_id FROM items WHERE sku = ?", [$row['item_sku']]);
            if (!$item) throw new Exception("Item not found: " . $row['item_sku']);
            
            $qty = floatval($row['quantity'] ?? 0);
            $price = floatval($row['unit_price'] ?? 0);
            $discount = floatval($row['discount_pct'] ?? 0);
            $taxRate = floatval($row['tax_rate'] ?? 13);
            
            $amount = ($qty * $price) * (1 - ($discount / 100));
            $tax = $amount * ($taxRate / 100);
            $total = $amount + $tax;

            $subtotal += $amount;
            $taxTotal += $tax;

            $lines[] = [
                'item_id' => $item['id'],
                'account_id' => ($type === 'vendor_bills' ? $item['inventory_account_id'] : $item['income_account_id']),
                'line_number' => $i + 1,
                'description' => $row['description'] ?? '',
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_pct' => $discount,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax,
                'line_total' => $total,
                'cost_price' => ($type === 'vendor_bills' ? $price : 0),
                'gross_profit' => ($type === 'customer_invoices' ? ($total - 0) : 0)
            ];
        }
    } elseif ($type === 'journal_entries') {
        foreach ($rows as $i => $row) {
            $account = $db->fetchOne("SELECT id FROM accounts WHERE account_code = ?", [$row['account_code']]);
            if (!$account) throw new Exception("Account not found: " . $row['account_code']);
            
            $amount = floatval($row['amount'] ?? 0);
            $lines[] = [
                'account_id' => $account['id'],
                'entry_type' => $row['entry_type'] ?? 'debit',
                'amount' => $amount,
                'memo' => $row['entry_memo'] ?? ''
            ];
        }
    } elseif ($type === 'expenses') {
        $row = $headerRow;
        $expAccount = $db->fetchOne("SELECT id FROM accounts WHERE account_code = ?", [$row['expense_account_code']]);
        $paidAccount = $db->fetchOne("SELECT id FROM accounts WHERE account_code = ?", [$row['paid_from_account_code']]);
        if (!$expAccount || !$paidAccount) throw new Exception("Expense or Paid-From account not found");
        
        $vendorId = null;
        if (!empty($row['vendor_code'])) {
            $v = $db->fetchOne("SELECT id FROM vendors WHERE vendor_code = ?", [$row['vendor_code']]);
            $vendorId = $v ? $v['id'] : null;
        }

        $amount = floatval($row['amount'] ?? 0);
        $tax = floatval($row['tax_amount'] ?? 0);
    }

    // Start Transaction
    $pdo->beginTransaction();
    try {
        // Save Transaction Header
        $importRef = !empty($headerRow['vendor_invoice_number']) ? $headerRow['vendor_invoice_number'] : (!empty($headerRow['invoice_number']) ? $headerRow['invoice_number'] : $headerRow['txn_number']);
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, created_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 
                     [$headerId, $headerRow['txn_number'], rtrim($type, 's'), $txnDate, date('Y'), date('m'), date('Y-m'), $status, $importRef, $headerRow['memo'] ?? '', $userId]);

        if ($type === 'vendor_bills' || $type === 'customer_invoices') {
            // Save Lines
            foreach ($lines as $line) {
                $line['id'] = generate_uuid();
                $line['header_id'] = $headerId;
                $keys = array_keys($line);
                $db->execute("INSERT INTO transaction_lines (" . implode(',', $keys) . ") VALUES (" . implode(',', array_fill(0, count($keys), '?')) . ")", array_values($line));
            }

            if ($type === 'vendor_bills') {
                $db->execute("INSERT INTO vendor_bills (id, header_id, vendor_id, bill_date, due_date, vendor_invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 
                             [generate_uuid(), $headerId, $entityId, $txnDate, $headerRow['due_date'] ?? $txnDate, !empty($headerRow['vendor_invoice_number']) ? $headerRow['vendor_invoice_number'] : $headerRow['txn_number'], $subtotal, 0, $taxTotal, ($subtotal + $taxTotal), 0, ($subtotal + $taxTotal), 'unpaid']);
            } else {
                $db->execute("INSERT INTO customer_invoices (id, header_id, customer_id, invoice_date, due_date, invoice_number, subtotal, discount_amount, tax_amount, total_amount, amount_paid, balance_due, payment_status, sale_type) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 
                             [generate_uuid(), $headerId, $entityId, $txnDate, $headerRow['due_date'] ?? $txnDate, !empty($headerRow['invoice_number']) ? $headerRow['invoice_number'] : $headerRow['txn_number'], $subtotal, 0, $taxTotal, ($subtotal + $taxTotal), 0, ($subtotal + $taxTotal), 'unpaid', $headerRow['sale_type'] ?? 'credit']);
            }
        } elseif ($type === 'journal_entries') {
            foreach ($lines as $line) {
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, entry_date, fiscal_period, fiscal_year) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", 
                             [generate_uuid(), $headerId, $line['account_id'], $line['entry_type'], $line['amount'], $line['memo'], $txnDate, date('Y-m'), date('Y')]);
            }
        } elseif ($type === 'expenses') {
            $db->execute("INSERT INTO expenses (id, header_id, expense_account_id, paid_from_account_id, vendor_id, description, amount, tax_amount, expense_category, expense_date) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 
                         [generate_uuid(), $headerId, $expAccount['id'], $paidAccount['id'], $vendorId, $headerRow['description'], $amount, $tax, $headerRow['expense_category'] ?? 'other', $txnDate]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function callTransactionHandler($payload) {
    // We can't easily call the API file via internal request without complexity, 
    // so we'll just include it or replicate the core logic if it's too much.
    // For now, since I have DBConnection, I can just write simple inserts/updates here.
    // But to be consistent with audit logs, let's use a simplified version of transaction_handler.
    
    $db = db();
    $pdo = $db->getConnection();
    
    $action = $payload['action'];
    $table = $payload['table'];
    $data = $payload['data'];
    $pk = $payload['primary_key'];
    $pv = $payload['primary_value'];

    if ($action === 'save') {
        if (empty($data['id'])) $data['id'] = generate_uuid();
        $keys = array_keys($data);
        $cols = implode(',', $keys);
        $vals = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("INSERT INTO $table ($cols) VALUES ($vals)");
        $stmt->execute(array_values($data));
    } else {
        $sets = [];
        foreach ($data as $k => $v) $sets[] = "$k = ?";
        $stmt = $pdo->prepare("UPDATE $table SET " . implode(',', $sets) . " WHERE $pk = ?");
        $vals = array_values($data);
        $vals[] = $pv;
        $stmt->execute($vals);
    }
}
