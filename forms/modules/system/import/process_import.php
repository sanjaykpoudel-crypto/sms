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

if (!function_exists('normalizeCsvEncoding')) {
    function normalizeCsvEncoding($filePath) {
        $content = @file_get_contents($filePath);
        if ($content === false) return;

        $encoding = null;
        if (substr($content, 0, 2) === "\xFF\FE") {
            $encoding = 'UTF-16LE';
            $content = substr($content, 2);
        } elseif (substr($content, 0, 2) === "\xFE\xFF") {
            $encoding = 'UTF-16BE';
            $content = substr($content, 2);
        } elseif (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
            $encoding = 'UTF-8';
        } else {
            if (strpos($content, "\x00") !== false) {
                $encoding = 'UTF-16LE';
            }
        }

        if ($encoding && $encoding !== 'UTF-8') {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if ($converted) $content = $converted;
        }

        $content = str_replace(["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\FE", "\x00"], '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        @file_put_contents($filePath, $content);
    }
}

normalizeCsvEncoding($file['tmp_name']);

// Read CSV file
$handle = fopen($file['tmp_name'], 'r');
if(!$handle) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unable to read file']);
    exit;
}

$headers = fgetcsv($handle);
// normalize headers: trim, remove BOM safely, lowercase
if($headers){
    foreach($headers as &$h){
        $h = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', strtolower(trim($h))));
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
        if(isset($_POST['validate_only']) && $_POST['validate_only'] == 1) {
            echo json_encode(['status' => 'success', 'message' => 'File validated successfully. Starting import...']);
            fclose($handle);
            exit;
        }

        // Helper: resolve account ID by code
        $getAccountId = function($code, $field) use ($db) {
            if (empty($code)) return null;
            // Try by id or account_code
            $acc = $db->fetchOne("SELECT id FROM accounts WHERE (id = ? OR REPLACE(id, 'acc-', '') = ?) AND is_deleted = 0", [$code, $code]);
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

            $rawRow = array_combine($headers, array_slice($row, 0, count($headers)));
            $data = [];
            foreach ($rawRow as $k => $v) {
                $cleanKey = preg_replace('/[\x00-\x1F\x7F-\xFF\xEF\xBB\xBF\xFE\xFF]/', '', $k);
                $cleanKey = strtolower(trim(str_replace([' ', '-'], '_', $cleanKey !== '' ? $cleanKey : $k)));

                if (in_array($cleanKey, ['id', '_id'])) $cleanKey = 'id';
                elseif (in_array($cleanKey, ['sku'])) $cleanKey = 'sku';
                elseif (in_array($cleanKey, ['item_name', 'tem_name', 'item_nam', 'tem_nam', 'name'])) $cleanKey = 'item_name';
                elseif (in_array($cleanKey, ['item_category', 'em_cate', 'category', 'cate'])) $cleanKey = 'item_category';
                elseif (in_array($cleanKey, ['brand', 'brand_name'])) $cleanKey = 'brand';
                elseif (in_array($cleanKey, ['bottle_size_ml', 'ttle_siz', 'bottle_size'])) $cleanKey = 'bottle_size_ml';
                elseif (in_array($cleanKey, ['unit_type', 'unit_ty', 'unit'])) $cleanKey = 'unit_type';
                elseif (in_array($cleanKey, ['units_per_case', 'its_per', 'units_per'])) $cleanKey = 'units_per_case';
                elseif (in_array($cleanKey, ['location_name', 'cation_r', 'location', 'loc_name'])) $cleanKey = 'location_name';
                elseif (in_array($cleanKey, ['location_stock', 'cation_s', 'stock'])) $cleanKey = 'location_stock';
                elseif (in_array($cleanKey, ['location_cost_price', 'tion_cos', 'loc_cost'])) $cleanKey = 'location_cost_price';
                elseif (in_array($cleanKey, ['location_selling_price', 'on_selli', 'loc_sell'])) $cleanKey = 'location_selling_price';
                elseif (in_array($cleanKey, ['location_mrp', 'location_r', 'loc_mrp'])) $cleanKey = 'location_mrp';
                elseif (in_array($cleanKey, ['global_cost_price', 'cost_price', 'bal_cost'])) $cleanKey = 'cost_price';
                elseif (in_array($cleanKey, ['global_selling_price', 'selling_price', 'al_sellin'])) $cleanKey = 'selling_price';
                elseif (in_array($cleanKey, ['global_mrp', 'mrp', 'global_r', 'global_m'])) $cleanKey = 'mrp';
                elseif (in_array($cleanKey, ['tax_rate', 'tax', 'tax_r'])) $cleanKey = 'tax_rate';

                $data[$cleanKey] = is_string($v) ? trim($v) : $v;
            }

            $itemId  = !empty($data['id']) ? strtolower(trim($data['id'])) : null;
            $itemSku = !empty($data['sku']) ? strtolower(trim($data['sku'])) : null;

            $existing = null;
            if ($itemId) {
                $existing = $db->fetchOne("SELECT id FROM items WHERE LOWER(id) = LOWER(?)", [$itemId]);
            }
            if (!$existing && $itemSku) {
                $existing = $db->fetchOne("SELECT id FROM items WHERE LOWER(sku) = LOWER(?)", [$itemSku]);
            }

            if (!$existing && !$itemId && empty($itemSku) && empty($data['item_name'])) {
                $errors[] = "Row {$current_row} skipped: Item ID or SKU and Item Name are required to create a new item.";
                continue;
            }

            // Resolve accounting IDs
            $cogsId      = $getAccountId($data['cogs_account_code'] ?? '', 'COGS');
            $incomeId    = $getAccountId($data['income_account_code'] ?? '', 'Income');
            $inventoryId = $getAccountId($data['inventory_account_code'] ?? '', 'Inventory');

            // Fallback to system defaults if creating a new item
            if (!$cogsId)      $cogsId      = $defaultCogs;
            if (!$incomeId)    $incomeId    = $defaultIncome;
            if (!$inventoryId) $inventoryId = $defaultInventory;

            // Resolve category ID from reference_codes or use as-is
            $categoryCode = $data['item_category'] ?? ($data['category'] ?? null);
            if ($categoryCode) {
                $catRef = $db->fetchOne("SELECT id FROM reference_codes WHERE (LOWER(code) = LOWER(?) OR LOWER(name) = LOWER(?)) AND type = 'category' AND is_active = 1", [$categoryCode, $categoryCode]);
                if ($catRef) $categoryCode = $catRef['id'];
            }

            // Resolve unit_type ID from reference_codes or use as-is
            $unitCode = $data['unit_type'] ?? null;
            if ($unitCode) {
                $unitRef = $db->fetchOne("SELECT id FROM reference_codes WHERE (LOWER(code) = LOWER(?) OR LOWER(name) = LOWER(?)) AND type IN ('unit','units') AND is_active = 1", [$unitCode, $unitCode]);
                if ($unitRef) $unitCode = $unitRef['id'];
            }

            $id = $existing ? $existing['id'] : ($itemId ?: sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)));

            try {
                if ($existing) {
                    $fields = [];
                    if (!empty($data['sku']))                     $fields['sku']                  = trim($data['sku']);
                    if (!empty($data['item_name']))               $fields['item_name']            = trim($data['item_name']);
                    if (!empty($categoryCode))                    $fields['item_category']        = $categoryCode;
                    if (isset($data['brand']))                    $fields['brand']                = $data['brand'];
                    if (isset($data['bottle_size_ml']) && is_numeric($data['bottle_size_ml'])) $fields['bottle_size_ml'] = floatval($data['bottle_size_ml']);
                    if (!empty($unitCode))                        $fields['unit_type']            = $unitCode;
                    if (isset($data['units_per_case']) && is_numeric($data['units_per_case'])) $fields['units_per_case'] = intval($data['units_per_case']);
                    if (isset($data['cost_price']) && is_numeric($data['cost_price']))     $fields['cost_price']     = floatval($data['cost_price']);
                    if (isset($data['selling_price']) && is_numeric($data['selling_price'])) $fields['selling_price']  = floatval($data['selling_price']);
                    if (isset($data['mrp']) && is_numeric($data['mrp']))                   $fields['mrp']            = floatval($data['mrp']);
                    if (isset($data['tax_rate']) && is_numeric($data['tax_rate']))         $fields['tax_rate']       = floatval($data['tax_rate']);
                    if (isset($data['reorder_level']) && is_numeric($data['reorder_level'])) $fields['reorder_level'] = intval($data['reorder_level']);
                    if (isset($data['reorder_qty']) && is_numeric($data['reorder_qty']))   $fields['reorder_qty']   = intval($data['reorder_qty']);
                    if (!empty($cogsId))                          $fields['cogs_account_id']      = $cogsId;
                    if (!empty($incomeId))                        $fields['income_account_id']    = $incomeId;
                    if (!empty($inventoryId))                     $fields['inventory_account_id'] = $inventoryId;

                    if (!empty($fields)) {
                        $sets = [];
                        foreach ($fields as $k => $v) $sets[] = "$k = ?";
                        $vals = array_values($fields);
                        $vals[] = $existing['id'];
                        $db->execute("UPDATE items SET " . implode(',', $sets) . " WHERE id = ?", $vals);
                    }
                } else {
                    $fields = [
                        'id'                   => $id,
                        'sku'                  => $itemSku ?: '',
                        'item_name'            => $data['item_name'] ?? '',
                        'item_category'        => $categoryCode ?: 'other',
                        'brand'                => $data['brand'] ?? null,
                        'bottle_size_ml'       => is_numeric($data['bottle_size_ml'] ?? null) ? floatval($data['bottle_size_ml']) : null,
                        'unit_type'            => $unitCode ?: 'bottle',
                        'units_per_case'       => is_numeric($data['units_per_case'] ?? null) ? intval($data['units_per_case']) : null,
                        'cost_price'           => floatval($data['cost_price'] ?? 0),
                        'selling_price'        => floatval($data['selling_price'] ?? 0),
                        'mrp'                  => isset($data['mrp']) && is_numeric($data['mrp']) ? floatval($data['mrp']) : 0,
                        'tax_rate'             => is_numeric($data['tax_rate'] ?? null) ? floatval($data['tax_rate']) : 13.00,
                        'reorder_level'        => is_numeric($data['reorder_level'] ?? null) ? intval($data['reorder_level']) : 0,
                        'reorder_qty'          => is_numeric($data['reorder_qty'] ?? null) ? intval($data['reorder_qty']) : 0,
                        'cogs_account_id'      => $cogsId ?: 'acc-5100',
                        'income_account_id'    => $incomeId ?: 'acc-4100',
                        'inventory_account_id' => $inventoryId ?: 'acc-1200',
                        'is_active'            => 1,
                        'is_deleted'           => 0,
                    ];
                    $cols = implode(',', array_keys($fields));
                    $placeholders = implode(',', array_fill(0, count($fields), '?'));
                    $db->execute("INSERT INTO items ($cols) VALUES ($placeholders)", array_values($fields));
                }

                // Update location-specific inventory balance if location information is present
                $targetItemId = $id;
                $locName = trim($data['location_name'] ?? '');
                $targetLocId = null;
                if (!empty($locName) && $locName !== 'Default Location') {
                    $locRec = $db->fetchOne("SELECT id FROM locations WHERE LOWER(name) = LOWER(?) AND is_deleted = 0", [$locName]);
                    if ($locRec) $targetLocId = $locRec['id'];
                }
                if (!$targetLocId && function_exists('get_user_default_location_id')) {
                    $targetLocId = get_user_default_location_id();
                }

                if ($targetLocId) {
                    $locCost  = isset($data['location_cost_price']) && is_numeric($data['location_cost_price']) ? (float)$data['location_cost_price'] : (isset($data['cost_price']) && is_numeric($data['cost_price']) ? (float)$data['cost_price'] : null);
                    $locSell  = isset($data['location_selling_price']) && is_numeric($data['location_selling_price']) ? (float)$data['location_selling_price'] : (isset($data['selling_price']) && is_numeric($data['selling_price']) ? (float)$data['selling_price'] : null);
                    $locMrp   = isset($data['location_mrp']) && is_numeric($data['location_mrp']) ? (float)$data['location_mrp'] : (isset($data['mrp']) && is_numeric($data['mrp']) ? (float)$data['mrp'] : null);
                    $locStock = isset($data['location_stock']) && is_numeric($data['location_stock']) ? (float)$data['location_stock'] : null;

                    $bal = $db->fetchOne("SELECT id FROM inventory_balances WHERE item_id = ? AND location_id = ?", [$targetItemId, $targetLocId]);
                    if ($bal) {
                        $bUpdates = ["last_updated = NOW()"];
                        $bVals = [];
                        if ($locCost !== null) { $bUpdates[] = "cost_price = ?"; $bUpdates[] = "average_cost = ?"; $bVals[] = $locCost; $bVals[] = $locCost; }
                        if ($locSell !== null) { $bUpdates[] = "selling_price = ?"; $bVals[] = $locSell; }
                        if ($locMrp !== null)  { $bUpdates[] = "mrp = ?"; $bVals[] = $locMrp; }
                        if ($locStock !== null){ $bUpdates[] = "quantity_on_hand = ?"; $bUpdates[] = "available_qty = ?"; $bVals[] = $locStock; $bVals[] = $locStock; }
                        $bVals[] = $targetItemId;
                        $bVals[] = $targetLocId;
                        $db->execute("UPDATE inventory_balances SET " . implode(', ', $bUpdates) . " WHERE item_id = ? AND location_id = ?", $bVals);
                    } else if ($locCost !== null || $locSell !== null || $locMrp !== null || $locStock !== null) {
                        $db->execute(
                            "INSERT INTO inventory_balances (id, item_id, location_id, quantity_on_hand, available_qty, committed_qty, on_order_qty, average_cost, cost_price, selling_price, mrp, last_updated) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NOW())",
                            [sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)), $targetItemId, $targetLocId, $locStock ?? 0, $locStock ?? 0, $locCost ?? 0, $locCost ?? 0, $locSell ?? 0, $locMrp ?? 0]
                        );
                    }
                }

                $imported++;
            } catch (Exception $e) {
                $errors[] = "Row {$current_row} (" . ($itemSku ?: $itemId) . "): DB Error - " . $e->getMessage();
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
            $existing    = $db->fetchOne("SELECT id FROM accounts WHERE id = ? OR REPLACE(id, 'acc-', '') = ?", ['acc-' . $data['account_code'], $data['account_code']]);

            try {
                if ($existing) {
                    $db->execute("UPDATE accounts SET account_name=?, account_type=?, account_subtype=?, normal_balance=? WHERE id=?",
                        [$data['account_name'], $acctType, $acctSub, $normalBal, $existing['id']]);
                } else {
                    $id = 'acc-' . $data['account_code'];
                    $db->execute("INSERT INTO accounts (id, account_name, account_type, account_subtype, normal_balance, currency, is_active) VALUES (?,?,?,?,?,'NPR',1)",
                        [$id, $data['account_name'], $acctType, $acctSub, $normalBal]);
                }
                $imported++;
            } catch(Exception $e) {
                $errors[] = "Row {$current_row} ({$data['account_code']}): " . $e->getMessage();
            }
        }
    }
    else {
        // Generic Dynamic Table Importer for all database entities
        $allowed_tables = [
            'users'               => 'users',
            'locations'           => 'locations',
            'reference_codes'     => 'reference_codes',
            'roles'               => 'roles',
            'fiscal_years'        => 'fiscal_years',
            'pos_entry'           => 'pos_entry',
            'credit_memos'        => 'credit_memos',
            'vendor_credits'      => 'vendor_credits',
            'payments'            => 'payments',
            'expenses'            => 'expenses',
            'journal_entries'     => 'journal_entries',
            'account_transfers'   => 'account_transfers',
            'cash_denominations'  => 'cash_denominations',
            'inventory_transfers' => 'inventory_transfers',
            'activities'          => 'activities',
            'vendor_bills'        => 'vendor_bills',
            'customer_invoices'   => 'customer_invoices',
        ];

        if (!isset($allowed_tables[$type])) {
            throw new Exception("Unsupported import type: {$type}");
        }

        $target_table = $allowed_tables[$type];

        // Fetch valid columns for target table
        $table_cols_raw = $db->fetchAll("DESCRIBE `{$target_table}`");
        $valid_cols = array_map(fn($c) => $c['Field'], $table_cols_raw);

        while (($row = fgetcsv($handle)) !== false) {
            $current_row++;
            update_progress($progress_file, $current_row, $total_rows);

            if (empty(array_filter($row))) continue;
            if (count($row) < count($headers)) continue;

            $rawRow = array_combine($headers, array_slice($row, 0, count($headers)));
            $data = [];
            foreach ($rawRow as $k => $v) {
                $cleanKey = strtolower(trim($k));
                if (in_array($cleanKey, $valid_cols)) {
                    $data[$cleanKey] = is_string($v) ? trim($v) : $v;
                }
            }

            if (empty($data)) continue;

            // If id is empty or non-numeric placeholder (e.g. template sample), unset it so MySQL AUTO_INCREMENT assigns it
            if (isset($data['id']) && (!is_numeric($data['id']) || empty($data['id']))) {
                unset($data['id']);
            }

            try {
                $cols = array_keys($data);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $col_str = implode('`, `', $cols);

                $updates = [];
                foreach ($cols as $col) {
                    $updates[] = "`{$col}` = VALUES(`{$col}`)";
                }
                $update_str = implode(', ', $updates);

                $sql = "INSERT INTO `{$target_table}` (`{$col_str}`) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$update_str}";
                $db->execute($sql, array_values($data));
                $imported++;
            } catch (Exception $e) {
                $errors[] = "Row {$current_row}: " . $e->getMessage();
            }
        }
    }
    
    $pdo = $db->getConnection();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    fclose($handle);
    update_progress($progress_file, $total_rows, $total_rows);

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    
    $message = "Successfully imported $imported records";
    if(count($errors) > 0) {
        $message .= " with " . count($errors) . " error(s). Download the error report below.";
    }
    
    echo json_encode(['status' => 'success', 'message' => $message, 'imported' => $imported, 'error_count' => count($errors), 'errors' => $errors]);
    
} catch(Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
?>
