<?php
define('TESTING', true);
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';
require_once __DIR__ . '/../api/transaction_handler.php';

$db = db();
$pdo = $db->getConnection();
$_SESSION['user_id'] = 'usr-admin-001';

echo "--- STARTING PAYMENT DELETION STATUS VERIFICATION ---\n";

// 1. Create a Test Customer Invoice
$inv_hdr_id = generate_uuid();
$inv_no = "INV-TEST-DEL-PAY";
$customer_id = $db->fetchOne("SELECT id FROM customers WHERE is_active=1 AND is_deleted=0 LIMIT 1")['id'];

$pdo->prepare("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by) VALUES (?, ?, 'customer_invoice', '2026-07-23', '2026', '07', '2026-07', 'open', 'usr-admin-001')")->execute([$inv_hdr_id, $inv_no]);
$pdo->prepare("INSERT INTO customer_invoices (id, header_id, customer_id, invoice_date, due_date, invoice_number, subtotal, total_amount, amount_paid, balance_due, payment_status, sale_type) VALUES (?, ?, ?, '2026-07-23', '2026-07-23', ?, 5000, 5000, 0, 5000, 'unpaid', 'credit')")->execute([generate_uuid(), $inv_hdr_id, $customer_id, $inv_no]);

// 2. Create a Test Payment for 5000 applied to this Invoice
$pay_hdr_id = generate_uuid();
$pay_no = "PAY-TEST-DEL";
$bank_acc = $db->fetchOne("SELECT id FROM accounts WHERE account_subtype='bank' AND is_active=1 AND is_deleted=0 LIMIT 1")['id'];

$pdo->prepare("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by) VALUES (?, ?, 'customer_payment', '2026-07-23', '2026', '07', '2026-07', 'posted', 'usr-admin-001')")->execute([$pay_hdr_id, $pay_no]);
$pdo->prepare("INSERT INTO payments (id, header_id, payment_type, customer_id, payment_method, bank_account_id, amount, payment_date) VALUES (?, ?, 'customer_payment', ?, 'bank_transfer', ?, 5000, '2026-07-23')")->execute([generate_uuid(), $pay_hdr_id, $customer_id, $bank_acc]);
$pdo->prepare("INSERT INTO transaction_links (id, parent_id, child_id, link_type) VALUES (?, ?, ?, 'payment:5000.00')")->execute([generate_uuid(), $pay_hdr_id, $inv_hdr_id]);

// Trigger recalculate_document_payment_status after applying payment
recalculate_document_payment_status($inv_hdr_id, $pdo);

$inv_after_pay = $db->fetchOne("SELECT amount_paid, balance_due, payment_status FROM customer_invoices WHERE header_id=?", [$inv_hdr_id]);
$hdr_after_pay = $db->fetchOne("SELECT status FROM transaction_headers WHERE id=?", [$inv_hdr_id]);

echo "After Applying Payment:\n";
echo "  - Invoice Paid: {$inv_after_pay['amount_paid']}, Due: {$inv_after_pay['balance_due']}, Payment Status: {$inv_after_pay['payment_status']}, Header Status: {$hdr_after_pay['status']}\n";

if ($inv_after_pay['payment_status'] !== 'paid' || $hdr_after_pay['status'] !== 'paid') {
    echo "ERROR: Payment application failed to set status to paid.\n";
    exit(1);
}

// 3. Now Delete the Payment via handleTransaction
$delete_payload = [
    'action' => 'delete',
    'table' => 'transaction_headers',
    'primary_key' => 'id',
    'primary_value' => $pay_hdr_id
];

$res = handleTransaction($delete_payload, $pdo, $db);
echo "Payment Delete API Result: " . json_encode($res) . "\n";

// 4. Verify Invoice Status AFTER Payment Deletion
$inv_after_del = $db->fetchOne("SELECT amount_paid, balance_due, payment_status FROM customer_invoices WHERE header_id=?", [$inv_hdr_id]);
$hdr_after_del = $db->fetchOne("SELECT status FROM transaction_headers WHERE id=?", [$inv_hdr_id]);

echo "After Deleting Payment:\n";
echo "  - Invoice Paid: {$inv_after_del['amount_paid']}, Due: {$inv_after_del['balance_due']}, Payment Status: {$inv_after_del['payment_status']}, Header Status: {$hdr_after_del['status']}\n";

if ((float)$inv_after_del['amount_paid'] === 0.00 && (float)$inv_after_del['balance_due'] === 5000.00 && $inv_after_del['payment_status'] === 'unpaid' && $hdr_after_del['status'] === 'open') {
    echo "SUCCESS: Payment deletion successfully reset invoice status to 'unpaid' / 'open' and restored balance due to 5,000.00!\n";
} else {
    echo "FAILED: Invoice status was not updated properly after payment deletion.\n";
}

// Clean up test rows
$pdo->prepare("DELETE FROM customer_invoices WHERE header_id=?")->execute([$inv_hdr_id]);
$pdo->prepare("DELETE FROM transaction_headers WHERE id=? OR id=?")->execute([$inv_hdr_id, $pay_hdr_id]);
