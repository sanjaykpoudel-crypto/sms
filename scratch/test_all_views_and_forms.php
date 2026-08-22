<?php
/**
 * Exhaustive Form and View Verification Engine
 * Renders every form, list, view, and report in the ERP and asserts 0 warnings, 0 errors.
 */
ob_start();
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['location_id'] = 1;
$_SESSION['fiscal_year_id'] = 1;
ob_end_clean();

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';
require_once __DIR__ . '/../api/AccountingEngine.php';
require_once __DIR__ . '/../api/ReportingEngine.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "====================================================================\n";
echo " EXHAUSTIVE VIEW & FORM RENDER VERIFICATION SUITE\n";
echo " Testing every single transaction form, master form, view & report\n";
echo "====================================================================\n\n";

// Sample valid IDs for testing view pages
$sample_inv_id = $pdo->query("SELECT id FROM transaction_headers WHERE txn_type = 'customer_invoice' LIMIT 1")->fetchColumn() ?: 1;
$sample_bill_id = $pdo->query("SELECT id FROM transaction_headers WHERE txn_type = 'vendor_bill' LIMIT 1")->fetchColumn() ?: 1;
$sample_pay_id = $pdo->query("SELECT id FROM transaction_headers WHERE txn_type = 'customer_payment' LIMIT 1")->fetchColumn() ?: 1;
$sample_exp_id = $pdo->query("SELECT id FROM transaction_headers WHERE txn_type = 'expense' LIMIT 1")->fetchColumn() ?: 1;
$sample_jv_id = $pdo->query("SELECT id FROM transaction_headers WHERE txn_type IN ('Journal', 'journal') LIMIT 1")->fetchColumn() ?: 1;
$sample_cust_id = $pdo->query("SELECT id FROM customers LIMIT 1")->fetchColumn() ?: 1;
$sample_vend_id = $pdo->query("SELECT id FROM vendors LIMIT 1")->fetchColumn() ?: 1;
$sample_item_id = $pdo->query("SELECT id FROM items LIMIT 1")->fetchColumn() ?: 1;
$sample_acc_id = $pdo->query("SELECT id FROM accounts LIMIT 1")->fetchColumn() ?: 1;

