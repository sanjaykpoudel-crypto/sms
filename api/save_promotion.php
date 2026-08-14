<?php
/**
 * api/save_promotion.php
 * API Endpoint for handling Promotion creation, updates, status toggling, duplication, and item search.
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

header('Content-Type: application/json');
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';
require_once __DIR__ . '/PromotionEngine.php';

$db  = db();
$pdo = $db->getConnection();

$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true);
if (is_array($json_data)) {
    $_POST = array_merge($_POST, $json_data);
    $_REQUEST = array_merge($_REQUEST, $json_data);
}

$action = $_REQUEST['action'] ?? ($_POST['action'] ?? ($_GET['action'] ?? 'save'));
$id     = (int)($_REQUEST['id'] ?? ($_POST['id'] ?? ($_GET['id'] ?? 0)));

try {
    // ----------------------------------------------------
    // Action: Search Items for Promotion Form Grid
    // ----------------------------------------------------
    if ($action === 'search_items') {
        $q = trim($_GET['q'] ?? '');
        $loc_id = $_GET['location_id'] ?? get_user_default_location_id();

        $where = "i.is_active = 1 AND i.is_deleted = 0";
        $params = [$loc_id];
        if (!empty($q)) {
            $where .= " AND (i.item_name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $items = $db->fetchAll("
            SELECT 
                i.id, i.sku, i.item_name, r.name as category_name,
                CAST(COALESCE(i.mrp, 0) AS DECIMAL(12,2)) as mrp,
                CAST(COALESCE(ib.selling_price, i.selling_price) AS DECIMAL(12,2)) as selling_price,
                CAST(COALESCE(ib.quantity_on_hand, 0) AS DECIMAL(12,2)) as current_stock
            FROM items i
            LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category'
            LEFT JOIN inventory_balances ib ON ib.item_id = i.id AND ib.location_id = ?
            WHERE {$where}
            ORDER BY i.item_name ASC
            LIMIT 50
        ", $params);

        echo json_encode(['status' => 'success', 'items' => $items]);
        exit;
    }

    // ----------------------------------------------------
    // Action: Toggle Status (Active / Inactive)
    // ----------------------------------------------------
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $new_status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        if ($id <= 0) throw new Exception("Invalid promotion ID.");

        $db->execute("UPDATE promotions SET status = ?, updated_by = ? WHERE id = ?", [
            $new_status, $_SESSION['user_id'], $id
        ]);

        echo json_encode(['status' => 'success', 'message' => "Promotion status updated to " . ucfirst($new_status)]);
        exit;
    }

    // ----------------------------------------------------
    // Action: Soft Delete Promotion
    // ----------------------------------------------------
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception("Invalid promotion ID.");

        $db->execute("UPDATE promotions SET is_deleted = 1, updated_by = ? WHERE id = ?", [
            $_SESSION['user_id'], $id
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Promotion deleted successfully.']);
        exit;
    }

    // ----------------------------------------------------
    // Action: Duplicate Promotion
    // ----------------------------------------------------
    if ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception("Invalid promotion ID.");

        $promo = $db->fetchOne("SELECT * FROM promotions WHERE id = ? AND is_deleted = 0", [$id]);
        if (!$promo) throw new Exception("Promotion not found.");

        $new_code = $promo['promo_code'] . '-COPY-' . rand(100, 999);
        $new_name = $promo['name'] . ' (Copy)';

        $pdo->beginTransaction();
        $db->execute("
            INSERT INTO promotions (promo_code, name, description, status, start_datetime, end_datetime, discount_basis, discount_type, discount_value, applies_to_locations, min_qty, max_qty, priority, is_stackable, created_by)
            VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            $new_code, $new_name, $promo['description'], $promo['start_datetime'], $promo['end_datetime'],
            $promo['discount_basis'], $promo['discount_type'], $promo['discount_value'],
            $promo['applies_to_locations'], $promo['min_qty'], $promo['max_qty'],
            $promo['priority'], $promo['is_stackable'], $_SESSION['user_id']
        ]);
        $new_id = $pdo->lastInsertId();

        // Copy item mappings
        $db->execute("
            INSERT INTO promotion_items (promotion_id, item_id, override_discount_type, override_discount_value)
            SELECT ?, item_id, override_discount_type, override_discount_value FROM promotion_items WHERE promotion_id = ?
        ", [$new_id, $id]);

        // Copy location mappings
        $db->execute("
            INSERT INTO promotion_locations (promotion_id, location_id)
            SELECT ?, location_id FROM promotion_locations WHERE promotion_id = ?
        ", [$new_id, $id]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Promotion duplicated successfully as draft.', 'id' => $new_id]);
        exit;
    }

    // ----------------------------------------------------
    // Action: Save / Update Promotion
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    // Handle JSON or FormData payload
    $raw_input = file_get_contents('php://input');
    $json_data = json_decode($raw_input, true);
    if (is_array($json_data)) {
        $_POST = array_merge($_POST, $json_data);
    }

    $id                  = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $promo_code          = trim($_POST['promo_code'] ?? '');
    $name                = trim($_POST['name'] ?? '');
    $description         = trim($_POST['description'] ?? '');
    $status              = $_POST['status'] ?? 'active';
    $start_date          = $_POST['start_date'] ?? date('Y-m-d');
    $start_time          = $_POST['start_time'] ?? '00:00:00';
    $end_date            = $_POST['end_date'] ?? date('Y-m-d', strtotime('+30 days'));
    $end_time            = $_POST['end_time'] ?? '23:59:59';
    $discount_basis      = $_POST['discount_basis'] ?? 'mrp';
    $discount_type       = $_POST['discount_type'] ?? 'percentage';
    $discount_value      = (float)($_POST['discount_value'] ?? 0);
    $applies_locations   = $_POST['applies_to_locations'] ?? 'all';
    $min_qty             = (float)($_POST['min_qty'] ?? 1);
    $max_qty             = !empty($_POST['max_qty']) ? (float)$_POST['max_qty'] : null;
    $priority            = (int)($_POST['priority'] ?? 1);
    $is_stackable        = !empty($_POST['is_stackable']) ? 1 : 0;
    $selected_items      = $_POST['items'] ?? [];
    $selected_locations  = $_POST['location_ids'] ?? [];

    if (empty($name)) throw new Exception("Promotion Name is required.");
    if (empty($promo_code)) {
        $promo_code = 'PROMO-' . strtoupper(substr(md5(uniqid()), 0, 6));
    }

    // Check code uniqueness
    $dup = $db->fetchOne("SELECT id FROM promotions WHERE promo_code = ? AND id != ? AND is_deleted = 0", [$promo_code, $id ?: 0]);
    if ($dup) throw new Exception("Promotion Code '{$promo_code}' is already in use.");

    $start_datetime = date('Y-m-d H:i:s', strtotime($start_date . ' ' . $start_time));
    $end_datetime   = date('Y-m-d H:i:s', strtotime($end_date . ' ' . $end_time));

    if (strtotime($end_datetime) <= strtotime($start_datetime)) {
        throw new Exception("End Date/Time must be after Start Date/Time.");
    }
    if ($discount_value <= 0) {
        throw new Exception("Discount Value must be greater than zero.");
    }
    if ($discount_type === 'percentage' && $discount_value > 100) {
        throw new Exception("Percentage discount cannot exceed 100%.");
    }
    if (empty($selected_items)) {
        throw new Exception("Please select at least one item for this promotion.");
    }

    $pdo->beginTransaction();

    if ($id) {
        $db->execute("
            UPDATE promotions SET
                promo_code = ?, name = ?, description = ?, status = ?,
                start_datetime = ?, end_datetime = ?, discount_basis = ?, discount_type = ?,
                discount_value = ?, applies_to_locations = ?, min_qty = ?, max_qty = ?,
                priority = ?, is_stackable = ?, updated_by = ?
            WHERE id = ?
        ", [
            $promo_code, $name, $description, $status,
            $start_datetime, $end_datetime, $discount_basis, $discount_type,
            $discount_value, $applies_locations, $min_qty, $max_qty,
            $priority, $is_stackable, $_SESSION['user_id'], $id
        ]);
        
        $db->execute("DELETE FROM promotion_items WHERE promotion_id = ?", [$id]);
        $db->execute("DELETE FROM promotion_locations WHERE promotion_id = ?", [$id]);
    } else {
        $db->execute("
            INSERT INTO promotions (
                promo_code, name, description, status, start_datetime, end_datetime,
                discount_basis, discount_type, discount_value, applies_to_locations,
                min_qty, max_qty, priority, is_stackable, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            $promo_code, $name, $description, $status, $start_datetime, $end_datetime,
            $discount_basis, $discount_type, $discount_value, $applies_locations,
            $min_qty, $max_qty, $priority, $is_stackable, $_SESSION['user_id']
        ]);
        $id = $pdo->lastInsertId();
    }

    // Insert Covered Items
    $stmtItemIns = $pdo->prepare("
        INSERT INTO promotion_items (promotion_id, item_id, override_discount_type, override_discount_value)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($selected_items as $item_entry) {
        $item_id = is_array($item_entry) ? ($item_entry['item_id'] ?? $item_entry['id']) : $item_entry;
        if (empty($item_id)) continue;

        $over_type = is_array($item_entry) ? ($item_entry['override_type'] ?? null) : null;
        $over_val  = is_array($item_entry) && isset($item_entry['override_val']) ? (float)$item_entry['override_val'] : null;

        $stmtItemIns->execute([$id, $item_id, $over_type, $over_val]);
    }

    // Insert Selected Locations (if not applies to all)
    if ($applies_locations === 'selected' && !empty($selected_locations)) {
        $stmtLocIns = $pdo->prepare("INSERT INTO promotion_locations (promotion_id, location_id) VALUES (?, ?)");
        foreach ($selected_locations as $loc_id) {
            if (!empty($loc_id)) {
                $stmtLocIns->execute([$id, $loc_id]);
            }
        }
    }

    $pdo->commit();
    clear_dashboard_cache();

    echo json_encode([
        'status' => 'success',
        'message' => 'Promotion saved successfully.',
        'id' => $id,
        'promo_code' => $promo_code
    ]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
