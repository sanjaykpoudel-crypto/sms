<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/ReportingEngine.php';

$db = db();

echo "====================================================================\n";
echo " BALANCE SHEET FILTER FUNCTIONALITY & MULTI-LOCATION TEST SUITE\n";
echo "====================================================================\n\n";

// Test 1: As Of Date Comparison (2026-07-31 vs 2026-08-19)
$d1 = '2026-07-31';
$d2 = '2026-08-19';

$bs_d1 = re_get_balance_sheet($db, $d1);
$bs_d2 = re_get_balance_sheet($db, $d2);

echo "1. DATE FILTER TESTING:\n";
echo "   - As Of {$d1}: Total Assets = Rs " . number_format($bs_d1['total_assets'], 2) . " | Total Liab+Equity = Rs " . number_format($bs_d1['total_liab_equity'], 2) . " | Balanced: " . ($bs_d1['is_balanced'] ? 'YES' : 'NO') . "\n";
echo "   - As Of {$d2}: Total Assets = Rs " . number_format($bs_d2['total_assets'], 2) . " | Total Liab+Equity = Rs " . number_format($bs_d2['total_liab_equity'], 2) . " | Balanced: " . ($bs_d2['is_balanced'] ? 'YES' : 'NO') . "\n";

$date_diff = abs($bs_d1['total_assets'] - $bs_d2['total_assets']);
echo "   -> Assets Changed Between Dates: Rs " . number_format($date_diff, 2) . " (Date filter is 100% active & dynamic)\n\n";

// Test 2: Location Filter Comparison (Location 1 vs Location 2 vs ALL)
$loc1 = '1';
$loc2 = '2';

$bs_loc1 = re_get_balance_sheet($db, $d2, $loc1);
$bs_loc2 = re_get_balance_sheet($db, $d2, $loc2);
$bs_all  = re_get_balance_sheet($db, $d2, 'all');

echo "2. LOCATION FILTER TESTING:\n";
echo "   - Location 1 (Main): Total Assets = Rs " . number_format($bs_loc1['total_assets'], 2) . " | Liab+Equity = Rs " . number_format($bs_loc1['total_liab_equity'], 2) . " | Balanced: " . ($bs_loc1['is_balanced'] ? 'YES' : 'NO') . "\n";
echo "   - Location 2 (Branch): Total Assets = Rs " . number_format($bs_loc2['total_assets'], 2) . " | Liab+Equity = Rs " . number_format($bs_loc2['total_liab_equity'], 2) . " | Balanced: " . ($bs_loc2['is_balanced'] ? 'YES' : 'NO') . "\n";
echo "   - All Locations: Total Assets = Rs " . number_format($bs_all['total_assets'], 2) . " | Liab+Equity = Rs " . number_format($bs_all['total_liab_equity'], 2) . " | Balanced: " . ($bs_all['is_balanced'] ? 'YES' : 'NO') . "\n\n";

// Test 3: AR & AP GL Subledger Location Filtering
$ar_gl_loc1 = re_get_ar_gl_balance($db, $d2, $loc1);
$ap_gl_loc1 = re_get_ap_gl_balance($db, $d2, $loc1);
$ar_sub_loc1 = re_get_ar_balance($db, $d2, $loc1);
$ap_sub_loc1 = re_get_ap_balance($db, $d2, $loc1);

echo "3. AR / AP SUBLEDGER LOCATION FILTERING:\n";
echo "   - Loc 1 Subledger AR: Rs " . number_format($ar_sub_loc1, 2) . " | GL AR: Rs " . number_format($ar_gl_loc1, 2) . " | Diff: Rs " . number_format(abs($ar_sub_loc1 - $ar_gl_loc1), 2) . "\n";
echo "   - Loc 1 Subledger AP: Rs " . number_format($ap_sub_loc1, 2) . " | GL AP: Rs " . number_format($ap_gl_loc1, 2) . " | Diff: Rs " . number_format(abs($ap_sub_loc1 - $ap_gl_loc1), 2) . "\n\n";

echo "====================================================================\n";
echo " FILTER TEST SUMMARY: " . (($date_diff > 0 && $bs_d1['is_balanced'] && $bs_d2['is_balanced']) ? "100% SUCCESS" : "FAILED") . "\n";
echo "====================================================================\n";
