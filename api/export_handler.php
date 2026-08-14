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

// Define comprehensive column schemas including all fields
$columns = [];
$query = "";
$params = [];

switch ($type) {
    case 'items':
        $columns = [
            'id', 'sku', 'item_name', 'item_category', 'brand', 'unit_type', 'bottle_size_ml',
            'case_unit_name', 'units_per_case', 'barcode', 'case_barcode',
            'cost_price', 'case_purchase_price', 'selling_price', 'case_selling_price', 'mrp',
            'tax_id', 'tax_rate', 'reorder_level', 'reorder_qty', 'description', 'is_active',
            'inventory_account_id', 'inventory_account_code', 'inventory_account_name',
            'cogs_account_id', 'cogs_account_code', 'cogs_account_name',
            'income_account_id', 'income_account_code', 'income_account_name',
            'location_name', 'location_stock', 'location_cost_price', 'location_selling_price', 'location_mrp',
            'global_stock', 'created_at', 'updated_at'
        ];
        if (!$isTemplate) {
            $location_filter = $_GET['location_id'] ?? '';
            $loc_where = "";
            if (!empty($location_filter)) {
                $loc_where = " AND ib.location_id = " . $db->quote($location_filter) . " ";
            }
            $query = "SELECT 
                        i.id, 
                        i.sku, 
                        i.item_name, 
                        COALESCE(rc1.name, i.item_category, '') as item_category, 
                        i.brand, 
                        COALESCE(rc2.name, i.unit_type, '') as unit_type, 
                        i.bottle_size_ml, 
                        COALESCE(i.case_unit_name, 'CASE') as case_unit_name,
                        i.units_per_case, 
                        i.barcode,
                        i.case_barcode,
                        COALESCE(i.cost_price, 0) as cost_price,
                        i.case_purchase_price,
                        COALESCE(i.selling_price, 0) as selling_price,
                        i.case_selling_price,
                        COALESCE(i.mrp, 0) as mrp,
                        i.tax_id,
                        i.tax_rate, 
                        i.reorder_level, 
                        i.reorder_qty, 
                        i.description,
                        i.is_active,
                        i.inventory_account_id,
                        REPLACE(COALESCE(a3.id, ''), 'acc-', '') as inventory_account_code,
                        a3.account_name as inventory_account_name,
                        i.cogs_account_id,
                        REPLACE(COALESCE(a1.id, ''), 'acc-', '') as cogs_account_code, 
                        a1.account_name as cogs_account_name,
                        i.income_account_id,
                        REPLACE(COALESCE(a2.id, ''), 'acc-', '') as income_account_code, 
                        a2.account_name as income_account_name,
                        COALESCE(loc.name, 'All / Global') as location_name,
                        COALESCE(ib.quantity_on_hand, i.current_stock, 0) as location_stock,
                        COALESCE(ib.cost_price, ib.average_cost, i.cost_price, 0) as location_cost_price,
                        COALESCE(ib.selling_price, i.selling_price, 0) as location_selling_price,
                        COALESCE(ib.mrp, i.mrp, 0) as location_mrp,
                        COALESCE(i.current_stock, 0) as global_stock, 
                        i.created_at,
                        i.updated_at
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
        $columns = [
            'id', 'customer_code', 'full_name', 'customer_type', 'phone', 'email', 'pan_number',
            'credit_limit', 'payment_terms_days', 'location_id', 'location_name',
            'receivable_account_id', 'receivable_account_name', 'is_active', 'created_at', 'updated_at'
        ];
        if (!$isTemplate) {
            $query = "SELECT c.id, c.customer_code, c.full_name, c.customer_type, c.phone, c.email, c.pan_number,
                             c.credit_limit, c.payment_terms_days, c.location_id, loc.name as location_name,
                             c.receivable_account_id, acc.account_name as receivable_account_name, c.is_active, c.created_at, c.updated_at
                      FROM customers c
                      LEFT JOIN locations loc ON c.location_id = loc.id
                      LEFT JOIN accounts acc ON c.receivable_account_id = acc.id
                      WHERE c.is_deleted = 0 ORDER BY c.full_name ASC";
        }
        break;

    case 'vendors':
        $columns = [
            'id', 'vendor_code', 'company_name', 'contact_name', 'phone', 'email', 'address', 'description',
            'pan_number', 'vat_number', 'credit_limit', 'payment_terms_days', 'location_id', 'location_name',
            'payable_account_id', 'payable_account_name', 'is_active', 'created_at', 'updated_at'
        ];
        if (!$isTemplate) {
            $query = "SELECT v.id, v.vendor_code, v.company_name, v.contact_name, v.phone, v.email, v.address, v.description,
                             v.pan_number, v.vat_number, v.credit_limit, v.payment_terms_days, v.location_id, loc.name as location_name,
                             v.payable_account_id, acc.account_name as payable_account_name, v.is_active, v.created_at, v.updated_at
                      FROM vendors v
                      LEFT JOIN locations loc ON v.location_id = loc.id
                      LEFT JOIN accounts acc ON v.payable_account_id = acc.id
                      WHERE v.is_deleted = 0 ORDER BY v.company_name ASC";
        }
        break;

    case 'accounts':
        $columns = ['id', 'account_code', 'account_name', 'account_type', 'account_subtype', 'normal_balance', 'opening_balance', 'currency', 'description', 'is_active', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT id, REPLACE(id, 'acc-', '') as account_code, account_name, account_type, account_subtype, normal_balance, opening_balance, currency, description, is_active, created_at, updated_at FROM accounts WHERE is_deleted = 0 ORDER BY account_code ASC";
        }
        break;

    case 'vendor_bills':
        $columns = [
            'id', 'txn_number', 'bill_date', 'due_date', 'vendor_code', 'vendor_name', 'vendor_invoice_number',
            'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'amount_paid', 'balance_due', 'payment_status',
            'memo', 'location_name', 'created_by', 'created_at', 'updated_at'
        ];
        if (!$isTemplate) {
            $query = "SELECT h.id, h.txn_number, b.bill_date, b.due_date, v.vendor_code, v.company_name as vendor_name,
                             b.vendor_invoice_number, b.subtotal, b.discount_amount, b.tax_amount, b.total_amount, b.amount_paid, b.balance_due,
                             b.payment_status, h.memo, loc.name as location_name, h.created_by, h.created_at, h.updated_at
                      FROM transaction_headers h 
                      JOIN vendor_bills b ON h.id = b.header_id 
                      JOIN vendors v ON b.vendor_id = v.id 
                      LEFT JOIN locations loc ON h.location_id = loc.id
                      WHERE h.txn_type = 'vendor_bill' AND h.is_deleted = 0";
            if ($from) { $query .= " AND b.bill_date >= " . $db->quote($from); }
            if ($to) { $query .= " AND b.bill_date <= " . $db->quote($to); }
            $query .= " ORDER BY b.bill_date DESC";
        }
        break;

    case 'customer_invoices':
        $columns = [
            'id', 'txn_number', 'invoice_number', 'invoice_date', 'due_date', 'customer_code', 'customer_name', 'sale_type',
            'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'amount_paid', 'balance_due', 'payment_status',
            'memo', 'location_name', 'created_by', 'created_at', 'updated_at'
        ];
        if (!$isTemplate) {
            $query = "SELECT h.id, h.txn_number, ci.invoice_number, ci.invoice_date, ci.due_date, c.customer_code, c.full_name as customer_name,
                             ci.sale_type, ci.subtotal, ci.discount_amount, ci.tax_amount, ci.total_amount, ci.amount_paid, ci.balance_due,
                             ci.payment_status, h.memo, loc.name as location_name, h.created_by, h.created_at, h.updated_at
                      FROM transaction_headers h 
                      JOIN customer_invoices ci ON h.id = ci.header_id 
                      JOIN customers c ON ci.customer_id = c.id 
                      LEFT JOIN locations loc ON h.location_id = loc.id
                      WHERE h.txn_type = 'customer_invoice' AND h.is_deleted = 0";
            if ($from) { $query .= " AND ci.invoice_date >= " . $db->quote($from); }
            if ($to) { $query .= " AND ci.invoice_date <= " . $db->quote($to); }
            $query .= " ORDER BY ci.invoice_date DESC";
        }
        break;

    case 'journal_entries':
        $columns = ['id', 'txn_number', 'txn_date', 'memo', 'account_code', 'account_name', 'entry_type', 'amount', 'entry_memo', 'created_by', 'created_at'];
        if (!$isTemplate) {
            $query = "SELECT h.id, h.txn_number, h.txn_date, h.memo, REPLACE(a.id, 'acc-', '') as account_code, a.account_name, j.entry_type, j.amount, j.memo as entry_memo, h.created_by, h.created_at 
                      FROM transaction_headers h 
                      JOIN journal_entries j ON h.id = j.header_id 
                      JOIN accounts a ON j.account_id = a.id 
                      WHERE h.txn_type = 'journal_entry' AND h.is_deleted = 0";
            if ($from) { $query .= " AND h.txn_date >= " . $db->quote($from); }
            if ($to) { $query .= " AND h.txn_date <= " . $db->quote($to); }
            $query .= " ORDER BY h.txn_date DESC";
        }
        break;

    case 'expenses':
        $columns = ['id', 'txn_number', 'expense_date', 'expense_account_code', 'expense_account_name', 'paid_from_account_code', 'paid_from_account_name', 'vendor_code', 'vendor_name', 'description', 'amount', 'tax_amount', 'expense_category', 'created_by', 'created_at'];
        if (!$isTemplate) {
            $query = "SELECT h.id, h.txn_number, e.expense_date, REPLACE(a1.id, 'acc-', '') as expense_account_code, a1.account_name as expense_account_name, REPLACE(a2.id, 'acc-', '') as paid_from_account_code, a2.account_name as paid_from_account_name, v.vendor_code, v.company_name as vendor_name, e.description, e.amount, e.tax_amount, e.expense_category, h.created_by, h.created_at 
                      FROM transaction_headers h 
                      JOIN expenses e ON h.id = e.header_id 
                      JOIN accounts a1 ON e.expense_account_id = a1.id 
                      JOIN accounts a2 ON e.paid_from_account_id = a2.id 
                      LEFT JOIN vendors v ON e.vendor_id = v.id 
                      WHERE h.txn_type = 'expense' AND h.is_deleted = 0";
            if ($from) { $query .= " AND e.expense_date >= " . $db->quote($from); }
            if ($to) { $query .= " AND e.expense_date <= " . $db->quote($to); }
            $query .= " ORDER BY e.expense_date DESC";
        }
        break;

    case 'users':
        $columns = ['id', 'username', 'full_name', 'role', 'email', 'phone', 'is_active', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT id, username, full_name, role, email, phone, is_active, created_at, updated_at FROM users WHERE is_deleted = 0 ORDER BY full_name ASC";
        }
        break;

    case 'locations':
        $columns = ['id', 'name', 'address', 'phone', 'is_default', 'is_active', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT id, name, address, phone, is_default, is_active, created_at, updated_at FROM locations WHERE is_deleted = 0 ORDER BY name ASC";
        }
        break;

    case 'reference_codes':
        $columns = ['id', 'type', 'name', 'code', 'symbol', 'is_active', 'created_at'];
        if (!$isTemplate) {
            $query = "SELECT id, type, name, code, symbol, is_active, created_at FROM reference_codes ORDER BY type ASC, name ASC";
        }
        break;

    case 'roles':
        $columns = ['id', 'role_code', 'role_name', 'description', 'access_level', 'is_active', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT id, role_code, role_name, description, access_level, is_active, created_at, updated_at FROM roles ORDER BY role_name ASC";
        }
        break;

    case 'fiscal_years':
        $columns = ['id', 'name', 'start_date', 'end_date', 'status', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT id, name, start_date, end_date, status, created_at, updated_at FROM fiscal_years ORDER BY start_date DESC";
        }
        break;

    case 'pos_entry':
        $columns = ['id', 'invoice_no', 'date_time', 'customer_name', 'gross_amount', 'discount_type', 'discount_value', 'discount_amount', 'tax_amount', 'net_amount', 'status', 'location_name', 'created_by', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT p.id, p.invoice_no, p.date_time, c.full_name as customer_name, p.gross_amount, p.discount_type, p.discount_value, p.discount_amount, p.tax_amount, p.net_amount, p.status, loc.name as location_name, p.created_by, p.created_at, p.updated_at 
                      FROM pos_entry p
                      LEFT JOIN customers c ON p.customer_id = c.id
                      LEFT JOIN locations loc ON p.location_id = loc.id
                      WHERE p.is_deleted = 0";
            if ($from) { $query .= " AND DATE(p.date_time) >= " . $db->quote($from); }
            if ($to) { $query .= " AND DATE(p.date_time) <= " . $db->quote($to); }
            $query .= " ORDER BY p.date_time DESC";
        }
        break;

    case 'credit_memos':
        $columns = ['id', 'memo_number', 'memo_date', 'customer_code', 'customer_name', 'return_to_stock', 'subtotal', 'tax_amount', 'total_amount', 'remaining_credit', 'status', 'created_by', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT cm.id, cm.memo_number, cm.memo_date, c.customer_code, c.full_name as customer_name, cm.return_to_stock, cm.subtotal, cm.tax_amount, cm.total_amount, cm.remaining_credit, cm.status, cm.created_by, cm.created_at, cm.updated_at 
                      FROM credit_memos cm JOIN transaction_headers h ON cm.header_id = h.id 
                      JOIN customers c ON cm.customer_id = c.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND cm.memo_date >= " . $db->quote($from); }
            if ($to) { $query .= " AND cm.memo_date <= " . $db->quote($to); }
            $query .= " ORDER BY cm.memo_date DESC";
        }
        break;

    case 'vendor_credits':
        $columns = ['id', 'credit_number', 'credit_date', 'vendor_code', 'vendor_name', 'deduct_from_stock', 'subtotal', 'tax_amount', 'total_amount', 'remaining_credit', 'status', 'created_by', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT vc.id, vc.credit_number, vc.credit_date, v.vendor_code, v.company_name as vendor_name, vc.deduct_from_stock, vc.subtotal, vc.tax_amount, vc.total_amount, vc.remaining_credit, vc.status, vc.created_by, vc.created_at, vc.updated_at 
                      FROM vendor_credits vc JOIN transaction_headers h ON vc.header_id = h.id 
                      JOIN vendors v ON vc.vendor_id = v.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND vc.credit_date >= " . $db->quote($from); }
            if ($to) { $query .= " AND vc.credit_date <= " . $db->quote($to); }
            $query .= " ORDER BY vc.credit_date DESC";
        }
        break;

    case 'payments':
        $columns = ['id', 'txn_number', 'payment_date', 'payment_type', 'payment_method', 'amount', 'cheque_number', 'transaction_reference', 'created_by', 'created_at'];
        if (!$isTemplate) {
            $query = "SELECT p.id, h.txn_number, p.payment_date, p.payment_type, p.payment_method, p.amount, p.cheque_number, p.transaction_reference, p.created_by, p.created_at 
                      FROM payments p JOIN transaction_headers h ON p.header_id = h.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND p.payment_date >= " . $db->quote($from); }
            if ($to) { $query .= " AND p.payment_date <= " . $db->quote($to); }
            $query .= " ORDER BY p.payment_date DESC";
        }
        break;

    case 'inventory_transfers':
        $columns = ['id', 'transfer_number', 'transfer_date', 'from_location', 'to_location', 'total_qty', 'total_value', 'status', 'memo', 'created_by', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT it.id, it.transfer_number, it.transfer_date, l1.name as from_location, l2.name as to_location, it.total_qty, it.total_value, it.status, it.memo, it.created_by, it.created_at, it.updated_at 
                      FROM inventory_transfers it JOIN transaction_headers h ON it.header_id = h.id 
                      LEFT JOIN locations l1 ON it.from_location_id = l1.id 
                      LEFT JOIN locations l2 ON it.to_location_id = l2.id WHERE h.is_deleted = 0";
            if ($from) { $query .= " AND it.transfer_date >= " . $db->quote($from); }
            if ($to) { $query .= " AND it.transfer_date <= " . $db->quote($to); }
            $query .= " ORDER BY it.transfer_date DESC";
        }
        break;

    case 'activities':
        $columns = ['id', 'title', 'activity_type', 'status', 'priority', 'assigned_to', 'start_date', 'end_date', 'due_date', 'description', 'created_by', 'created_at', 'updated_at'];
        if (!$isTemplate) {
            $query = "SELECT id, title, activity_type, status, priority, assigned_to, start_date, end_date, due_date, description, created_by, created_at, updated_at FROM activities WHERE is_deleted = 0 ORDER BY activity_date DESC";
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
        elseif (strpos($col, 'price') !== false || strpos($col, 'amount') !== false || strpos($col, 'mrp') !== false || strpos($col, 'cash') !== false || strpos($col, 'limit') !== false || strpos($col, 'due') !== false || strpos($col, 'value') !== false) { $sample1[] = '1000.00'; $sample2[] = '2500.00'; }
        elseif (strpos($col, 'tax') !== false || strpos($col, 'rate') !== false || strpos($col, 'pct') !== false) { $sample1[] = '13.00'; $sample2[] = '13.00'; }
        elseif (strpos($col, 'qty') !== false || strpos($col, 'quantity') !== false || strpos($col, 'stock') !== false || strpos($col, 'level') !== false || strpos($col, 'units') !== false) { $sample1[] = '10'; $sample2[] = '50'; }
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
