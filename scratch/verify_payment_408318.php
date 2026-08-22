<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "=== Verification in DB for ID 408318 ===\n";
$th = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = 408318");
echo "Transaction Header: Net Amount = {$th['net_amount']} | Status = {$th['status']} | Party ID = {$th['party_id']}\n";

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
