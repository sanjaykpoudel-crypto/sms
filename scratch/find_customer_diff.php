<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
$db = db();

$customers = $db->fetchAll("SELECT id, full_name, is_active, is_deleted FROM customers");
$tot_pos = 0; $tot_neg = 0;

foreach ($customers as $c) {
    $bal = get_customer_net_balance($db, $c['id'], '2026-08-22');
    if (abs($bal) > 0.001) {
        printf("Customer %-3d: %-30s | Active: %d | Bal: Rs. %10.2f\n", $c['id'], substr($c['full_name'], 0, 30), $c['is_active'], $bal);
        if ($bal > 0) $tot_pos += $bal;
        else $tot_neg += $bal;
    }
}

echo "\nTotal Positive Balances: Rs. " . number_format($tot_pos, 2) . "\n";
echo "Total Negative Balances (Advances): Rs. " . number_format($tot_neg, 2) . "\n";
echo "Net Subledger Balance: Rs. " . number_format($tot_pos + $tot_neg, 2) . "\n";

$gl_ar = re_get_ar_gl_balance($db, '2026-08-22');
echo "GL AR Control Balance: Rs. " . number_format($gl_ar, 2) . "\n";
echo "Difference vs Net Subledger: Rs. " . number_format(($tot_pos + $tot_neg) - $gl_ar, 2) . "\n";
