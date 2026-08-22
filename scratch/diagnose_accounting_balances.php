<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "========================================================================================\n";
echo " COMPLETE ACCOUNT BALANCES AUDIT: ACCOUNTS TABLE vs GL JOURNAL_LINES\n";
echo "========================================================================================\n";

$accs = $db->fetchAll("
    SELECT 
        a.id, 
        a.account_name, 
        a.account_type, 
        a.account_subtype, 
        a.opening_balance,
        COALESCE(gl.gl_debit, 0.00) as gl_debit,
        COALESCE(gl.gl_credit, 0.00) as gl_credit,
        CASE 
            WHEN a.account_type IN ('asset', 'expense') THEN (COALESCE(gl.gl_debit, 0) - COALESCE(gl.gl_credit, 0))
            ELSE (COALESCE(gl.gl_credit, 0) - COALESCE(gl.gl_debit, 0))
        END as gl_net_balance
    FROM accounts a
    LEFT JOIN (
        SELECT 
            jl.account_id,
            SUM(jl.debit) as gl_debit,
            SUM(jl.credit) as gl_credit
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN transaction_headers th ON je.transaction_id = th.id
        WHERE th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
        GROUP BY jl.account_id
    ) gl ON a.id = gl.account_id
    ORDER BY a.account_type, a.id ASC
");

printf("%-5s | %-35s | %-10s | %-12s | %-12s | %-12s | %-12s\n", "ID", "Account Name", "Type", "Open Bal", "GL Debit", "GL Credit", "GL Net Bal");
echo str_repeat("-", 115) . "\n";

foreach ($accs as $a) {
    if (abs($a['opening_balance']) > 0 || abs($a['gl_debit']) > 0 || abs($a['gl_credit']) > 0) {
        printf("%-5d | %-35s | %-10s | %12.2f | %12.2f | %12.2f | %12.2f\n",
            $a['id'],
            substr($a['account_name'], 0, 35),
            $a['account_type'],
            $a['opening_balance'],
            $a['gl_debit'],
            $a['gl_credit'],
            $a['gl_net_balance']
        );
    }
}

echo "\n========================================================================================\n";
echo " CHECKING DASHBOARD BANK & CASH CARDS:\n";
echo "========================================================================================\n";

$bank_accs = $db->fetchAll("SELECT * FROM accounts WHERE account_subtype IN ('Bank', 'Cash')");
foreach ($bank_accs as $ba) {
    echo "Account {$ba['account_name']} (ID: {$ba['id']}):\n";
    echo "  Opening Balance in accounts table: Rs. " . number_format($ba['opening_balance'] ?? 0, 2) . "\n";
    $gl_sum = $db->fetchOne("
        SELECT SUM(jl.debit) as dr, SUM(jl.credit) as cr, (SUM(jl.debit) - SUM(jl.credit)) as net
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN transaction_headers th ON je.transaction_id = th.id
        WHERE jl.account_id = ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ", [$ba['id']]);
    echo "  GL Net Movement: Dr " . number_format($gl_sum['dr'] ?? 0, 2) . " - Cr " . number_format($gl_sum['cr'] ?? 0, 2) . " = Net Rs. " . number_format($gl_sum['net'] ?? 0, 2) . "\n";
    echo "  Total (Opening + GL): Rs. " . number_format(($ba['opening_balance'] ?? 0) + ($gl_sum['net'] ?? 0), 2) . "\n\n";
}
