<?php
require_once __DIR__ . '/../../../../database/DBConnection.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['message' => 'Unauthorized']);
    exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file']) || !isset($_POST['type'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Invalid request']);
    exit;
}

$type = $_POST['type'];
$file = $_FILES['file'];
$db = db();

// Validate file
if($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid file uploaded']);
    exit;
}

// Read CSV file
$handle = fopen($file['tmp_name'], 'r');
if(!$handle) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unable to read file']);
    exit;
}

$headers = fgetcsv($handle);
// normalize headers: trim, remove BOM, lowercase
if($headers){
    foreach($headers as &$h){
        $h = trim($h);
        // remove BOM if present
        $h = preg_replace('/\x{FEFF}/u', '', $h);
        $h = strtolower($h);
    }
    unset($h);
}
$imported = 0;
$errors = [];
$import_id = isset($_POST['import_id']) ? $_POST['import_id'] : uniqid();
$progress_file = __DIR__ . "/../../uploads/progress_{$import_id}.json";

function update_progress($file, $current, $total) {
    $progress = ($total > 0) ? round(($current / $total) * 100) : 0;
    file_put_contents($file, json_encode(['progress' => $progress, 'current' => $current, 'total' => $total]));
}

// Count total rows for progress tracking
$total_rows = 0;
$count_handle = fopen($file['tmp_name'], 'r');
while(fgetcsv($count_handle) !== false) {
    $total_rows++;
}
fclose($count_handle);
$total_rows = max(0, $total_rows - 1); // exclude header

update_progress($progress_file, 0, $total_rows);
$current_row = 0;

