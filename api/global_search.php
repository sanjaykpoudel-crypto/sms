<?php
/**
 * api/global_search.php
 * Universal Omnibox Search Engine for MNS Liquor ERP.
 * Fast multi-entity search across Items, Customers, Vendors, Invoices, Bills, Payments, and Accounts.
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo json_encode(['status' => 'success', 'results' => []]);
    exit;
}

$db = db();
$like = '%' . $query . '%';

try {
    $results = [];

    // 1. Items Search
    $items = $db->fetchAll("
        SELECT id, item_name as title, sku as subtitle, selling_price, current_stock, 'item' as category 
        FROM items 
        WHERE (item_name LIKE ? OR sku LIKE ?) AND is_deleted = 0 
        LIMIT 5
    ", [$like, $like]);
    foreach ($items as $i) {
        $results[] = [
            'category' => 'Item',
            'title' => $i['title'],
            'subtitle' => 'SKU: ' . $i['subtitle'] . ' | Stock: ' . $i['current_stock'] . ' | Price: Rs ' . number_format($i['selling_price'], 2),
            'url' => 'index.php?page=master/item/manage&id=' . urlencode($i['id'])
        ];
    }

    // 2. Customers Search
    $customers = $db->fetchAll("
        SELECT id, full_name as title, phone as subtitle, 'customer' as category 
        FROM customers 
        WHERE (full_name LIKE ? OR phone LIKE ? OR customer_code LIKE ?) AND is_deleted = 0 
        LIMIT 5
    ", [$like, $like, $like]);
    foreach ($customers as $c) {
        $results[] = [
            'category' => 'Customer',
            'title' => $c['title'],
            'subtitle' => 'Phone: ' . ($c['subtitle'] ?: 'N/A'),
            'url' => 'index.php?page=master/customer/manage&id=' . urlencode($c['id'])
        ];
    }

    // 3. Vendors Search
    $vendors = $db->fetchAll("
        SELECT id, company_name as title, phone as subtitle, 'vendor' as category 
        FROM vendors 
        WHERE (company_name LIKE ? OR phone LIKE ? OR vendor_code LIKE ?) AND is_deleted = 0 
        LIMIT 5
    ", [$like, $like, $like]);
    foreach ($vendors as $v) {
        $results[] = [
            'category' => 'Vendor',
            'title' => $v['title'],
            'subtitle' => 'Phone: ' . ($v['subtitle'] ?: 'N/A'),
            'url' => 'index.php?page=master/vendor/manage&id=' . urlencode($v['id'])
        ];
    }

    // 4. Transactions Search (Invoices, Bills, POS, Payments, Journals)
    $txns = $db->fetchAll("
        SELECT id, txn_number as title, txn_type, txn_date, status
        FROM transaction_headers 
        WHERE (txn_number LIKE ? OR reference_number LIKE ?) AND is_deleted = 0 
        ORDER BY txn_date DESC 
        LIMIT 8
    ", [$like, $like]);
    foreach ($txns as $t) {
        $typeLabel = ucwords(str_replace('_', ' ', $t['txn_type']));
        $results[] = [
            'category' => $typeLabel,
            'title' => $t['title'],
            'subtitle' => 'Date: ' . $t['txn_date'] . ' | Status: ' . ucfirst($t['status']),
            'url' => 'index.php?page=transactions/view&id=' . urlencode($t['id'])
        ];
    }

    // 5. Chart of Accounts Search
    $accounts = $db->fetchAll("
        SELECT id, account_name as title, normal_balance, 'account' as category 
        FROM accounts 
        WHERE account_name LIKE ? AND is_deleted = 0 
        LIMIT 4
    ", [$like]);
    foreach ($accounts as $a) {
        $results[] = [
            'category' => 'Account',
            'title' => $a['title'],
            'subtitle' => 'Type: ' . ucfirst($a['normal_balance']),
            'url' => 'index.php?page=master/account/manage&id=' . urlencode($a['id'])
        ];
    }

    echo json_encode(['status' => 'success', 'results' => $results]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
