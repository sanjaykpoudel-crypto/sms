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

normalizeCsvEncoding($file['tmp_name']);

$handle = fopen($file['tmp_name'], 'r');
$header = fgetcsv($handle);

if (!$header) {
    echo json_encode(['status' => 'error', 'message' => 'Empty CSV file.']);
    exit;
}

// Map header to indices stripping control chars
$headerMap = [];
foreach ($header as $idx => $col) {
    $cleanCol = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', strtolower(trim($col))));
    if ($cleanCol !== '') {
        $headerMap[$cleanCol] = $idx;
    }
}

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

// Pre-fetch in-memory caches for high-performance batch processing
$cacheSystemInfo = [];
$sysRows = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
foreach ($sysRows as $sr) {
    $cacheSystemInfo[$sr['meta_field']] = $sr['meta_value'];
}

$cacheAccounts = [];
$accRows = $db->fetchAll("SELECT id FROM accounts WHERE is_deleted = 0");
foreach ($accRows as $ar) {
    $cacheAccounts[$ar['id']] = $ar['id'];
    $cleanId = str_replace('acc-', '', $ar['id']);
    $cacheAccounts[$cleanId] = $ar['id'];
}

$cacheRefCodes = [];
$refRows = $db->fetchAll("SELECT id, type, name, value FROM reference_codes WHERE is_active = 1");
foreach ($refRows as $rr) {
    if (!empty($rr['name'])) {
        $cacheRefCodes[$rr['type'] . '_' . strtolower(trim($rr['name']))] = $rr['id'];
    }
    if ($rr['type'] === 'tax_code' && isset($rr['value'])) {
        $cacheRefCodes['tax_code_' . (string)$rr['value']] = $rr['id'];
    }
}

$cacheLocations = [];
$locRows = $db->fetchAll("SELECT id, name FROM locations WHERE is_deleted = 0");
foreach ($locRows as $lr) {
    $cacheLocations[$lr['id']] = $lr['id'];
    $cacheLocations[strtolower(trim($lr['name']))] = $lr['id'];
}

$cacheItemsById = [];
$cacheItemsBySku = [];
if ($type === 'items') {
    $itemRows = $db->fetchAll("SELECT id, sku, item_name FROM items");
    foreach ($itemRows as $ir) {
        $cleanId = strtolower(trim($ir['id']));
        $cacheItemsById[$cleanId] = $ir;
        if (!empty($ir['sku'])) {
            $cleanSku = strtolower(trim($ir['sku']));
            $cacheItemsBySku[$cleanSku] = $ir;
        }
    }
}

$cacheBalances = [];
if ($type === 'items') {
    $balRows = $db->fetchAll("SELECT id, item_id, location_id FROM inventory_balances");
    foreach ($balRows as $br) {
        $cacheBalances[$br['item_id'] . '_' . $br['location_id']] = $br['id'];
    }
}

$defaultLocationId = function_exists('get_user_default_location_id') ? get_user_default_location_id() : '';

