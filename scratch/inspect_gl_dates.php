<?php
require_once 'database/DBConnection.php';
require_once 'api/ReportingEngine.php';
$db = db();

echo "=== TESTING re_get_inventory_gl_balance FOR DIFFERENT DATES ===\n\n";

$d1 = '2026-07-17';
$gl1 = re_get_inventory_gl_balance($db, $d1);
echo "re_get_inventory_gl_balance as of {$d1}: Rs " . number_format($gl1, 2) . "\n";

$d2 = '2026-08-19';
$gl2 = re_get_inventory_gl_balance($db, $d2);
echo "re_get_inventory_gl_balance as of {$d2}: Rs " . number_format($gl2, 2) . "\n\n";

$sub = re_get_inventory_subledger($db, $d2);
echo "re_get_inventory_subledger as of {$d2}: Rs " . number_format($sub, 2) . "\n\n";

echo "Difference if Subledger as of 2026-08-19 vs GL as of 2026-07-17:\n";
echo "Subledger ({$d2}): Rs " . number_format($sub, 2) . "\n";
echo "GL ({$d1}): Rs " . number_format($gl1, 2) . "\n";
echo "Mismatch: Rs " . number_format($sub - $gl1, 2) . "\n";
