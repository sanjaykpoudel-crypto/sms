<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "Customer invoices columns:\n";
$cols = $db->fetchAll("DESCRIBE customer_invoices");
foreach ($cols as $c) echo $c['Field'] . ", ";
echo "\n\n";

echo "Invoices around July 2026:\n";
print_r($db->fetchAll("SELECT id, header_id, invoice_number, customer_id, total_amount, amount_paid, balance_due FROM customer_invoices WHERE invoice_date <= '2026-07-20' OR invoice_number LIKE '%00001%' OR invoice_number LIKE '%00002%'"));