// Start single transaction for batch speed
if (!$pdo->inTransaction()) {
    $pdo->beginTransaction();
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
                processItem($data, $db, $pdo, $userId, $cacheItemsById, $cacheItemsBySku, $cacheRefCodes, $cacheAccounts, $cacheSystemInfo, $cacheLocations, $cacheBalances, $defaultLocationId);
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

    // Send progress every 20 rows or if errors exist
    if ($currentRow % 20 == 0 || !empty($errors)) {
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

if ($pdo->inTransaction()) {
    $pdo->commit();
}

sendProgress($totalRows, $totalRows, $successCount, $failedCount, $errors);

fclose($handle);

// Helper Functions
function processItem($data, $db, $pdo, $userId, &$cacheItemsById, &$cacheItemsBySku, &$cacheRefCodes, &$cacheAccounts, &$cacheSystemInfo, &$cacheLocations, &$cacheBalances, $defaultLocationId) {
    // 1. Normalize CSV headers with support for partial/truncated Excel headers (e.g. tem_name, em_cate, ttle_siz, unit_ty, etc.)
    $row = [];
    foreach ($data as $k => $v) {
        $cleanKey = preg_replace('/[\x00-\x1F\x7F-\xFF\xEF\xBB\xBF\xFE\xFF]/', '', $k);
        $cleanKey = strtolower(trim(str_replace([' ', '-'], '_', $cleanKey)));

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

        $row[$cleanKey] = is_string($v) ? trim($v) : $v;
    }

    $itemId  = !empty($row['id']) ? strtolower(trim($row['id'])) : null;
    $itemSku = !empty($row['sku']) ? strtolower(trim($row['sku'])) : null;

    // 2. Case-insensitive lookup for existing item by ID first, then fallback to SKU
    $existing = null;
    if ($itemId) {
        if (isset($cacheItemsById[$itemId])) {
            $existing = $cacheItemsById[$itemId];
        } else {
            $found = $db->fetchOne("SELECT id, sku, item_name FROM items WHERE LOWER(id) = LOWER(?)", [$itemId]);
            if ($found) {
                $existing = $found;
                $cacheItemsById[strtolower(trim($found['id']))] = $found;
            }
        }
    }

    if (!$existing && $itemSku) {
        if (isset($cacheItemsBySku[$itemSku])) {
            $existing = $cacheItemsBySku[$itemSku];
        } else {
            $found = $db->fetchOne("SELECT id, sku, item_name FROM items WHERE LOWER(sku) = LOWER(?)", [$itemSku]);
            if ($found) {
                $existing = $found;
                $cacheItemsBySku[strtolower(trim($found['sku']))] = $found;
            }
        }
    }

    // Helper to get account ID from memory cache
    $getAccount = function($csvKeys, $metaField, $defaultId) use ($row, &$cacheAccounts, &$cacheSystemInfo) {
        foreach ((array)$csvKeys as $csvKey) {
            $code = trim($row[$csvKey] ?? '');
            if (!empty($code)) {
                if (isset($cacheAccounts[$code])) return $cacheAccounts[$code];
                $clean = str_replace('acc-', '', $code);
                if (isset($cacheAccounts[$clean])) return $cacheAccounts[$clean];
            }
        }
        $sysDefault = $cacheSystemInfo[$metaField] ?? $defaultId;
        if (isset($cacheAccounts[$sysDefault])) return $cacheAccounts[$sysDefault];
        return $defaultId;
    };

    $cogsId      = $getAccount(['cogs_account_code', 'cogs_account'], 'default_cogs_account', 'acc-5100');
    $incomeId    = $getAccount(['income_account_code', 'income_account'], 'default_income_account', 'acc-4100');
    $inventoryId = $getAccount(['inventory_account_code', 'inventory_account'], 'default_asset_account', 'acc-1200');

    // Resolve category, unit, tax
    $cat_name = !empty($row['item_category']) ? $row['item_category'] : (!empty($row['category']) ? $row['category'] : null);
    $cat_id   = null;
    if (!empty($cat_name)) {
        $cat_key = 'category_' . strtolower(trim($cat_name));
        if (isset($cacheRefCodes[$cat_key])) {
            $cat_id = $cacheRefCodes[$cat_key];
        } else {
            $cat_id = generate_uuid();
            $db->execute("INSERT INTO reference_codes (id, type, name, is_active) VALUES (?, 'category', ?, 1)", [$cat_id, $cat_name]);
            $cacheRefCodes[$cat_key] = $cat_id;
        }
    }

    $unit_name = !empty($row['unit_type']) ? $row['unit_type'] : (!empty($row['unit']) ? $row['unit'] : null);
    $unit_id   = null;
    if (!empty($unit_name)) {
        $unit_key = 'units_' . strtolower(trim($unit_name));
        if (isset($cacheRefCodes[$unit_key])) {
            $unit_id = $cacheRefCodes[$unit_key];
        } else {
            $unit_id = generate_uuid();
            $db->execute("INSERT INTO reference_codes (id, type, name, is_active) VALUES (?, 'units', ?, 1)", [$unit_id, $unit_name]);
            $cacheRefCodes[$unit_key] = $unit_id;
        }
    }

    $tax_rate = isset($row['tax_rate']) && is_numeric($row['tax_rate']) ? (float)$row['tax_rate'] : null;
    $tax_id   = null;
    if ($tax_rate !== null) {
        $tax_key = 'tax_code_' . (string)$tax_rate;
        if (isset($cacheRefCodes[$tax_key])) {
            $tax_id = $cacheRefCodes[$tax_key];
        } else {
            $tax_name = $tax_rate > 0 ? "VAT $tax_rate%" : "Non-Taxable";
            $tax_id   = generate_uuid();
            $db->execute("INSERT INTO reference_codes (id, type, name, value, is_active) VALUES (?, 'tax_code', ?, ?, 1)", [$tax_id, $tax_name, $tax_rate]);
            $cacheRefCodes[$tax_key] = $tax_id;
        }
    }

    if ($existing || $itemId) {
        // --- UPDATE / UPSERT MODE (ID or existing record matched) ---
        $targetItemId = $existing ? $existing['id'] : $itemId;

        $itemData = [];
        if (array_key_exists('sku', $row) && trim($row['sku']) !== '')                         $itemData['sku'] = trim($row['sku']);
        if (array_key_exists('item_name', $row) && trim($row['item_name']) !== '')             $itemData['item_name'] = trim($row['item_name']);
        elseif (array_key_exists('name', $row) && trim($row['name']) !== '')                   $itemData['item_name'] = trim($row['name']);
        if (!empty($cat_id))                                                                   $itemData['item_category'] = $cat_id;
        if (array_key_exists('brand', $row) && $row['brand'] !== '')                           $itemData['brand'] = $row['brand'];
        if (array_key_exists('bottle_size_ml', $row) && is_numeric($row['bottle_size_ml']))   $itemData['bottle_size_ml'] = (float)$row['bottle_size_ml'];
        if (!empty($unit_id))                                                                  $itemData['unit_type'] = $unit_id;
        if (array_key_exists('units_per_case', $row) && is_numeric($row['units_per_case']))   $itemData['units_per_case'] = (int)$row['units_per_case'];
        if (array_key_exists('cost_price', $row) && is_numeric($row['cost_price']))           $itemData['cost_price'] = (float)$row['cost_price'];
        if (array_key_exists('selling_price', $row) && is_numeric($row['selling_price']))     $itemData['selling_price'] = (float)$row['selling_price'];
        if (array_key_exists('mrp', $row) && is_numeric($row['mrp']))                         $itemData['mrp'] = (float)$row['mrp'];
        if ($tax_rate !== null) {
            $itemData['tax_rate'] = $tax_rate;
            if ($tax_id) $itemData['tax_id'] = $tax_id;
        }
        if (array_key_exists('reorder_level', $row) && is_numeric($row['reorder_level']))     $itemData['reorder_level'] = (int)$row['reorder_level'];
        if (array_key_exists('reorder_qty', $row) && is_numeric($row['reorder_qty']))         $itemData['reorder_qty'] = (int)$row['reorder_qty'];
        if (!empty($cogsId))      $itemData['cogs_account_id'] = $cogsId;
        if (!empty($incomeId))    $itemData['income_account_id'] = $incomeId;
        if (!empty($inventoryId)) $itemData['inventory_account_id'] = $inventoryId;

        if ($existing) {
            if (!empty($itemData)) {
                $payload = [
                    'action'        => 'update',
                    'table'         => 'items',
                    'primary_key'   => 'id',
                    'primary_value' => $existing['id'],
                    'data'          => $itemData
                ];
                callTransactionHandler($payload);
            }
            $itemData['sku'] = $itemData['sku'] ?? $existing['sku'];
            $itemData['item_name'] = $itemData['item_name'] ?? $existing['item_name'];
        } else {
            // Specified ID in CSV but record does not exist in DB yet: insert directly with that ID
            $itemData['id'] = $itemId;
            $itemData['item_name'] = $itemData['item_name'] ?? ($row['item_name'] ?? ($row['name'] ?? 'Item ' . $itemId));
            $itemData['sku']       = $itemData['sku'] ?? ($row['sku'] ?? '');
            $itemData['item_category'] = $itemData['item_category'] ?? ($cat_id ?: 'Other');
            $itemData['unit_type']     = $itemData['unit_type'] ?? ($unit_id ?: 'Piece');
            $itemData['cogs_account_id']      = $itemData['cogs_account_id'] ?? $cogsId;
            $itemData['income_account_id']    = $itemData['income_account_id'] ?? $incomeId;
            $itemData['inventory_account_id'] = $itemData['inventory_account_id'] ?? $inventoryId;
            $itemData['is_active']  = 1;
            $itemData['is_deleted'] = 0;

            $payload = [
                'action'        => 'save',
                'table'         => 'items',
                'primary_key'   => 'id',
                'primary_value' => null,
                'data'          => $itemData
            ];
            callTransactionHandler($payload);
        }
    } else {
        // --- CREATE MODE: No ID or SKU provided ---
        $itemName = !empty($row['item_name']) ? $row['item_name'] : (!empty($row['name']) ? $row['name'] : '');
        if (empty($itemName)) {
            throw new Exception("Item Name is required to create a new item.");
        }

        $newItemId = generate_uuid();

        $itemData = [
            'id'                   => $newItemId,
            'sku'                  => $itemSku ?: '',
            'item_name'            => $itemName,
            'item_category'        => $cat_id ?: 'Other',
            'brand'                => $row['brand'] ?? '',
            'bottle_size_ml'       => is_numeric($row['bottle_size_ml'] ?? null) ? (float)$row['bottle_size_ml'] : 0,
            'unit_type'            => $unit_id ?: 'Piece',
            'units_per_case'       => is_numeric($row['units_per_case'] ?? null) ? (int)$row['units_per_case'] : 1,
            'cost_price'           => is_numeric($row['cost_price'] ?? null) ? (float)$row['cost_price'] : 0,
            'selling_price'        => is_numeric($row['selling_price'] ?? null) ? (float)$row['selling_price'] : 0,
            'mrp'                  => isset($row['mrp']) && is_numeric($row['mrp']) ? (float)$row['mrp'] : 0,
            'tax_rate'             => $tax_rate !== null ? $tax_rate : 13.00,
            'tax_id'               => $tax_id,
            'status_id'            => null,
            'reorder_level'        => is_numeric($row['reorder_level'] ?? null) ? (int)$row['reorder_level'] : 0,
            'reorder_qty'          => is_numeric($row['reorder_qty'] ?? null) ? (int)$row['reorder_qty'] : 0,
            'cogs_account_id'      => $cogsId ?: 'acc-5100',
            'income_account_id'    => $incomeId ?: 'acc-4100',
            'inventory_account_id' => $inventoryId ?: 'acc-1200',
            'is_active'            => 1,
            'is_deleted'           => 0
        ];

        $payload = [
            'action'        => 'save',
            'table'         => 'items',
            'primary_key'   => 'id',
            'primary_value' => null,
            'data'          => $itemData
        ];
        callTransactionHandler($payload);
        $targetItemId = $newItemId;
    }

    // Update in-memory item cache
    if ($targetItemId) {
        $cacheItemsById[$targetItemId] = ['id' => $targetItemId, 'sku' => $itemData['sku'] ?? '', 'item_name' => $itemData['item_name'] ?? ''];
        if (!empty($itemData['sku'])) {
            $cacheItemsBySku[$itemData['sku']] = ['id' => $targetItemId, 'sku' => $itemData['sku'], 'item_name' => $itemData['item_name'] ?? ''];
        }

        // Location balance lookup & update
        $locName = !empty($row['location_name']) ? $row['location_name'] : (!empty($row['location']) ? $row['location'] : '');
        $targetLocId = null;
        if (!empty($locName) && strtolower($locName) !== 'default location') {
            $lowerLoc = strtolower($locName);
            if (isset($cacheLocations[$lowerLoc])) {
                $targetLocId = $cacheLocations[$lowerLoc];
            }
        }
        if (!$targetLocId) {
            $targetLocId = $defaultLocationId;
        }

        $locCost  = isset($row['location_cost_price']) && is_numeric($row['location_cost_price']) ? (float)$row['location_cost_price'] : (isset($row['cost_price']) && is_numeric($row['cost_price']) ? (float)$row['cost_price'] : null);
        $locSell  = isset($row['location_selling_price']) && is_numeric($row['location_selling_price']) ? (float)$row['location_selling_price'] : (isset($row['selling_price']) && is_numeric($row['selling_price']) ? (float)$row['selling_price'] : null);
        $locMrp   = isset($row['location_mrp']) && is_numeric($row['location_mrp']) ? (float)$row['location_mrp'] : (isset($row['mrp']) && is_numeric($row['mrp']) ? (float)$row['mrp'] : null);
        $locStock = isset($row['location_stock']) && is_numeric($row['location_stock']) ? (float)$row['location_stock'] : (isset($row['current_stock']) && is_numeric($row['current_stock']) ? (float)$row['current_stock'] : null);

        if ($targetLocId) {
            $balKey = $targetItemId . '_' . $targetLocId;
            if (isset($cacheBalances[$balKey])) {
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
                $newBalId = generate_uuid();
                $db->execute(
                    "INSERT INTO inventory_balances (id, item_id, location_id, quantity_on_hand, available_qty, committed_qty, on_order_qty, average_cost, cost_price, selling_price, mrp, last_updated) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NOW())",
                    [$newBalId, $targetItemId, $targetLocId, $locStock ?? 0, $locStock ?? 0, $locCost ?? 0, $locCost ?? 0, $locSell ?? 0, $locMrp ?? 0]
                );
                $cacheBalances[$balKey] = $newBalId;
            }
        }
    }
}

function processCustomer($data, $db, $pdo, $userId) {
    $row = [];
    foreach ($data as $k => $v) {
        $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', strtolower(trim($k))));
        if (in_array($cleanKey, ['id', '_id'])) $cleanKey = 'id';
        elseif (in_array($cleanKey, ['customer_code', 'code'])) $cleanKey = 'customer_code';
        elseif (in_array($cleanKey, ['full_name', 'name', 'customer_name'])) $cleanKey = 'full_name';
        $row[$cleanKey] = is_string($v) ? trim($v) : $v;
    }

    $id   = !empty($row['id']) ? strtolower(trim($row['id'])) : null;
    $code = !empty($row['customer_code']) ? strtolower(trim($row['customer_code'])) : null;

    $existing = null;
    if ($id) {
        $existing = $db->fetchOne("SELECT id, full_name, customer_code FROM customers WHERE LOWER(id) = LOWER(?)", [$id]);
    }
    if (!$existing && $code) {
        $existing = $db->fetchOne("SELECT id, full_name, customer_code FROM customers WHERE LOWER(customer_code) = LOWER(?)", [$code]);
    }

    $ar = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_ar_account'")['meta_value'] ?? 'acc-1100';

    if ($existing || $id) {
        $cData = [];
        if (array_key_exists('customer_code', $row) && $row['customer_code'] !== '') $cData['customer_code'] = $row['customer_code'];
        if (array_key_exists('full_name', $row) && $row['full_name'] !== '')         $cData['full_name']     = $row['full_name'];
        if (array_key_exists('customer_type', $row) && $row['customer_type'] !== '') $cData['customer_type'] = $row['customer_type'];
        if (array_key_exists('phone', $row))         $cData['phone']         = $row['phone'];
        if (array_key_exists('email', $row))         $cData['email']         = $row['email'];
        if (array_key_exists('pan_number', $row))    $cData['pan_number']    = $row['pan_number'];
        if (array_key_exists('credit_limit', $row) && is_numeric($row['credit_limit'])) $cData['credit_limit'] = (float)$row['credit_limit'];
        if (array_key_exists('payment_terms_days', $row) && is_numeric($row['payment_terms_days'])) $cData['payment_terms_days'] = (int)$row['payment_terms_days'];

        if ($existing) {
            if (!empty($cData)) {
                $payload = ['action' => 'update', 'table' => 'customers', 'primary_key' => 'id', 'primary_value' => $existing['id'], 'data' => $cData];
                callTransactionHandler($payload);
            }
        } else {
            $cData['id'] = $id;
            $cData['full_name'] = $cData['full_name'] ?? ($row['full_name'] ?? 'Customer ' . $id);
            $cData['receivable_account_id'] = $ar;
            $payload = ['action' => 'save', 'table' => 'customers', 'primary_key' => 'id', 'primary_value' => null, 'data' => $cData];
            callTransactionHandler($payload);
        }
    } else {
        if (empty($row['full_name'])) throw new Exception("Full Name is required for new customer");
        $payload = [
            'action' => 'save',
            'table' => 'customers',
            'primary_key' => 'id',
            'primary_value' => null,
            'data' => [
                'id' => generate_uuid(),
                'customer_code' => $row['customer_code'] ?? '',
                'full_name' => $row['full_name'],
                'customer_type' => $row['customer_type'] ?? 'retail',
                'phone' => $row['phone'] ?? '',
                'email' => $row['email'] ?? '',
                'pan_number' => $row['pan_number'] ?? '',
                'receivable_account_id' => $ar,
                'credit_limit' => is_numeric($row['credit_limit'] ?? null) ? (float)$row['credit_limit'] : 0,
                'payment_terms_days' => is_numeric($row['payment_terms_days'] ?? null) ? (int)$row['payment_terms_days'] : 0
            ]
        ];
        callTransactionHandler($payload);
    }
}

function processVendor($data, $db, $pdo, $userId) {
    $row = [];
    foreach ($data as $k => $v) {
        $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', strtolower(trim($k))));
        if (in_array($cleanKey, ['id', '_id'])) $cleanKey = 'id';
        elseif (in_array($cleanKey, ['vendor_code', 'supplier_code', 'code'])) $cleanKey = 'vendor_code';
        elseif (in_array($cleanKey, ['company_name', 'vendor_name', 'supplier_name', 'name'])) $cleanKey = 'company_name';
        $row[$cleanKey] = is_string($v) ? trim($v) : $v;
    }

    $id   = !empty($row['id']) ? strtolower(trim($row['id'])) : null;
    $code = !empty($row['vendor_code']) ? strtolower(trim($row['vendor_code'])) : null;

    $existing = null;
    if ($id) {
        $existing = $db->fetchOne("SELECT id, company_name, vendor_code FROM vendors WHERE LOWER(id) = LOWER(?)", [$id]);
    }
    if (!$existing && $code) {
        $existing = $db->fetchOne("SELECT id, company_name, vendor_code FROM vendors WHERE LOWER(vendor_code) = LOWER(?)", [$code]);
    }

    $ap = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_ap_account'")['meta_value'] ?? 'acc-2100';

    if ($existing || $id) {
        $vData = [];
        if (array_key_exists('vendor_code', $row) && $row['vendor_code'] !== '')   $vData['vendor_code']  = $row['vendor_code'];
        if (array_key_exists('company_name', $row) && $row['company_name'] !== '') $vData['company_name'] = $row['company_name'];
        if (array_key_exists('contact_name', $row)) $vData['contact_name'] = $row['contact_name'];
        if (array_key_exists('phone', $row))        $vData['phone']        = $row['phone'];
        if (array_key_exists('email', $row))        $vData['email']        = $row['email'];
        if (array_key_exists('address', $row))      $vData['address']      = $row['address'];
        if (array_key_exists('pan_number', $row))   $vData['pan_number']   = $row['pan_number'];
        if (array_key_exists('vat_number', $row))   $vData['vat_number']   = $row['vat_number'];
        if (array_key_exists('payment_terms_days', $row) && is_numeric($row['payment_terms_days'])) $vData['payment_terms_days'] = (int)$row['payment_terms_days'];
        if (array_key_exists('credit_limit', $row) && is_numeric($row['credit_limit']))               $vData['credit_limit']       = (float)$row['credit_limit'];

        if ($existing) {
            if (!empty($vData)) {
                $payload = ['action' => 'update', 'table' => 'vendors', 'primary_key' => 'id', 'primary_value' => $existing['id'], 'data' => $vData];
                callTransactionHandler($payload);
            }
        } else {
            $vData['id'] = $id;
            $vData['company_name'] = $vData['company_name'] ?? ($row['company_name'] ?? 'Vendor ' . $id);
            $vData['payable_account_id'] = $ap;
            $payload = ['action' => 'save', 'table' => 'vendors', 'primary_key' => 'id', 'primary_value' => null, 'data' => $vData];
            callTransactionHandler($payload);
        }
    } else {
        if (empty($row['company_name'])) throw new Exception("Company Name is required for new vendor");
        $payload = [
            'action' => 'save',
            'table' => 'vendors',
            'primary_key' => 'id',
            'primary_value' => null,
            'data' => [
                'id' => generate_uuid(),
                'vendor_code' => $row['vendor_code'] ?? '',
                'company_name' => $row['company_name'],
                'contact_name' => $row['contact_name'] ?? '',
                'phone' => $row['phone'] ?? '',
                'email' => $row['email'] ?? '',
                'address' => $row['address'] ?? '',
                'pan_number' => $row['pan_number'] ?? '',
                'vat_number' => $row['vat_number'] ?? '',
                'payable_account_id' => $ap,
                'payment_terms_days' => is_numeric($row['payment_terms_days'] ?? null) ? (int)$row['payment_terms_days'] : 0,
                'credit_limit' => is_numeric($row['credit_limit'] ?? null) ? (float)$row['credit_limit'] : 0
            ]
        ];
        callTransactionHandler($payload);
    }
}

function processAccount($data, $db, $pdo, $userId) {
    $row = [];
    foreach ($data as $k => $v) {
        $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', strtolower(trim($k))));
        if (in_array($cleanKey, ['id', '_id'])) $cleanKey = 'id';
        elseif (in_array($cleanKey, ['account_code', 'code'])) $cleanKey = 'account_code';
        elseif (in_array($cleanKey, ['account_name', 'name'])) $cleanKey = 'account_name';
        $row[$cleanKey] = is_string($v) ? trim($v) : $v;
    }

    $id   = !empty($row['id']) ? trim($row['id']) : null;
    $code = !empty($row['account_code']) ? trim($row['account_code']) : null;

    $existing = null;
    if ($id) {
        $existing = $db->fetchOne("SELECT id, REPLACE(id, 'acc-', '') as account_code, account_name FROM accounts WHERE id = ? OR REPLACE(id, 'acc-', '') = ?", [$id, $id]);
    }
    if (!$existing && $code) {
        $existing = $db->fetchOne("SELECT id, REPLACE(id, 'acc-', '') as account_code, account_name FROM accounts WHERE id = ? OR REPLACE(id, 'acc-', '') = ?", ['acc-' . $code, $code]);
    }

    if ($existing || $id) {
        $accData = [];
        if (array_key_exists('account_code', $row) && $row['account_code'] !== '')   $accData['account_code']   = $row['account_code'];
        if (array_key_exists('account_name', $row) && $row['account_name'] !== '')   $accData['account_name']   = $row['account_name'];
        if (array_key_exists('account_type', $row) && $row['account_type'] !== '')   $accData['account_type']   = $row['account_type'];
        if (array_key_exists('account_subtype', $row) && $row['account_subtype'] !== '') $accData['account_subtype'] = $row['account_subtype'];
        if (array_key_exists('normal_balance', $row) && $row['normal_balance'] !== '') $accData['normal_balance'] = $row['normal_balance'];
        if (array_key_exists('currency', $row) && $row['currency'] !== '')           $accData['currency']       = $row['currency'];

        if ($existing) {
            if (!empty($accData)) {
                $payload = ['action' => 'update', 'table' => 'accounts', 'primary_key' => 'id', 'primary_value' => $existing['id'], 'data' => $accData];
                callTransactionHandler($payload);
            }
        } else {
            $accId = strpos($id, 'acc-') === 0 ? $id : 'acc-' . $id;
            $accData['id'] = $accId;
            $accData['account_code'] = $accData['account_code'] ?? $id;
            $accData['account_name'] = $accData['account_name'] ?? ('Account ' . $id);
            $accData['account_type'] = $accData['account_type'] ?? 'expense';
            $accData['account_subtype'] = $accData['account_subtype'] ?? 'other';
            $accData['normal_balance'] = $accData['normal_balance'] ?? 'debit';
            $accData['currency'] = $accData['currency'] ?? 'NPR';
            $payload = ['action' => 'save', 'table' => 'accounts', 'primary_key' => 'id', 'primary_value' => null, 'data' => $accData];
            callTransactionHandler($payload);
        }
    } else {
        if (empty($row['account_code']) || empty($row['account_name'])) throw new Exception("Account Code and Name are required");
        $accId = 'acc-' . $row['account_code'];
        $payload = [
            'action' => 'save',
            'table' => 'accounts',
            'primary_key' => 'id',
            'primary_value' => null,
            'data' => [
                'id' => $accId,
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'account_type' => $row['account_type'] ?? 'expense',
                'account_subtype' => $row['account_subtype'] ?? 'other',
                'normal_balance' => $row['normal_balance'] ?? 'debit',
                'currency' => $row['currency'] ?? 'NPR'
            ]
        ];
        callTransactionHandler($payload);
    }
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
            $account = $db->fetchOne("SELECT id FROM accounts WHERE id = ? OR REPLACE(id, 'acc-', '') = ?", ['acc-' . $row['account_code'], $row['account_code']]);
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
        $expAccount = $db->fetchOne("SELECT id FROM accounts WHERE id = ? OR REPLACE(id, 'acc-', '') = ?", ['acc-' . $row['expense_account_code'], $row['expense_account_code']]);
        $paidAccount = $db->fetchOne("SELECT id FROM accounts WHERE id = ? OR REPLACE(id, 'acc-', '') = ?", ['acc-' . $row['paid_from_account_code'], $row['paid_from_account_code']]);
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
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
