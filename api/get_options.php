<?php
/**
 * Dedicated API Endpoint for Fetching Dynamic Dropdown Options with Performance Benchmarking
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

$t_start = microtime(true);
$type = $_GET['type'] ?? 'bank_account';
$db = db();

$items = [];
switch ($type) {
    case 'location':
    case 'from_location':
    case 'to_location':
        foreach (get_active_locations() as $loc) {
            $items[] = [
                'id' => $loc['id'],
                'label' => $loc['name'] . (!empty($loc['is_default']) ? ' (Default)' : '')
            ];
        }
        break;

    case 'bank_account':
    case 'from_account':
    case 'to_account':
        $raw = $db->fetchAll("SELECT id, account_name FROM accounts WHERE account_subtype = 'Bank' AND is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
        foreach ($raw as $r) {
            $items[] = ['id' => $r['id'], 'label' => $r['account_name']];
        }
        break;

    case 'account':
        $raw = $db->fetchAll("SELECT id, account_name FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
        foreach ($raw as $r) {
            $items[] = ['id' => $r['id'], 'label' => $r['account_name']];
        }
        break;

    case 'customer':
        $raw = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
        foreach ($raw as $r) {
            $items[] = ['id' => $r['id'], 'label' => $r['full_name']];
        }
        break;

    case 'vendor':
        $raw = $db->fetchAll("SELECT id, company_name FROM vendors WHERE is_active = 1 AND is_deleted = 0 ORDER BY company_name ASC");
        foreach ($raw as $r) {
            $items[] = ['id' => $r['id'], 'label' => $r['company_name']];
        }
        break;

    case 'item':
        $raw = $db->fetchAll("SELECT id, item_name, sku FROM items WHERE is_active = 1 AND is_deleted = 0 ORDER BY item_name ASC");
        foreach ($raw as $r) {
            $items[] = ['id' => $r['id'], 'label' => $r['item_name'] . ($r['sku'] ? ' (' . $r['sku'] . ')' : '')];
        }
        break;
}

$query_time_ms = round((microtime(true) - $t_start) * 1000, 3);

ob_end_clean();
echo json_encode([
    'status' => 'success',
    'type' => $type,
    'query_time_ms' => $query_time_ms,
    'count' => count($items),
    'data' => $items
]);
exit;
