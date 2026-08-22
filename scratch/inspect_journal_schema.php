<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- Distinct txn_type in transaction_headers ---\n";
$types = $db->fetchAll("SELECT txn_type, COUNT(*) as cnt FROM transaction_headers GROUP BY txn_type");
print_r($types);

echo "\n--- Distinct je_type in journal_entries ---\n";
$je_types = $db->fetchAll("SELECT je_type, COUNT(*) as cnt FROM journal_entries GROUP BY je_type");
print_r($je_types);

echo "\n--- Sample journal_entries headers ---\n";
$samples = $db->fetchAll("
    SELECT je.*, th.txn_number, th.txn_type, th.party_id, th.party_type
    FROM journal_entries je
    LEFT JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE th.txn_type NOT IN ('customer_invoice', 'vendor_bill', 'customer_payment', 'vendor_payment', 'expense', 'stock_transfer', 'inventory_adjustment', 'cash_denomination')
       OR th.txn_type IS NULL
");
print_r($samples);

echo "\n--- Transactions with JV in txn_number ---\n";
$jv_txns = $db->fetchAll("SELECT * FROM transaction_headers WHERE txn_number LIKE '%JV%'");
print_r($jv_txns);
