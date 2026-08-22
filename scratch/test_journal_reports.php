<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;
$_SESSION['username'] = 'superadmin';
$_SESSION['full_name'] = 'System Administrator';

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$tests = [
    'AR Register' => 'forms/modules/reports/customers/ar_register_list.php',
    'AR Payment By Invoice' => 'forms/modules/reports/customers/ar_payment_by_invoice_list.php',
    'Balance Confirmation' => 'forms/modules/reports/customers/balance_confirmation_list.php',
    'Customer Statement' => 'forms/modules/reports/customers/statement_list.php',
    'AP Register' => 'forms/modules/reports/vendors/ap_register_list.php',
    'AP Payment By Bill' => 'forms/modules/reports/vendors/ap_payment_by_bill_list.php',
    'Open Bills' => 'forms/modules/reports/vendors/open_bills_list.php',
    'Open Invoices' => 'forms/modules/reports/sales/open_invoices_list.php',
    'Journal Register' => 'forms/modules/reports/financial/journal_register_list.php',
    'Payment Register' => 'forms/modules/reports/financial/payment_register_list.php',
    'Expense Register' => 'forms/modules/reports/financial/expense_register_list.php',
    'Retained Earnings' => 'forms/modules/reports/financial/retained_earnings_list.php',
    'Equity Statement' => 'forms/modules/reports/financial/equity_statement_list.php',
];

echo "====================================================\n";
echo " TESTING JOURNAL-INTEGRATED REPORTS & REGISTERS\n";
echo "====================================================\n";

$pass = 0; $fail = 0;
foreach ($tests as $name => $path) {
    $_GET = [
        'date_from' => '2024-01-01',
        'date_to' => date('Y-m-d'),
        'customer_id' => '',
        'vendor_id' => '',
        'party_id' => 'cust-69a039773df01',
        'party_type' => 'customer',
        'as_on_date' => date('Y-m-d'),
    ];
    ob_start();
    try {
        require_once __DIR__ . '/../forms/modules/reports/rpt_helpers.php';
        include __DIR__ . '/../' . $path;
        $out = ob_get_clean();
        $len = strlen($out);
        echo "[PASS] $name ($len bytes)\n";
        $pass++;
    } catch (Throwable $e) {
        ob_end_clean();
        echo "[FAIL] $name: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        $fail++;
    }
}

// Test Open Transactions API
echo "\nTesting api/get_open_transactions.php for customer:\n";
$_GET = ['party_id' => 'cust-69a039773df01', 'party_type' => 'customer'];
ob_start();
include __DIR__ . '/../api/get_open_transactions.php';
$api_out = ob_get_clean();
$data = json_decode($api_out, true);
echo "Customer Open Txns Returned: " . count($data) . "\n";

echo "\nTesting api/get_dashboard_data.php:\n";
$_GET = [];
ob_start();
include __DIR__ . '/../api/get_dashboard_data.php';
$dash_out = ob_get_clean();
$dash_data = json_decode($dash_out, true);
echo "Dashboard Status: " . ($dash_data ? "Valid JSON with " . count($dash_data) . " keys" : "Invalid output") . "\n";

echo "====================================================\n";
echo "RESULTS: Passed: $pass, Failed: $fail\n";
echo "====================================================\n";
