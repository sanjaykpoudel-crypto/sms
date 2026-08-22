<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "=== Total GL Debits vs Total GL Credits across all Journal Lines ===\n";
$gl_totals = $db->fetchOne("
    SELECT 
        SUM(jl.debit) as total_debit, 
        SUM(jl.credit) as total_credit, 
        ABS(SUM(jl.debit) - SUM(jl.credit)) as diff
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
");
echo "Total Debit: Rs. " . number_format($gl_totals['total_debit'], 2) . "\n";
echo "Total Credit: Rs. " . number_format($gl_totals['total_credit'], 2) . "\n";
echo "Difference (Diff): Rs. " . number_format($gl_totals['diff'], 2) . "\n";

if ($gl_totals['diff'] < 0.01) {
    echo "[PASS] System is in 100% perfect double-entry equilibrium!\n";
} else {
    echo "[FAIL] System has out-of-balance variance!\n";
}
