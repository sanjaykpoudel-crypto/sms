<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
require_once __DIR__ . '/../api/reference_helper.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "====================================================================\n";
echo " DETAILED REPORT & SUBLEDGER RECONCILIATION AUDIT\n";
echo "====================================================================\n\n";

// 1. Audit P&L Report
echo "--- 1. PROFIT & LOSS REPORT AUDIT ---\n";
$pnl = re_get_pnl($db, '2026-07-01', '2026-08-22');
echo "Sales Revenue:       Rs " . number_format($pnl['total_income'] ?? 0, 2) . "\n";
echo "Cost of Goods Sold:  Rs " . number_format($pnl['total_cogs'] ?? 0, 2) . "\n";
echo "Gross Profit:        Rs " . number_format($pnl['gross_profit'] ?? 0, 2) . "\n";
echo "Total Expenses:      Rs " . number_format($pnl['total_expenses'] ?? 0, 2) . "\n";
echo "Net Profit:          Rs " . number_format($pnl['net_profit'] ?? 0, 2) . "\n";
$calculated_net = ($pnl['gross_profit'] ?? 0) - ($pnl['total_expenses'] ?? 0);
echo "Formula Match: " . (abs($calculated_net - ($pnl['net_profit'] ?? 0)) < 0.01 ? "YES (PERFECT)" : "MISMATCH") . "\n\n";

// 2. Audit Balance Sheet
echo "--- 2. BALANCE SHEET REPORT AUDIT ---\n";
$bs = re_get_balance_sheet($db, '2026-08-22');
echo "Total Assets:        Rs " . number_format($bs['total_assets'] ?? 0, 2) . "\n";
echo "Total Liabilities:   Rs " . number_format($bs['total_liabilities'] ?? 0, 2) . "\n";
echo "Total Equity:        Rs " . number_format($bs['total_equity'] ?? 0, 2) . "\n";
echo "Liab + Equity:       Rs " . number_format(($bs['total_liabilities'] ?? 0) + ($bs['total_equity'] ?? 0), 2) . "\n";
$bs_diff = ($bs['total_assets'] ?? 0) - (($bs['total_liabilities'] ?? 0) + ($bs['total_equity'] ?? 0));
echo "Accounting Equation Assets = Liabilities + Equity Match: " . (abs($bs_diff) < 0.01 ? "YES (PERFECT)" : "Diff: Rs " . number_format($bs_diff, 2)) . "\n\n";

// 3. Inspect AR GL Lines breakdown by transaction type
echo "--- 3. ACCOUNTS RECEIVABLE (ACCOUNT 6) GL BREAKDOWN ---\n";
$ar_breakdown = $pdo->query("
    SELECT h.txn_type, je.je_type, SUM(jl.debit) as total_debit, SUM(jl.credit) as total_credit, SUM(jl.debit - jl.credit) as net_change
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE jl.account_id = 6 AND h.is_deleted = 0
    GROUP BY h.txn_type, je.je_type
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($ar_breakdown as $r) {
    echo sprintf("TxnType: %-20s | JEType: %-18s | Dr: %12.2f | Cr: %12.2f | Net: %12.2f\n",
        $r['txn_type'], $r['je_type'], $r['total_debit'], $r['total_credit'], $r['net_change']
    );
}

// 4. Inspect AP GL Lines breakdown by transaction type
echo "\n--- 4. ACCOUNTS PAYABLE (ACCOUNT 12) GL BREAKDOWN ---\n";
$ap_breakdown = $pdo->query("
    SELECT h.txn_type, je.je_type, SUM(jl.debit) as total_debit, SUM(jl.credit) as total_credit, SUM(jl.credit - jl.debit) as net_change
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE jl.account_id = 12 AND h.is_deleted = 0
    GROUP BY h.txn_type, je.je_type
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($ap_breakdown as $r) {
    echo sprintf("TxnType: %-20s | JEType: %-18s | Dr: %12.2f | Cr: %12.2f | Net: %12.2f\n",
        $r['txn_type'], $r['je_type'], $r['total_debit'], $r['total_credit'], $r['net_change']
    );
}

// 5. Inspect Inventory (ACCOUNT 7) GL Breakdown
echo "\n--- 5. INVENTORY ASSET (ACCOUNT 7) GL BREAKDOWN ---\n";
$inv_breakdown = $pdo->query("
    SELECT h.txn_type, je.je_type, SUM(jl.debit) as total_debit, SUM(jl.credit) as total_credit, SUM(jl.debit - jl.credit) as net_change
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE jl.account_id = 7 AND h.is_deleted = 0
    GROUP BY h.txn_type, je.je_type
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($inv_breakdown as $r) {
    echo sprintf("TxnType: %-20s | JEType: %-18s | Dr: %12.2f | Cr: %12.2f | Net: %12.2f\n",
        $r['txn_type'], $r['je_type'], $r['total_debit'], $r['total_credit'], $r['net_change']
    );
}
