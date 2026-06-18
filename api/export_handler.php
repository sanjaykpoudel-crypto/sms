<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

require_once '../database/DBConnection.php';

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
        $columns = ['sku', 'item_name', 'item_category', 'brand', 'bottle_size_ml', 'unit_type', 'units_per_case', 'cost_price', 'selling_price', 'tax_rate', 'reorder_level', 'reorder_qty', 'cogs_account_code', 'income_account_code', 'inventory_account_code'];
        if (!$isTemplate) {
            $query = "SELECT i.sku, i.item_name, rc1.name as item_category, i.brand, i.bottle_size_ml, rc2.name as unit_type, i.units_per_case, i.cost_price, i.selling_price, i.tax_rate, i.reorder_level, i.reorder_qty, a1.account_code as cogs_account_code, a2.account_code as income_account_code, a3.account_code as inventory_account_code 
                      FROM items i
                      LEFT JOIN accounts a1 ON i.cogs_account_id = a1.id
                      LEFT JOIN accounts a2 ON i.income_account_id = a2.id
                      LEFT JOIN accounts a3 ON i.inventory_account_id = a3.id
                      LEFT JOIN reference_codes rc1 ON i.item_category = rc1.id AND rc1.type = 'category'
                      LEFT JOIN reference_codes rc2 ON i.unit_type = rc2.id AND rc2.type IN ('unit', 'units')
                      WHERE i.is_deleted = 0";
        }
        break;

    case 'customers':
        $columns = ['customer_code', 'full_name', 'customer_type', 'phone', 'email', 'pan_number', 'credit_limit', 'payment_terms_days'];
        if (!$isTemplate) {
            $query = "SELECT " . implode(', ', $columns) . " FROM customers WHERE is_deleted = 0";
        }
        break;

    case 'vendors':
        $columns = ['vendor_code', 'company_name', 'contact_name', 'phone', 'email', 'address', 'pan_number', 'vat_number', 'payment_terms_days', 'credit_limit'];
        if (!$isTemplate) {
            $query = "SELECT " . implode(', ', $columns) . " FROM vendors WHERE is_deleted = 0";
        }
        break;

    case 'accounts':
        $columns = ['account_code', 'account_name', 'account_type', 'account_subtype', 'normal_balance', 'currency'];
        if (!$isTemplate) {
            $query = "SELECT " . implode(', ', $columns) . " FROM accounts WHERE is_deleted = 0";
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
            $query = "SELECT h.txn_number, h.txn_date, h.memo, a.account_code, j.entry_type, j.amount, j.memo as entry_memo 
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
            $query = "SELECT h.txn_number, e.expense_date, a1.account_code as expense_account_code, a2.account_code as paid_from_account_code, v.vendor_code, e.description, e.amount, e.tax_amount, e.expense_category 
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

    default:
        die("Unsupported type: " . $type);
}

// Write header
fputcsv($output, $columns);

// Write 2 sample rows for template downloads so users understand the format
if ($isTemplate) {
    $sampleRows = [];
    switch ($type) {
        case 'items':
            $sampleRows = [
                ['JD-001', 'Jack Daniels Whisky', 'spirits', 'Jack Daniels', '750', 'bottle', '12', '3500', '5000', '13', '10', '24', '5100', '4100', '1200'],
                ['CB-001', 'Carlsberg Premium Lager', 'beer', 'Carlsberg', '650', 'bottle', '12', '250', '350', '13', '50', '100', '5110', '4110', '1200'],
            ];
            break;
        case 'customers':
            $sampleRows = [
                ['C-001', 'Yeti Lounge Bar', 'bar', '9851000001', 'accounts@yetilounge.com', '600000001', '100000', '30'],
                ['C-002', 'Everest View Hotel', 'hotel', '9851000002', 'purchase@everestview.com', '600000002', '300000', '45'],
            ];
            break;
        case 'vendors':
            $sampleRows = [
                ['V-001', 'Global Spirits Distributors', 'Rajesh Sharma', '9841000001', 'info@globalspirits.com', 'Lazimpat Kathmandu', '300000001', '', '30', '500000'],
                ['V-002', 'Himalayan Breweries Pvt Ltd', 'Sita Thapa', '9841000002', 'sales@himalayanbrew.com', 'Pokhara Nepal', '300000002', '', '45', '200000'],
            ];
            break;
        case 'accounts':
            $sampleRows = [
                ['7100', 'Staff Welfare Expense', 'expense', 'other', 'debit', 'NPR'],
                ['4200', 'Other Operating Income', 'income', 'other', 'credit', 'NPR'],
            ];
            break;
        case 'vendor_bills':
            $sampleRows = [
                ['BILL-0001', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), 'V-001', 'INV-2026-001', 'Monthly stock purchase', 'JD-001', 'Jack Daniels 750ml x 12', '2', '3500', '0', '13'],
                ['BILL-0001', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), 'V-001', 'INV-2026-001', 'Monthly stock purchase', 'CB-001', 'Carlsberg 650ml x 24', '5', '250', '0', '13'],
            ];
            break;
        case 'customer_invoices':
            $sampleRows = [
                ['INV-0001', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), 'C-001', 'SI-2026-001', 'Supply to Yeti Lounge', 'JD-001', 'Jack Daniels 750ml', '10', '5000', '0', '13', 'wholesale'],
                ['INV-0001', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), 'C-001', 'SI-2026-001', 'Supply to Yeti Lounge', 'CB-001', 'Carlsberg 650ml', '24', '350', '0', '13', 'wholesale'],
            ];
            break;
        case 'journal_entries':
            $sampleRows = [
                ['JE-0001', date('Y-m-d'), 'Opening balance adjustment', '1010', 'debit', '50000', 'Cash on hand opening'],
                ['JE-0001', date('Y-m-d'), 'Opening balance adjustment', '3100', 'credit', '50000', 'Owner equity contra'],
            ];
            break;
        case 'expenses':
            $sampleRows = [
                ['EXP-0001', date('Y-m-d'), '7100', '1010', 'V-001', 'Staff lunch expenses for May', '5000', '0', 'staff_welfare'],
                ['EXP-0002', date('Y-m-d'), '7200', '1010', '', 'Office insurance premium', '12000', '0', 'insurance'],
            ];
            break;
    }
    foreach ($sampleRows as $row) {
        fputcsv($output, $row);
    }
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

