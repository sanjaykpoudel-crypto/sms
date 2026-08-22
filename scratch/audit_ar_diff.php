<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
$db = db();

echo "Auditing AR Subledger vs AR GL:\n";

// Invoices balance due
$inv_due = $db->fetchOne("
    SELECT SUM(balance_due) as due 
    FROM customer_invoices 
    WHERE is_deleted = 0 AND payment_status != 'paid' AND invoice_date <= '2026-08-22'
")['due'] ?? 0;
echo "Customer Invoices Open Balance Due: Rs. $inv_due\n";

// Opening balances in journal_lines
$jv_ar = $db->fetchOne("
    SELECT SUM(jl.debit - jl.credit) as net_ar
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 6 AND th.txn_type = 'Journal' AND th.is_deleted = 0
")['net_ar'] ?? 0;
echo "Journal Entries AR: Rs. $jv_ar\n";

// Total GL AR
$gl_ar = $db->fetchOne("
    SELECT SUM(jl.debit - jl.credit) as net_ar
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE jl.account_id = 6 AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
")['net_ar'] ?? 0;
echo "Total GL AR: Rs. $gl_ar\n";
