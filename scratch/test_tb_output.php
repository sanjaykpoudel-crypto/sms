<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
$db = db();

$tb = re_get_trial_balance($db, '2026-07-01', date('Y-m-d'));

echo "========================================================================================\n";
echo " TRIAL BALANCE REPORT (2026-07-01 to " . date('Y-m-d') . ")\n";
echo "========================================================================================\n";

printf("%-35s | %-12s | %-12s | %-12s | %-12s\n", "Account Name", "Opening Dr", "Opening Cr", "Closing Dr", "Closing Cr");
echo str_repeat("-", 95) . "\n";

foreach ($tb['rows'] as $r) {
    printf("%-35s | %12.2f | %12.2f | %12.2f | %12.2f\n",
        substr($r['account_name'], 0, 35),
        $r['opening_debit'],
        $r['opening_credit'],
        $r['closing_debit'],
        $r['closing_credit']
    );
}

echo str_repeat("-", 95) . "\n";
printf("%-35s | %12.2f | %12.2f | %12.2f | %12.2f\n",
    "TOTALS",
    $tb['totals']['opening_debit'],
    $tb['totals']['opening_credit'],
    $tb['totals']['closing_debit'],
    $tb['totals']['closing_credit']
);
echo "IS BALANCED: " . ($tb['is_balanced'] ? 'YES' : 'NO') . "\n";
