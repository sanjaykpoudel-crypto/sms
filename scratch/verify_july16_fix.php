<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/ReportingEngine.php';

$db = db();
$d = '2026-07-16';
$loc = '1';

$sub = re_get_inventory_subledger($db, $d, $loc);
$gl  = re_get_inventory_gl_balance($db, $d, $loc);
$diff = abs($sub - $gl);
$tolerated = ($diff <= 500.00) || ($sub > 0 && ($diff / $sub) <= 0.002);

echo "=== VERIFICATION AS OF {$d} (LOCATION {$loc}) ===\n";
echo "Subledger Valuation as of {$d}: Rs " . number_format($sub, 2) . "\n";
echo "GL Inventory Asset as of {$d}: Rs " . number_format($gl, 2) . "\n";
echo "Difference:                     Rs " . number_format($diff, 2) . "\n";
echo "Reconciliation Status:         " . ($tolerated ? "PASS (NO WARNING BANNER)" : "FAIL") . "\n";
