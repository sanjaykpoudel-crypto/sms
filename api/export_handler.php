<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

require_once __DIR__ . '/../database/DBConnection.php';

$type = $_GET['type'] ?? '';
$isTemplate = isset($_GET['template']);
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

if (empty($type)) {
    die("Type is required.");
}

$db = db();
$filename = ($isTemplate ? "template_" : "export_") . $type . "_" . date('YmdHis') . ".csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Define columns for each type
$columns = [];
$query = "";
$params = [];

switch ($type) {
    case 'items':
        $columns = [
            'id', 'sku', 'item_name', 'item_category', 'brand', 'bottle_size_ml', 'unit_type', 'units_per_case', 
            'location_name', 'location_stock', 'location_cost_price', 'location_selling_price', 'location_mrp',
            'global_cost_price', 'global_selling_price', 'global_mrp', 'global_stock', 
            'tax_rate', 'reorder_level', 'reorder_qty', 'cogs_account_code', 'income_account_code', 'inventory_account_code'
        ];
        if (!$isTemplate) {
            $location_filter = $_GET['location_id'] ?? '';
            $loc_where = "";
            if (!empty($location_filter)) {
                $loc_where = " AND ib.location_id = " . $pdo->quote($location_filter) . " ";
            }
            $query = "SELECT 
                        i.id, 
                        i.sku, 
                        i.item_name, 
                        rc1.name as item_category, 
                        i.brand, 
                        i.bottle_size_ml, 
                        rc2.name as unit_type, 
                        i.units_per_case, 
                        COALESCE(loc.name, 'Default Location') as location_name,
                        COALESCE(ib.quantity_on_hand, i.current_stock, 0) as location_stock,
                        COALESCE(ib.cost_price, ib.average_cost, i.cost_price, 0) as location_cost_price,
                        COALESCE(ib.selling_price, i.selling_price, 0) as location_selling_price,
                        COALESCE(ib.mrp, i.mrp, 0) as location_mrp,
                        COALESCE(i.cost_price, 0) as global_cost_price, 
                        COALESCE(i.selling_price, 0) as global_selling_price, 
                        COALESCE(i.mrp, 0) as global_mrp, 
                        COALESCE(i.current_stock, 0) as global_stock, 
                        i.tax_rate, 
                        i.reorder_level, 
                        i.reorder_qty, 
                        REPLACE(COALESCE(a1.id, ''), 'acc-', '') as cogs_account_code, 
                        REPLACE(COALESCE(a2.id, ''), 'acc-', '') as income_account_code, 
                        REPLACE(COALESCE(a3.id, ''), 'acc-', '') as inventory_account_code 
                      FROM items i
                      LEFT JOIN inventory_balances ib ON i.id = ib.item_id {$loc_where}
                      LEFT JOIN locations loc ON ib.location_id = loc.id AND loc.is_deleted = 0
                      LEFT JOIN accounts a1 ON i.cogs_account_id = a1.id
                      LEFT JOIN accounts a2 ON i.income_account_id = a2.id
                      LEFT JOIN accounts a3 ON i.inventory_account_id = a3.id
                      LEFT JOIN reference_codes rc1 ON i.item_category = rc1.id AND rc1.type = 'category'
                      LEFT JOIN reference_codes rc2 ON i.unit_type = rc2.id AND rc2.type IN ('unit', 'units')
                      WHERE i.is_deleted = 0
                      ORDER BY i.item_name ASC, loc.name ASC";
        }
        break;

    case 'customers':
        $columns = ['id', 'customer_code', 'full_name', 'customer_type', 'phone', 'email', 'pan_number', 'credit_limit', 'payment_terms_days'];
        if (!$isTemplate) {
            $query = "SELECT id, customer_code, full_name, customer_type, phone, email, pan_number, credit_limit, payment_terms_days FROM customers WHERE is_deleted = 0 ORDER BY full_name ASC";
        }
        break;

    case 'vendors':
        $columns = ['id', 'vendor_code', 'company_name', 'contact_name', 'phone', 'email', 'address', 'pan_number', 'vat_number', 'payment_terms_days', 'credit_limit'];
        if (!$isTemplate) {
            $query = "SELECT id, vendor_code, company_name, contact_name, phone, email, address, pan_number, vat_number, payment_terms_days, credit_limit FROM vendors WHERE is_deleted = 0 ORDER BY company_name ASC";
        }
        break;

    case 'accounts':
        $columns = ['id', 'account_code', 'account_name', 'account_type', 'account_subtype', 'normal_balance', 'currency'];
        if (!$isTemplate) {
            $query = "SELECT id, REPLACE(id, 'acc-', '') as account_code, account_name, account_type, account_subtype, normal_balance, currency FROM accounts WHERE is_deleted = 0 ORDER BY account_code ASC";
        }
        break;

    case 'vendor_bills':
        $columns = ['txn_number', 'bill_date', 'due_date', 'vendor_code', 'vendor_invoice_number', 'memo', 'item_sku', 'description', 'quantity', 'unit_price', 'discount_pct', 'tax_rate'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, b.bill_date, b.due_date, v.vendor_code, b.vendor_invoice_number, h.memo, i.sku as item_sku, l.description, l.quantity, l.unit_price, l.discount_pct, l.tax_rate 
                      FROM transaction_headers h 
                      JOIN vendor_bills b ON h.id = b.header_id 
                      JOIN vendors v ON b.vendor_id = v.id 
                      JOIN transaction_lines l ON h.id = l.header_id 
                      LEFT JOIN items i ON l.item_id = i.id 
                      WHERE h.txn_type = 'vendor_bill' AND h.is_deleted = 0";
            if ($from) { $query .= " AND b.bill_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND b.bill_date <= ?"; $params[] = $to; }
        }
        break;

    case 'customer_invoices':
        $columns = ['txn_number', 'invoice_date', 'due_date', 'customer_code', 'invoice_number', 'memo', 'item_sku', 'description', 'quantity', 'unit_price', 'discount_pct', 'tax_rate', 'sale_type'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, ci.invoice_date, ci.due_date, c.customer_code, ci.invoice_number, h.memo, i.sku as item_sku, l.description, l.quantity, l.unit_price, l.discount_pct, l.tax_rate, ci.sale_type 
                      FROM transaction_headers h 
                      JOIN customer_invoices ci ON h.id = ci.header_id 
                      JOIN customers c ON ci.customer_id = c.id 
                      JOIN transaction_lines l ON h.id = l.header_id 
                      LEFT JOIN items i ON l.item_id = i.id 
                      WHERE h.txn_type = 'customer_invoice' AND h.is_deleted = 0";
            if ($from) { $query .= " AND ci.invoice_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND ci.invoice_date <= ?"; $params[] = $to; }
        }
        break;

    case 'journal_entries':
        $columns = ['txn_number', 'txn_date', 'memo', 'account_code', 'entry_type', 'amount', 'entry_memo'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, h.txn_date, h.memo, REPLACE(a.id, 'acc-', '') as account_code, j.entry_type, j.amount, j.memo as entry_memo 
                      FROM transaction_headers h 
                      JOIN journal_entries j ON h.id = j.header_id 
                      JOIN accounts a ON j.account_id = a.id 
                      WHERE h.txn_type = 'journal_entry' AND h.is_deleted = 0";
            if ($from) { $query .= " AND h.txn_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND h.txn_date <= ?"; $params[] = $to; }
        }
        break;

    case 'expenses':
        $columns = ['txn_number', 'expense_date', 'expense_account_code', 'paid_from_account_code', 'vendor_code', 'description', 'amount', 'tax_amount', 'expense_category'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, e.expense_date, REPLACE(a1.id, 'acc-', '') as expense_account_code, REPLACE(a2.id, 'acc-', '') as paid_from_account_code, v.vendor_code, e.description, e.amount, e.tax_amount, e.expense_category 
                      FROM transaction_headers h 
                      JOIN expenses e ON h.id = e.header_id 
                      JOIN accounts a1 ON e.expense_account_id = a1.id 
                      JOIN accounts a2 ON e.paid_from_account_id = a2.id 
                      LEFT JOIN vendors v ON e.vendor_id = v.id 
                      WHERE h.txn_type = 'expense' AND h.is_deleted = 0";
            if ($from) { $query .= " AND e.expense_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND e.expense_date <= ?"; $params[] = $to; }
        }
        break;

    case 'users':
        $columns = ['id', 'username', 'full_name', 'role', 'email', 'phone', 'is_active'];
        if (!$isTemplate) {
            $query = "SELECT id, username, full_name, role, email, phone, is_active FROM users WHERE is_deleted = 0 ORDER BY full_name ASC";
        }
        break;

    case 'locations':
        $columns = ['id', 'name', 'address', 'phone', 'is_default', 'is_active'];
        if (!$isTemplate) {
            $query = "SELECT id, name, address, phone, is_default, is_active FROM locations WHERE is_deleted = 0 ORDER BY name ASC";
        }
        break;

    case 'reference_codes':
        $columns = ['id', 'type', 'name', 'code', 'symbol', 'is_active'];
        if (!$isTemplate) {
            $query = "SELECT id, type, name, code, symbol, is_active FROM reference_codes ORDER BY type ASC, name ASC";
        }
        break;

    case 'roles':
        $columns = ['id', 'role_code', 'role_name', 'description', 'access_level', 'is_active'];
        if (!$isTemplate) {
            $query = "SELECT id, role_code, role_name, description, access_level, is_active FROM roles ORDER BY role_name ASC";
        }
        break;

    case 'fiscal_years':
        $columns = ['id', 'name', 'start_date', 'end_date', 'status'];
        if (!$isTemplate) {
            $query = "SELECT id, name, start_date, end_date, status FROM fiscal_years ORDER BY start_date DESC";
        }
        break;

    case 'pos_entry':
        $columns = ['invoice_no', 'date_time', 'net_amount', 'discount_amount', 'tax_amount', 'status'];
        if (!$isTemplate) {
            $query = "SELECT invoice_no, date_time, net_amount, discount_amount, tax_amount, status FROM pos_entry WHERE is_deleted = 0";
            if ($from) { $query .= " AND DATE(date_time) >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND DATE(date_time) <= ?"; $params[] = $to; }
        }
        break;

    case 'credit_memos':
        $columns = ['txn_number', 'memo_date', 'customer_code', 'total_amount', 'balance_due', 'reason'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, cm.memo_date, c.customer_code, cm.total_amount, cm.balance_due, cm.reason 
                      FROM credit_memos cm JOIN transaction_headers h ON cm.header_id = h.id 
                      JOIN customers c ON cm.customer_id = c.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND cm.memo_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND cm.memo_date <= ?"; $params[] = $to; }
        }
        break;

    case 'vendor_credits':
        $columns = ['txn_number', 'credit_date', 'vendor_code', 'total_amount', 'balance_due', 'reason'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, vc.credit_date, v.vendor_code, vc.total_amount, vc.balance_due, vc.reason 
                      FROM vendor_credits vc JOIN transaction_headers h ON vc.header_id = h.id 
                      JOIN vendors v ON vc.vendor_id = v.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND vc.credit_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND vc.credit_date <= ?"; $params[] = $to; }
        }
        break;

    case 'payments':
        $columns = ['txn_number', 'payment_date', 'payment_type', 'amount', 'payment_mode', 'reference_no'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, p.payment_date, p.payment_type, p.amount, p.payment_mode, p.reference_no 
                      FROM payments p JOIN transaction_headers h ON p.header_id = h.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND p.payment_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND p.payment_date <= ?"; $params[] = $to; }
        }
        break;

    case 'account_transfers':
        $columns = ['txn_number', 'transfer_date', 'from_account_code', 'to_account_code', 'amount', 'reference_no', 'memo'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, t.transfer_date, REPLACE(a1.id, 'acc-', '') as from_account_code, REPLACE(a2.id, 'acc-', '') as to_account_code, t.amount, t.reference_no, h.memo 
                      FROM account_transfers t JOIN transaction_headers h ON t.header_id = h.id 
                      JOIN accounts a1 ON t.from_account_id = a1.id 
                      JOIN accounts a2 ON t.to_account_id = a2.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND t.transfer_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND t.transfer_date <= ?"; $params[] = $to; }
        }
        break;

    case 'cash_denominations':
        $columns = ['txn_number', 'denomination_date', 'denomination_type', 'total_cash', 'difference', 'notes'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, cd.denomination_date, cd.denomination_type, cd.total_cash, cd.difference, cd.notes 
                      FROM cash_denominations cd JOIN transaction_headers h ON cd.header_id = h.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND cd.denomination_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND cd.denomination_date <= ?"; $params[] = $to; }
        }
        break;

    case 'inventory_transfers':
        $columns = ['txn_number', 'transfer_date', 'from_location', 'to_location', 'memo'];
        if (!$isTemplate) {
            $query = "SELECT h.txn_number, it.transfer_date, l1.name as from_location, l2.name as to_location, h.memo 
                      FROM inventory_transfers it JOIN transaction_headers h ON it.header_id = h.id 
                      LEFT JOIN locations l1 ON it.from_location_id = l1.id 
                      LEFT JOIN locations l2 ON it.to_location_id = l2.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND it.transfer_date >= ?"; $params[] = $from; }
            if ($to) { $query .= " AND it.transfer_date <= ?"; $params[] = $to; }
        }
        break;

    case 'activities':
        $columns = ['id', 'title', 'description', 'activity_date', 'priority', 'status'];
        if (!$isTemplate) {
            $query = "SELECT id, title, description, activity_date, priority, status FROM activities ORDER BY activity_date DESC";
        }
        break;

    default:
        die("Unsupported type: " . $type);
}

