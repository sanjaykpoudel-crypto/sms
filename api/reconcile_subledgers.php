<?php
/**
 * api/reconcile_subledgers.php
 * Comprehensive automated financial reconciliation tool for MNS Liquor ERP.
 * Verifies that:
 * 1. Total Debit == Total Credit across all journal entries
 * 2. AR Subledger Customer Net Balances == Receivable Accounts Ledger
 * 3. AP Subledger Vendor Net Balances == Payable Accounts Ledger
 * 4. Inventory Valuation == Inventory Asset Account Ledger
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && PHP_SAPI !== 'cli') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

$db = db();
$pdo = $db->getConnection();

try {
    $results = [];
    $allBalanced = true;

    // 1. Trial Balance Check: Total Debit vs Total Credit
    $glTotals = $db->fetchOne("
        SELECT 
            SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) as total_debit,
            SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) as total_credit
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ");
    $totDebit = (float)($glTotals['total_debit'] ?? 0);
    $totCredit = (float)($glTotals['total_credit'] ?? 0);
    $glDiff = abs($totDebit - $totCredit);

    $results['trial_balance'] = [
        'total_debit' => round($totDebit, 2),
        'total_credit' => round($totCredit, 2),
        'difference' => round($glDiff, 2),
        'status' => $glDiff < 0.01 ? 'MATCH' : 'MISMATCH'
    ];
    if ($glDiff >= 0.01) $allBalanced = false;

    // 2. Customer Accounts Receivable Subledger Check
    $customers = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_deleted = 0");
    $totalArSubledger = 0.0;
    foreach ($customers as $c) {
        $totalArSubledger += get_customer_net_balance($db, $c['id']);
    }
    
    $arGlBalance = (float)($db->fetchOne("
        SELECT SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END) as net_bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (a.account_subtype IN ('receivable', 'Accounts Receivable') OR a.id = 'acc-1100')
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ")['net_bal'] ?? 0);
    
    $arDiff = abs($totalArSubledger - $arGlBalance);
    $results['accounts_receivable'] = [
        'subledger_total' => round($totalArSubledger, 2),
        'gl_ledger_total' => round($arGlBalance, 2),
        'difference' => round($arDiff, 2),
        'status' => $arDiff < 0.01 ? 'MATCH' : 'MISMATCH'
    ];

    // 3. Vendor Accounts Payable Subledger Check
    $vendors = $db->fetchAll("SELECT id, company_name FROM vendors WHERE is_deleted = 0");
    $totalApSubledger = 0.0;
    foreach ($vendors as $v) {
        $totalApSubledger += get_vendor_net_balance($db, $v['id']);
    }

    $apGlBalance = (float)($db->fetchOne("
        SELECT SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END) as net_bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (a.account_subtype IN ('payable', 'Accounts Payable') OR a.id = 'acc-2100')
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ")['net_bal'] ?? 0);

    $apDiff = abs($totalApSubledger - $apGlBalance);
    $results['accounts_payable'] = [
        'subledger_total' => round($totalApSubledger, 2),
        'gl_ledger_total' => round($apGlBalance, 2),
        'difference' => round($apDiff, 2),
        'status' => $apDiff < 0.01 ? 'MATCH' : 'MISMATCH'
    ];

    // 4. Inventory Valuation Subledger Check
    $invSubledger = (float)($db->fetchOne("SELECT SUM(current_stock * cost_price) as total_val FROM items WHERE is_deleted = 0")['total_val'] ?? 0);
    $invGlBalance = (float)($db->fetchOne("
        SELECT SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END) as net_bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (a.account_subtype IN ('inventory', 'Inventory Asset') OR a.id = 'acc-1200')
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ")['net_bal'] ?? 0);

    $invDiff = abs($invSubledger - $invGlBalance);
    $results['inventory_valuation'] = [
        'items_stock_valuation' => round($invSubledger, 2),
        'gl_ledger_valuation' => round($invGlBalance, 2),
        'difference' => round($invDiff, 2),
        'status' => $invDiff < 0.01 ? 'MATCH' : 'MISMATCH'
    ];

    $response = [
        'success' => true,
        'all_reconciled' => $allBalanced,
        'timestamp' => date('Y-m-d H:i:s'),
        'reconciliation' => $results
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