try {
    if($type === 'items') {
        // Accept new-format headers
        $expected = ['sku','item_name','category','brand','bottle_size_ml','unit_type','units_per_case','cost_price','selling_price','tax_rate','reorder_level','reorder_qty','cogs_account_code','income_account_code','inventory_account_code'];
        if(array_values($headers) !== $expected) {
            throw new Exception('CSV headers do not match. Expected: ' . implode(', ', $expected) . '. Please download the sample template.');
        }

        if(isset($_POST['validate_only']) && $_POST['validate_only'] == 1) {
            echo json_encode(['status' => 'success', 'message' => 'File validated successfully. Starting import...']);
            fclose($handle);
            exit;
        }

        // Helper: resolve account ID by code
        $getAccountId = function($code, $field) use ($db) {
            if (empty($code)) return null;
            // Try by account_code
            $acc = $db->fetchOne("SELECT id FROM accounts WHERE account_code = ? AND is_deleted = 0", [$code]);
            if ($acc) return $acc['id'];
            // Try stripping leading zeros
            $acc = $db->fetchOne("SELECT id FROM accounts WHERE account_code = ? AND is_deleted = 0", [ltrim($code, '0')]);
            if ($acc) return $acc['id'];
            return null;
        };

        // Fetch system defaults for fallback
        $defaultCogs      = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_cogs_account'")['meta_value'] ?? null;
        $defaultIncome    = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_income_account'")['meta_value'] ?? null;
        $defaultInventory = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_asset_account'")['meta_value'] ?? null;

        while(($row = fgetcsv($handle)) !== false) {
            $current_row++;
            update_progress($progress_file, $current_row, $total_rows);

            if(empty(array_filter($row))) continue;
            if(count($row) < count($headers)) {
                $errors[] = "Row {$current_row} skipped: Insufficient columns.";
                continue;
            }

            $data = array_combine($headers, array_slice($row, 0, count($headers)));

            if(empty($data['sku']) || empty($data['item_name'])) {
                $errors[] = "Row {$current_row} skipped: SKU and Item Name are required.";
                continue;
            }

            // Resolve accounting IDs
            $cogsId      = $getAccountId($data['cogs_account_code'], 'COGS');
            $incomeId    = $getAccountId($data['income_account_code'], 'Income');
            $inventoryId = $getAccountId($data['inventory_account_code'], 'Inventory');

            // Fallback to system defaults
            if (!$cogsId)      $cogsId      = $defaultCogs;
            if (!$incomeId)    $incomeId    = $defaultIncome;
            if (!$inventoryId) $inventoryId = $defaultInventory;

            if (!$cogsId || !$incomeId || !$inventoryId) {
                $errors[] = "Row {$current_row} ({$data['sku']}): Could not resolve accounting accounts. Check account codes or configure defaults in System Settings.";
                continue;
            }

            // Resolve category ID from reference_codes or use as-is
            $categoryCode = $data['category'] ?? 'other';
            $catRef = $db->fetchOne("SELECT id FROM reference_codes WHERE (LOWER(code) = LOWER(?) OR LOWER(name) = LOWER(?)) AND type = 'category' AND is_active = 1", [$categoryCode, $categoryCode]);
            if ($catRef) $categoryCode = $catRef['id'];

            // Resolve unit_type ID from reference_codes or use as-is
            $unitCode = $data['unit_type'] ?? 'bottle';
            $unitRef = $db->fetchOne("SELECT id FROM reference_codes WHERE (LOWER(code) = LOWER(?) OR LOWER(name) = LOWER(?)) AND type IN ('unit','units') AND is_active = 1", [$unitCode, $unitCode]);
            if ($unitRef) $unitCode = $unitRef['id'];

            // Check for duplicate SKU
            $existing = $db->fetchOne("SELECT id FROM items WHERE sku = ?", [$data['sku']]);

            $id = $existing ? $existing['id'] : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

            $fields = [
                'sku'                  => $data['sku'],
                'item_name'            => $data['item_name'],
                'item_category'        => $categoryCode,
                'brand'                => $data['brand'] ?? null,
                'bottle_size_ml'       => is_numeric($data['bottle_size_ml']) ? floatval($data['bottle_size_ml']) : null,
                'unit_type'            => $unitCode,
                'units_per_case'       => is_numeric($data['units_per_case']) ? intval($data['units_per_case']) : null,
                'cost_price'           => floatval($data['cost_price'] ?? 0),
                'selling_price'        => floatval($data['selling_price'] ?? 0),
                'tax_rate'             => is_numeric($data['tax_rate']) ? floatval($data['tax_rate']) : 13.00,
                'reorder_level'        => is_numeric($data['reorder_level']) ? intval($data['reorder_level']) : 0,
                'reorder_qty'          => is_numeric($data['reorder_qty']) ? intval($data['reorder_qty']) : 0,
                'cogs_account_id'      => $cogsId,
                'income_account_id'    => $incomeId,
                'inventory_account_id' => $inventoryId,
                'is_active'            => 1,
                'is_deleted'           => 0,
            ];

            try {
                if ($existing) {
                    $sets = [];
                    foreach ($fields as $k => $v) $sets[] = "$k = ?";
                    $vals = array_values($fields);
                    $vals[] = $existing['id'];
                    $db->execute("UPDATE items SET " . implode(',', $sets) . " WHERE id = ?", $vals);
                } else {
                    $fields['id'] = $id;
                    $cols = implode(',', array_keys($fields));
                    $placeholders = implode(',', array_fill(0, count($fields), '?'));
                    $db->execute("INSERT INTO items ($cols) VALUES ($placeholders)", array_values($fields));
                }
                $imported++;
            } catch (Exception $e) {
                $errors[] = "Row {$current_row} ({$data['sku']}): DB Error - " . $e->getMessage();
            }
        }
    }
    else if($type === 'suppliers') {
        $expected = ['vendor_code','company_name','contact_name','phone','email','address','pan_number'];
        if(array_values($headers) !== $expected) {
            throw new Exception('CSV headers do not match. Expected: ' . implode(', ', $expected) . '. Please download the sample template.');
        }

        if(isset($_POST['validate_only']) && $_POST['validate_only'] == 1) {
            echo json_encode(['status' => 'success', 'message' => 'File validated successfully. Starting import...']);
            fclose($handle);
            exit;
        }

        $defaultAp = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_ap_account'")['meta_value'] ?? 'acc-2100';

        while(($row = fgetcsv($handle)) !== false) {
            $current_row++;
            update_progress($progress_file, $current_row, $total_rows);
            if(empty(array_filter($row))) continue;

            $data = array_combine($headers, array_slice($row, 0, count($headers)));

            if(empty($data['company_name'])) {
                $errors[] = "Row {$current_row} skipped: Company Name is required.";
                continue;
            }

            $existing = !empty($data['vendor_code'])
                ? $db->fetchOne("SELECT id FROM vendors WHERE vendor_code = ?", [$data['vendor_code']])
                : null;

            try {
                if ($existing) {
                    $db->execute("UPDATE vendors SET company_name=?, contact_name=?, phone=?, email=?, address=?, pan_number=? WHERE id=?",
                        [$data['company_name'], $data['contact_name'] ?? null, $data['phone'] ?? null, $data['email'] ?? null, $data['address'] ?? null, $data['pan_number'] ?? null, $existing['id']]);
                } else {
                    $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
                    $db->execute("INSERT INTO vendors (id, vendor_code, company_name, contact_name, phone, email, address, pan_number, payable_account_id, is_active) VALUES (?,?,?,?,?,?,?,?,?,1)",
                        [$id, $data['vendor_code'] ?: null, $data['company_name'], $data['contact_name'] ?? null, $data['phone'] ?? null, $data['email'] ?? null, $data['address'] ?? null, $data['pan_number'] ?? null, $defaultAp]);
                }
                $imported++;
            } catch(Exception $e) {
                $errors[] = "Row {$current_row} ({$data['company_name']}): " . $e->getMessage();
            }
        }
    }
    else if($type === 'customers') {
        $expected = ['customer_code','full_name','customer_type','phone','email','pan_number','credit_limit'];
        if(array_values($headers) !== $expected) {
            throw new Exception('CSV headers do not match. Expected: ' . implode(', ', $expected) . '. Please download the sample template.');
        }

        if(isset($_POST['validate_only']) && $_POST['validate_only'] == 1) {
            echo json_encode(['status' => 'success', 'message' => 'File validated successfully. Starting import...']);
            fclose($handle);
            exit;
        }

        $defaultAr = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_ar_account'")['meta_value'] ?? 'acc-1100';
        $validTypes = ['retail', 'wholesale', 'bar', 'hotel'];

        while(($row = fgetcsv($handle)) !== false) {
            $current_row++;
            update_progress($progress_file, $current_row, $total_rows);
            if(empty(array_filter($row))) continue;

            $data = array_combine($headers, array_slice($row, 0, count($headers)));

            if(empty($data['full_name'])) {
                $errors[] = "Row {$current_row} skipped: Full Name is required.";
                continue;
            }

            $customerType = in_array($data['customer_type'] ?? '', $validTypes) ? $data['customer_type'] : 'retail';
            $existing = !empty($data['customer_code'])
                ? $db->fetchOne("SELECT id FROM customers WHERE customer_code = ?", [$data['customer_code']])
                : null;

            try {
                if ($existing) {
                    $db->execute("UPDATE customers SET full_name=?, customer_type=?, phone=?, email=?, pan_number=?, credit_limit=? WHERE id=?",
                        [$data['full_name'], $customerType, $data['phone'] ?? null, $data['email'] ?? null, $data['pan_number'] ?? null, floatval($data['credit_limit'] ?? 0), $existing['id']]);
                } else {
                    $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
                    $db->execute("INSERT INTO customers (id, customer_code, full_name, customer_type, phone, email, pan_number, receivable_account_id, credit_limit, is_active) VALUES (?,?,?,?,?,?,?,?,?,1)",
                        [$id, $data['customer_code'] ?: null, $data['full_name'], $customerType, $data['phone'] ?? null, $data['email'] ?? null, $data['pan_number'] ?? null, $defaultAr, floatval($data['credit_limit'] ?? 0)]);
                }
                $imported++;
            } catch(Exception $e) {
                $errors[] = "Row {$current_row} ({$data['full_name']}): " . $e->getMessage();
            }
        }
    }
    else if($type === 'categories') {
        // Categories are now stored in reference_codes table with type='category'
        $expected = ['name','code','description'];
        if(array_values($headers) !== $expected) {
            throw new Exception('CSV headers do not match. Expected: ' . implode(', ', $expected) . '. Please download the sample template.');
        }

        if(isset($_POST['validate_only']) && $_POST['validate_only'] == 1) {
            echo json_encode(['status' => 'success', 'message' => 'File validated successfully. Starting import...']);
            fclose($handle);
            exit;
        }

        while(($row = fgetcsv($handle)) !== false) {
            $current_row++;
            update_progress($progress_file, $current_row, $total_rows);
            if(empty(array_filter($row))) continue;

            $data = array_combine($headers, array_slice($row, 0, count($headers)));

            if(empty($data['name'])) {
                $errors[] = "Row {$current_row} skipped: Name is required.";
                continue;
            }

            $code = !empty($data['code']) ? strtoupper($data['code']) : strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $data['name']), 0, 4));
            $existing = $db->fetchOne("SELECT id FROM reference_codes WHERE code = ? AND type = 'category'", [$code]);

            try {
                if ($existing) {
                    $db->execute("UPDATE reference_codes SET name=?, description=? WHERE id=?", [$data['name'], $data['description'] ?? null, $existing['id']]);
                } else {
                    $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
                    $db->execute("INSERT INTO reference_codes (id, type, name, code, description, is_active) VALUES (?,?,?,?,?,1)",
                        [$id, 'category', $data['name'], $code, $data['description'] ?? null]);
                }
                $imported++;
            } catch(Exception $e) {
                $errors[] = "Row {$current_row} ({$data['name']}): " . $e->getMessage();
            }
        }
    }
    else if($type === 'accounts') {
        $expected = ['account_code','account_name','account_type','account_subtype','normal_balance'];
        if(array_values($headers) !== $expected) {
            throw new Exception('CSV headers do not match. Expected: ' . implode(', ', $expected) . '. Please download the sample template.');
        }

        if(isset($_POST['validate_only']) && $_POST['validate_only'] == 1) {
            echo json_encode(['status' => 'success', 'message' => 'File validated successfully. Starting import...']);
            fclose($handle);
            exit;
        }

        $validTypes    = ['asset', 'liability', 'equity', 'income', 'expense'];
        $validSubtypes = ['cash', 'bank', 'receivable', 'payable', 'inventory', 'cogs', 'sales', 'tax', 'other'];
        $validBalances = ['debit', 'credit'];

        while(($row = fgetcsv($handle)) !== false) {
            $current_row++;
            update_progress($progress_file, $current_row, $total_rows);
            if(empty(array_filter($row))) continue;

            $data = array_combine($headers, array_slice($row, 0, count($headers)));

            if(empty($data['account_code']) || empty($data['account_name'])) {
                $errors[] = "Row {$current_row} skipped: Account Code and Name are required.";
                continue;
            }

            $acctType    = in_array($data['account_type'] ?? '', $validTypes) ? $data['account_type'] : 'expense';
            $acctSub     = in_array($data['account_subtype'] ?? '', $validSubtypes) ? $data['account_subtype'] : 'other';
            $normalBal   = in_array($data['normal_balance'] ?? '', $validBalances) ? $data['normal_balance'] : 'debit';
            $existing    = $db->fetchOne("SELECT id FROM accounts WHERE account_code = ?", [$data['account_code']]);

            try {
                if ($existing) {
                    $db->execute("UPDATE accounts SET account_name=?, account_type=?, account_subtype=?, normal_balance=? WHERE id=?",
                        [$data['account_name'], $acctType, $acctSub, $normalBal, $existing['id']]);
                } else {
                    $id = 'acc-' . $data['account_code'];
                    $db->execute("INSERT INTO accounts (id, account_code, account_name, account_type, account_subtype, normal_balance, currency, is_active) VALUES (?,?,?,?,?,?,'NPR',1)",
                        [$id, $data['account_code'], $data['account_name'], $acctType, $acctSub, $normalBal]);
                }
                $imported++;
            } catch(Exception $e) {
                $errors[] = "Row {$current_row} ({$data['account_code']}): " . $e->getMessage();
            }
        }
    }
    else if($type === 'transactions') {
        // This type is not supported via this legacy handler.
        // Use the main api/import_handler.php for vendor_bills / customer_invoices.
        throw new Exception('For transaction imports (Vendor Bills, Customer Invoices), please use the dedicated import buttons on their respective list pages.');
    }
    else {
        throw new Exception('Invalid import type');
    }
    
    fclose($handle);
    update_progress($progress_file, $total_rows, $total_rows);
    
    $message = "Successfully imported $imported records";
    if(count($errors) > 0) {
        $message .= " with " . count($errors) . " error(s). Download the error report below.";
    }
    
    echo json_encode(['status' => 'success', 'message' => $message, 'imported' => $imported, 'error_count' => count($errors), 'errors' => $errors]);
    
} catch(Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
?>
