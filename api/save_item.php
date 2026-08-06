<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$db = db();

try {
    $id                = trim($_POST['id'] ?? '');
    $item_name         = trim($_POST['item_name'] ?? '');
    $item_category     = trim($_POST['item_category'] ?? '');
    $brand             = trim($_POST['brand'] ?? '');
    $unit_type         = trim($_POST['unit_type'] ?? '');
    $bottle_size_ml    = !empty($_POST['bottle_size_ml']) ? (float)$_POST['bottle_size_ml'] : null;
    $units_per_case    = !empty($_POST['units_per_case']) ? (int)$_POST['units_per_case'] : null;
    $barcode           = trim($_POST['barcode'] ?? '');
    $is_active         = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    
    $cost_price        = isset($_POST['cost_price']) && is_numeric($_POST['cost_price']) ? (float)$_POST['cost_price'] : 0.00;
    $selling_price_raw = $_POST['selling_price'] ?? null;
    $mrp               = isset($_POST['mrp']) && is_numeric($_POST['mrp']) ? (float)$_POST['mrp'] : 0.00;
    $tax_id            = trim($_POST['tax_id'] ?? '');
    $tax_rate          = isset($_POST['tax_rate']) ? (float)$_POST['tax_rate'] : 0.00;
    $reorder_level     = isset($_POST['reorder_level']) && $_POST['reorder_level'] !== '' ? (int)$_POST['reorder_level'] : 10;
    $reorder_qty       = isset($_POST['reorder_qty']) && $_POST['reorder_qty'] !== '' ? (int)$_POST['reorder_qty'] : null;
    $description       = trim($_POST['description'] ?? '');

    $inventory_account_id = trim($_POST['inventory_account_id'] ?? '');
    $cogs_account_id      = trim($_POST['cogs_account_id'] ?? '');
    $income_account_id    = trim($_POST['income_account_id'] ?? '');

    // 1. Item Name Validation (Required & Must Be Unique)
    if (empty($item_name)) {
        throw new Exception("Item Name is required.");
    }
    $name_check_sql = "SELECT COUNT(*) as count FROM items WHERE LOWER(TRIM(item_name)) = LOWER(?) AND is_deleted = 0";
    $params = [$item_name];
    if (!empty($id)) {
        $name_check_sql .= " AND id != ?";
        $params[] = $id;
    }
    $existing_name_count = (int)($db->fetchOne($name_check_sql, $params)['count'] ?? 0);
    if ($existing_name_count > 0) {
        throw new Exception("An item with the name '" . htmlspecialchars($item_name) . "' already exists.");
    }

    // 2. Barcode Validation (Optional, but if provided MUST be unique)
    if (!empty($barcode)) {
        $bc_check_sql = "SELECT COUNT(*) as count FROM items WHERE barcode = ? AND is_deleted = 0";
        $bc_params = [$barcode];
        if (!empty($id)) {
            $bc_check_sql .= " AND id != ?";
            $bc_params[] = $id;
        }
        $existing_bc_count = (int)($db->fetchOne($bc_check_sql, $bc_params)['count'] ?? 0);
        if ($existing_bc_count > 0) {
            throw new Exception("Barcode '" . htmlspecialchars($barcode) . "' is already assigned to another item.");
        }
    }

    // 3. Selling Price Validation (Required & Must Be Numeric >= 0)
    if ($selling_price_raw === null || $selling_price_raw === '' || !is_numeric($selling_price_raw)) {
        throw new Exception("Selling Price is required.");
    }
    $selling_price = (float)$selling_price_raw;
    if ($selling_price < 0) {
        throw new Exception("Selling Price cannot be negative.");
    }

    // 4. Accounting Configuration Validation (Required on Create and Edit)
    if (empty($inventory_account_id)) {
        throw new Exception("Inventory Account is required in Accounting Configuration.");
    }
    if (empty($cogs_account_id)) {
        throw new Exception("COGS Account is required in Accounting Configuration.");
    }
    if (empty($income_account_id)) {
        throw new Exception("Income Account is required in Accounting Configuration.");
    }

    if (empty($id)) {
        // Create new item
        $id = generate_uuid();
        $sku_count = (int)($db->fetchOne("SELECT COUNT(*) as count FROM items")['count'] ?? 0);
        $sku = 'CB-' . str_pad($sku_count + 1, 3, '0', STR_PAD_LEFT);

        $db->execute("
            INSERT INTO items (
                id, sku, item_name, item_category, brand, unit_type, bottle_size_ml, units_per_case,
                barcode, is_active, cost_price, selling_price, mrp, tax_id, tax_rate, reorder_level, reorder_qty,
                description, inventory_account_id, cogs_account_id, income_account_id, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ", [
            $id, $sku, $item_name, $item_category, $brand, $unit_type, $bottle_size_ml, $units_per_case,
            $barcode ?: null, $is_active, $cost_price, $selling_price, $mrp, $tax_id ?: null, $tax_rate, $reorder_level, $reorder_qty,
            $description, $inventory_account_id, $cogs_account_id, $income_account_id
        ]);

        $message = "Item created successfully.";
    } else {
        // Update existing item
        $db->execute("
            UPDATE items SET
                item_name = ?, item_category = ?, brand = ?, unit_type = ?, bottle_size_ml = ?, units_per_case = ?,
                barcode = ?, is_active = ?, cost_price = ?, selling_price = ?, mrp = ?, tax_id = ?, tax_rate = ?,
                reorder_level = ?, reorder_qty = ?, description = ?, inventory_account_id = ?, cogs_account_id = ?,
                income_account_id = ?, updated_at = NOW()
            WHERE id = ?
        ", [
            $item_name, $item_category, $brand, $unit_type, $bottle_size_ml, $units_per_case,
            $barcode ?: null, $is_active, $cost_price, $selling_price, $mrp, $tax_id ?: null, $tax_rate,
            $reorder_level, $reorder_qty, $description, $inventory_account_id, $cogs_account_id,
            $income_account_id, $id
        ]);

        $message = "Item updated successfully.";
    }

    // Process Location-Specific Pricing & Costing Overrides
    $loc_cost    = $_POST['loc_cost_price'] ?? [];
    $loc_selling = $_POST['loc_selling_price'] ?? [];
    $loc_mrp     = $_POST['loc_mrp'] ?? [];

    $all_locs = $db->fetchAll("SELECT id FROM locations WHERE is_deleted = 0");
    foreach ($all_locs as $loc_row) {
        $lid = $loc_row['id'];
        $c_price = isset($loc_cost[$lid]) && $loc_cost[$lid] !== '' ? (float)$loc_cost[$lid] : null;
        $s_price = isset($loc_selling[$lid]) && $loc_selling[$lid] !== '' ? (float)$loc_selling[$lid] : null;
        $m_price = isset($loc_mrp[$lid]) && $loc_mrp[$lid] !== '' ? (float)$loc_mrp[$lid] : null;

        $inv_exists = $db->fetchOne("SELECT id FROM inventory_balances WHERE item_id = ? AND location_id = ?", [$id, $lid]);
        if ($inv_exists) {
            $db->execute("
                UPDATE inventory_balances 
                SET cost_price = ?, selling_price = ?, mrp = ?, last_updated = NOW()
                WHERE item_id = ? AND location_id = ?
            ", [$c_price, $s_price, $m_price, $id, $lid]);
        } else if ($c_price !== null || $s_price !== null || $m_price !== null) {
            $inv_id = generate_uuid();
            $db->execute("
                INSERT INTO inventory_balances (id, item_id, location_id, quantity_on_hand, available_qty, committed_qty, on_order_qty, average_cost, cost_price, selling_price, mrp, last_updated)
                VALUES (?, ?, ?, 0, 0, 0, 0, 0, ?, ?, ?, NOW())
            ", [$inv_id, $id, $lid, $c_price, $s_price, $m_price]);
        }
    }

    clear_dashboard_cache();
    ob_end_clean();

    // If request was standard form submit (non-AJAX)
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Location: ../index.php?page=master/item');
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => $message, 'id' => $id]);
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
