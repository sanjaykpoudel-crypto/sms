<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
$db = db();

$as_of = date('Y-m-d');
$bs = re_get_balance_sheet($db, $as_of);

echo "====================================================================\n";
echo " BALANCE SHEET AS OF $as_of\n";
echo "====================================================================\n";

echo "\n--- ASSETS ---\n";
foreach ($bs['assets'] as $a) {
    printf("%-35s : Rs. %12.2f\n", $a['name'], $a['balance']);
}
echo "TOTAL ASSETS: Rs. " . number_format($bs['total_assets'], 2) . "\n";

echo "\n--- LIABILITIES ---\n";
foreach ($bs['liabilities'] as $l) {
    printf("%-35s : Rs. %12.2f\n", $l['name'], $l['balance']);
}
echo "TOTAL LIABILITIES: Rs. " . number_format($bs['total_liabilities'], 2) . "\n";

echo "\n--- EQUITY ---\n";
foreach ($bs['equity'] as $e) {
    printf("%-35s : Rs. %12.2f\n", $e['name'], $e['balance']);
}
echo "Current Period Net Income: Rs. " . number_format($bs['net_income'], 2) . "\n";
echo "TOTAL EQUITY: Rs. " . number_format($bs['total_equity'], 2) . "\n";

echo "\nTOTAL LIABILITIES & EQUITY: Rs. " . number_format($bs['total_liab_equity'], 2) . "\n";
echo "VARIANCE (Assets - Liab&Eq): Rs. " . number_format($bs['variance'], 2) . "\n";
echo "IS BALANCED: " . ($bs['is_balanced'] ? 'YES' : 'NO') . "\n";