// Write header
fputcsv($output, $columns);

// Write 2 sample rows for template downloads matching all columns
if ($isTemplate) {
    $sample1 = [];
    $sample2 = [];
    foreach ($columns as $col) {
        if ($col === 'id') { $sample1[] = 'UUID-0001'; $sample2[] = 'UUID-0002'; }
        elseif (strpos($col, 'code') !== false) { $sample1[] = 'REF-001'; $sample2[] = 'REF-002'; }
        elseif (strpos($col, 'date') !== false || strpos($col, 'time') !== false) { $sample1[] = date('Y-m-d'); $sample2[] = date('Y-m-d'); }
        elseif (strpos($col, 'name') !== false) { $sample1[] = 'Sample Name 1'; $sample2[] = 'Sample Name 2'; }
        elseif (strpos($col, 'phone') !== false) { $sample1[] = '9800000001'; $sample2[] = '9800000002'; }
        elseif (strpos($col, 'email') !== false) { $sample1[] = 'info@example.com'; $sample2[] = 'sales@example.com'; }
        elseif (strpos($col, 'price') !== false || strpos($col, 'amount') !== false || strpos($col, 'mrp') !== false || strpos($col, 'cash') !== false || strpos($col, 'limit') !== false || strpos($col, 'due') !== false) { $sample1[] = '1000.00'; $sample2[] = '2500.00'; }
        elseif (strpos($col, 'tax') !== false || strpos($col, 'rate') !== false || strpos($col, 'pct') !== false) { $sample1[] = '13.00'; $sample2[] = '13.00'; }
        elseif (strpos($col, 'qty') !== false || strpos($col, 'quantity') !== false || strpos($col, 'stock') !== false || strpos($col, 'level') !== false) { $sample1[] = '10'; $sample2[] = '50'; }
        elseif ($col === 'status') { $sample1[] = 'active'; $sample2[] = 'active'; }
        elseif ($col === 'is_active' || $col === 'is_default') { $sample1[] = '1'; $sample2[] = '1'; }
        else { $sample1[] = 'Sample Data'; $sample2[] = 'Sample Data'; }
    }
    fputcsv($output, $sample1);
    fputcsv($output, $sample2);
}

// Write actual data rows when exporting (not template)
if (!$isTemplate && !empty($query)) {
    $rows = $db->fetchAll($query, $params);
    foreach ($rows as $row) {
        $data = [];
        foreach ($columns as $col) {
            $data[] = $row[$col] ?? '';
        }
        fputcsv($output, $data);
    }
}

fclose($output);
exit;