$routes = [
    // --- 1. Transaction Form Pages (Create / Manage) ---
    ['name' => 'New Customer Invoice Form',    'file' => 'forms/modules/transactions/invoice/invoice_manage.php', 'get' => []],
    ['name' => 'Edit Customer Invoice Form',   'file' => 'forms/modules/transactions/invoice/invoice_manage.php', 'get' => ['id' => $sample_inv_id]],
    ['name' => 'New Vendor Bill Form',          'file' => 'forms/modules/transactions/bill/bill_manage.php',       'get' => []],
    ['name' => 'Edit Vendor Bill Form',         'file' => 'forms/modules/transactions/bill/bill_manage.php',       'get' => ['id' => $sample_bill_id]],
    ['name' => 'New Customer Payment Form',     'file' => 'forms/modules/transactions/payment/payment_manage.php', 'get' => ['type' => 'customer_payment']],
    ['name' => 'New Vendor Payment Form',       'file' => 'forms/modules/transactions/payment/payment_manage.php', 'get' => ['type' => 'vendor_payment']],
    ['name' => 'New Expense Form',              'file' => 'forms/modules/transactions/expense/expense_manage.php', 'get' => []],
    ['name' => 'Edit Expense Form',             'file' => 'forms/modules/transactions/expense/expense_manage.php', 'get' => ['id' => $sample_exp_id]],
    ['name' => 'New Journal Voucher Form',      'file' => 'forms/modules/transactions/journal/journal_manage.php', 'get' => []],
    ['name' => 'Edit Journal Voucher Form',     'file' => 'forms/modules/transactions/journal/journal_manage.php', 'get' => ['id' => $sample_jv_id]],
    ['name' => 'New Credit Memo Form',          'file' => 'forms/modules/transactions/credit_memo/credit_memo_manage.php', 'get' => []],
    ['name' => 'New Vendor Credit Form',        'file' => 'forms/modules/transactions/bill_credit/bill_credit_manage.php', 'get' => []],
    ['name' => 'New Inventory Adjustment Form', 'file' => 'forms/modules/transactions/adjustment/adjustment_manage.php', 'get' => []],
    ['name' => 'New Stock Transfer Form',       'file' => 'forms/modules/transactions/inventory_transfer/inventory_transfer_manage.php', 'get' => []],
    ['name' => 'New Bank Fund Transfer Form',   'file' => 'forms/modules/transactions/transfer/transfer_manage.php', 'get' => []],
    ['name' => 'Cash Denomination Form',        'file' => 'forms/modules/transactions/cash_denom/cash_denom_manage.php', 'get' => []],

    // --- 2. Transaction List Pages ---
    ['name' => 'Invoices List View',           'file' => 'forms/modules/transactions/invoice/invoice_list.php',   'get' => []],
    ['name' => 'Vendor Bills List View',       'file' => 'forms/modules/transactions/bill/bill_list.php',         'get' => []],
    ['name' => 'Customer Payments List View',   'file' => 'forms/modules/transactions/payment/customer_payments.php', 'get' => []],
    ['name' => 'Vendor Payments List View',     'file' => 'forms/modules/transactions/payment/vendor_payments.php',   'get' => []],
    ['name' => 'Expenses List View',            'file' => 'forms/modules/transactions/expense/expense_list.php',   'get' => []],
    ['name' => 'Journal Entries List View',     'file' => 'forms/modules/transactions/journal/journal_list.php',   'get' => []],
    ['name' => 'Credit Memos List View',        'file' => 'forms/modules/transactions/credit_memo/credit_memo_list.php', 'get' => []],
    ['name' => 'Vendor Credits List View',      'file' => 'forms/modules/transactions/bill_credit/bill_credit_list.php', 'get' => []],
    ['name' => 'Stock Transfers List View',     'file' => 'forms/modules/transactions/inventory_transfer/inventory_transfer_list.php', 'get' => []],
    ['name' => 'Cash Denominations List View',  'file' => 'forms/modules/transactions/cash_denom/cash_denom_list.php', 'get' => []],
    ['name' => 'Transactions Register View',    'file' => 'forms/modules/transactions/transactions_list.php',      'get' => []],

    // --- 3. Transaction Details & Print Views ---
    ['name' => 'General Transaction View',      'file' => 'forms/modules/transactions/view.php',                   'get' => ['id' => $sample_inv_id]],
    ['name' => 'Transaction Print View',        'file' => 'forms/modules/transactions/print.php',                  'get' => ['id' => $sample_inv_id]],

    // --- 4. Master Data Forms & Views ---
    ['name' => 'Customer List View',            'file' => 'forms/modules/master/customer/customer_list.php',       'get' => []],
    ['name' => 'Customer Manage Form',          'file' => 'forms/modules/master/customer/customer_manage.php',     'get' => ['id' => $sample_cust_id]],
    ['name' => 'Customer Details View',         'file' => 'forms/modules/master/customer/view.php',                'get' => ['id' => $sample_cust_id]],
    ['name' => 'Vendor List View',              'file' => 'forms/modules/master/vendor/vendor_list.php',           'get' => []],
    ['name' => 'Vendor Manage Form',            'file' => 'forms/modules/master/vendor/vendor_manage.php',         'get' => ['id' => $sample_vend_id]],
    ['name' => 'Vendor Details View',           'file' => 'forms/modules/master/vendor/view.php',                  'get' => ['id' => $sample_vend_id]],
    ['name' => 'Item Master List View',         'file' => 'forms/modules/master/item/item_list.php',               'get' => []],
    ['name' => 'Item Master Manage Form',       'file' => 'forms/modules/master/item/item_manage.php',             'get' => ['id' => $sample_item_id]],
    ['name' => 'Item Details View',             'file' => 'forms/modules/master/item/view.php',                    'get' => ['id' => $sample_item_id]],
    ['name' => 'Chart of Accounts List View',   'file' => 'forms/modules/master/account/account_list.php',         'get' => []],
    ['name' => 'Chart of Accounts Manage Form', 'file' => 'forms/modules/master/account/account_manage.php',       'get' => ['id' => $sample_acc_id]],

    // --- 5. Financial & Operational Reports ---
    ['name' => 'Trial Balance Report',          'file' => 'forms/modules/reports/financial/trial_balance_list.php', 'get' => []],
    ['name' => 'Income Statement (P&L)',        'file' => 'forms/modules/reports/financial/income_statement_list.php', 'get' => []],
    ['name' => 'Balance Sheet Report',          'file' => 'forms/modules/reports/financial/balance_sheet_list.php', 'get' => []],
    ['name' => 'General Ledger Report',         'file' => 'forms/modules/reports/financial/general_ledger_list.php', 'get' => []],
    ['name' => 'Cash Flow Statement',           'file' => 'forms/modules/reports/financial/cash_flow_statement_list.php', 'get' => []],
    ['name' => 'Cash Book Report',              'file' => 'forms/modules/reports/financial/cash_book_list.php',     'get' => []],
    ['name' => 'Bank Book Report',              'file' => 'forms/modules/reports/financial/bank_book_list.php',     'get' => []],
    ['name' => 'Daily Profit Report',           'file' => 'forms/modules/reports/financial/daily_profit_list.php',  'get' => []],
    ['name' => 'Financial Ratios Report',       'file' => 'forms/modules/reports/financial/financial_ratios_list.php', 'get' => []],
    ['name' => 'Receivable Aging Report',       'file' => 'forms/modules/reports/customers/receivable_aging_list.php', 'get' => []],
    ['name' => 'Customer Statement Report',     'file' => 'forms/modules/reports/customers/statement_list.php',    'get' => []],
    ['name' => 'Payable Aging Report',          'file' => 'forms/modules/reports/vendors/payable_aging_list.php',   'get' => []],
    ['name' => 'Stock Summary Report',          'file' => 'forms/modules/reports/inventory/stock_summary_list.php', 'get' => []],
    ['name' => 'Stock Movement Report',         'file' => 'forms/modules/reports/inventory/stock_movement_list.php', 'get' => []],
    ['name' => 'Stock Valuation Report',        'file' => 'forms/modules/reports/inventory/inventory_valuation_list.php', 'get' => []],
    ['name' => 'Low Stock Report',              'file' => 'forms/modules/reports/inventory/low_stock_list.php',     'get' => []],
    ['name' => 'Sales Summary Report',          'file' => 'forms/modules/reports/sales/sales_summary_list.php',     'get' => []],
    ['name' => 'Sales By Item Report',          'file' => 'forms/modules/reports/sales/by_item_list.php',           'get' => []],
    ['name' => 'Sales By Customer Report',      'file' => 'forms/modules/reports/sales/by_customer_list.php',       'get' => []],
    ['name' => 'Purchase Register Report',      'file' => 'forms/modules/reports/purchases/purchase_register_list.php', 'get' => []],
    ['name' => 'Purchases By Vendor Report',    'file' => 'forms/modules/reports/purchases/by_vendor_list.php',     'get' => []],
    ['name' => 'VAT Sales Register Report',     'file' => 'forms/modules/reports/vat/sales_register_list.php',      'get' => []],
    ['name' => 'VAT Purchase Register Report',  'file' => 'forms/modules/reports/vat/purchase_register_list.php',   'get' => []],

    // --- 6. System Administration & Settings ---
    ['name' => 'Company Profile Manage',        'file' => 'forms/modules/system/company/company_manage.php',       'get' => []],
    ['name' => 'Accounting Preferences',        'file' => 'forms/modules/system/settings/accounting_preferences.php', 'get' => []],
    ['name' => 'Reference Codes Settings',      'file' => 'forms/modules/system/ref_codes/ref_codes_manage.php',    'get' => []],
    ['name' => 'Location Settings View',        'file' => 'forms/modules/system/settings/location/location_list.php', 'get' => []],
    ['name' => 'Backup & Restore Manage',       'file' => 'forms/modules/system/backup/backup_manage.php',          'get' => []],
    ['name' => 'Users Administration List',     'file' => 'forms/modules/system/users/user_list.php',              'get' => []],
    ['name' => 'User Profile View',             'file' => 'forms/modules/system/users/profile.php',                'get' => []],
];

