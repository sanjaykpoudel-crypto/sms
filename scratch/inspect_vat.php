<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== VAT / TAX ACCOUNTS & JOURNAL ENTRIES AUDIT ===\n\n";

// 1. Fetch all accounts with VAT or Tax in account_name or account_subtype
$tax_accounts = $db->fetchAll("
    SELECT id, account_name, account_type, account_subtype, normal_balance
    FROM accounts
    WHERE (LOWER(account_name) LIKE '%vat%' OR LOWER(account_name) LIKE '%tax%' OR LOWER(account_subtype) LIKE '%vat%' OR LOWER(account_subtype) LIKE '%tax%')
      AND is_deleted = 0
");

echo "--- 1. VAT/TAX ACCOUNTS IN COA ---\n";
print_r($tax_accounts);

// 2. Fetch all journal entries posted to these accounts
$acct_ids = array_column($tax_accounts, 'id');
if (!empty($acct_ids)) {
    $placeholders = implode(',', array_fill(0, count($acct_ids), '?'));
    $entries = $db->fetchAll("
        SELECT j.id, j.header_id, j.account_id, a.account_name, j.entry_type, j.amount, j.entry_date, j.memo,
               h.txn_number, h.txn_type
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE j.account_id IN ({$placeholders})
          AND h.is_deleted = 0
          AND h.status NOT IN ('void', 'voided', 'draft')
        ORDER BY j.entry_date ASC, j.id ASC
    ", $acct_ids);

    echo "\n--- 2. JOURNAL ENTRIES ON VAT/TAX ACCOUNTS (Total: " . count($entries) . ") ---\n";
    $tot_dr = 0; $tot_cr = 0;
    foreach ($entries as $e) {
        $amt = (float)$e['amount'];
        if ($e['entry_type'] === 'debit') $tot_dr += $amt;
        else $tot_cr += $amt;

        echo sprintf("Txn #: %-18s | Type: %-15s | Date: %s | Account: %-15s | Entry: %-6s | Amount: %10.2f | Memo: %s\n",
            $e['txn_number'], $e['txn_type'], $e['entry_date'], $e['account_name'], $e['entry_type'], $amt, $e['memo']
        );
    }

    echo "\n--- SUMMARY --- \n";
    echo "Total Debits (Input VAT / Tax Paid on Purchases): Rs " . number_format($tot_dr, 2) . "\n";
    echo "Total Credits (Output VAT / Tax Collected on Sales): Rs " . number_format($tot_cr, 2) . "\n";
    echo "Net Balance (Debits - Credits): Rs " . number_format($tot_dr - $tot_cr, 2) . "\n";
}
