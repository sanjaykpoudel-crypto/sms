<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

// 1. Invoices for Customer 11
$invs = $db->fetchAll("
    SELECT ci.id, ci.invoice_number, ci.invoice_date, ci.total_amount, ci.amount_paid, ci.balance_due, ci.header_id
    FROM customer_invoices ci
    WHERE ci.customer_id = 11 AND ci.payment_status != 'paid'
");
echo "=== Customer 11 Unpaid Invoices ===\n";
print_r($invs);

// 2. Payments for Customer 11
$pmts = $db->fetchAll("
    SELECT p.id, p.payment_number, p.payment_date, p.amount, p.header_id
    FROM payments p
    WHERE p.customer_id = 11
");
echo "=== Customer 11 Payments in payments table ===\n";
print_r($pmts);