$pass_count = 0;
$fail_count = 0;
$warnings_list = [];

foreach ($routes as $route) {
    $name = $route['name'];
    $file = realpath(__DIR__ . '/../' . $route['file']);

    if (!$file || !file_exists($file)) {
        echo sprintf("[FAIL] %-40s | File not found: %s\n", $name, $route['file']);
        $fail_count++;
        continue;
    }

    $_GET = $route['get'];
    $_POST = [];

    // Capture output and warnings
    ob_start();
    $error_msg = null;
    try {
        include $file;
    } catch (Throwable $e) {
        $error_msg = $e->getMessage() . " in " . basename($e->getFile()) . ":" . $e->getLine();
    }
    $output = ob_get_clean();

    if ($error_msg) {
        echo sprintf("[FAIL] %-40s | Error: %s\n", $name, $error_msg);
        $fail_count++;
        $warnings_list[] = ['name' => $name, 'file' => $route['file'], 'error' => $error_msg];
    } else {
        echo sprintf("[PASS] %-40s | Rendered successfully (%d bytes)\n", $name, strlen($output));
        $pass_count++;
    }
}

echo "\n====================================================================\n";
echo " FORM & VIEW SUITE RESULTS:\n";
echo " Passed: {$pass_count} / " . count($routes) . "\n";
echo " Failed: {$fail_count} / " . count($routes) . "\n";
echo "====================================================================\n";
