<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/AccountingEngine.php';
require_once __DIR__ . '/../api/reference_helper.php';

$db = db();
$engine = AccountingEngine::getInstance();

// Let's create a test Journal Entry for Customer 21 (Krishna Lomus)
$id = 999888;
$txn_number = 'JV-TEST-00099';
$txn_date = '2026-08-10';
$memo = 'Opening adjustment / Debit Note test for Krishna Lomus';

// 1. Insert header
$db->execute("
    INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, net_amount, created_by, location_id)
    VALUES (?, ?, 'Journal', ?, 2026, 8, '2026-08', 'posted', ?, 500.00, 2, 1)
", [$id, $txn_number, $txn_date, $memo]);

// 2. Post Journal lines
$gl_lines = [
    [
        'account_id' => 6, // Accounts Receivable
        'debit' => 500.00,
        'credit' => 0.00,
        'entity_type' => 'CUSTOMER',
        'entity_id' => 21,
        'location_id' => 1
    ],
    [
        'account_id' => 25, // Sales Income or other
        'debit' => 0.00,
        'credit' => 500.00,
        'entity_type' => 'NONE',
        'location_id' => 1
    ]
];

$engine->postJournalEntry($id, 'JOURNAL', $gl_lines, $txn_date, $memo);

// 3. Now let's fetch the customer statement HTML for customer 21
$_GET = [
    'customer_id' => '21',
    'from_date' => '2026-06-01',
    'to_date' => '2026-08-22'
];

ob_start();
include __DIR__ . '/../forms/modules/reports/customers/statement_list.php';
$html = ob_get_clean();

echo "Statement contains JV-TEST-00099: " . (str_contains($html, 'JV-TEST-00099') ? 'YES' : 'NO') . "\n";
echo "Statement contains Opening adjustment / Debit Note: " . (str_contains($html, 'Opening adjustment') ? 'YES' : 'NO') . "\n";
echo "Statement contains 500.00 debit: " . (str_contains($html, '500.00') ? 'YES' : 'NO') . "\n";

// Cleanup test transaction
$engine->deleteJournalForTransaction($id);
$db->execute("DELETE FROM transaction_headers WHERE id = ?", [$id]);

echo "Cleaned up test journal transaction successfully!\n";
