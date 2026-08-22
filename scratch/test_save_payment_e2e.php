<?php
session_start();
$_SESSION['user_id'] = 2;

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'id' => 408318,
    'txn_number' => 'CPAY-00053',
    'party_type' => 'customer',
    'party_id' => 19,
    'txn_date' => '2026-08-22',
    'reference_number' => '',
    'memo' => 'Payment received from Upendra Saha',
    'location_id' => 1,
    'bank_account_id' => [3], // Prabhu Bank
    'line_amount' => [1000.00],
    'apply_txn_id' => [
        '967973',
        '967976',
        '967977',
        '967978'
    ],
    'apply_amount' => [
        '967973' => 60.00,
        '967976' => 590.00,
        '967977' => 310.00,
        '967978' => 40.00
    ]
];

ob_start();
include __DIR__ . '/../api/save_transaction.php';
$response = ob_get_clean();

echo "Response from save_transaction.php:\n$response\n\n";

echo "=== Verification in DB ===\n";
$th = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = 408318");
echo "Transaction Header: Net Amount = {$th['net_amount']} | Status = {$th['status']}\n";

$pay = $db->fetchAll("SELECT * FROM payments WHERE header_id = 408318");
echo "Payments Rows: " . count($pay) . " | Amount = " . ($pay[0]['amount'] ?? 0) . " | Method = " . ($pay[0]['payment_method'] ?? '') . "\n";

$jes = $db->fetchAll("
    SELECT jl.*, a.account_name 
    FROM journal_lines jl 
    JOIN journal_entries je ON jl.je_id = je.je_id 
    JOIN accounts a ON jl.account_id = a.id 
    WHERE je.transaction_id = 408318
");
echo "GL Lines: " . count($jes) . "\n";
foreach ($jes as $j) {
    echo "  Account: {$j['account_name']} | Dr: {$j['debit']} | Cr: {$j['credit']} | Entity: {$j['entity_type']} (ID: {$j['entity_id']})\n";
}

$invs = $db->fetchAll("SELECT id, invoice_number, total_amount, amount_paid, balance_due, payment_status FROM customer_invoices WHERE header_id IN (967973, 967976, 967977, 967978)");
echo "\nAffected Invoices Statuses:\n";
foreach ($invs as $inv) {
    echo "  Invoice: {$inv['invoice_number']} | Total: {$inv['total_amount']} | Paid: {$inv['amount_paid']} | Due: {$inv['balance_due']} | Status: {$inv['payment_status']}\n";
}
