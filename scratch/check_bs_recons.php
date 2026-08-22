<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
$db = db();

$as_of = '2026-08-22';
$location_id = '1';

$ar_subledger   = re_get_ar_balance($db, $as_of, $location_id);
$ar_gl          = re_get_ar_gl_balance($db, $as_of, $location_id);
$ar_ok          = abs($ar_subledger - $ar_gl) < 0.05;

$ap_subledger   = re_get_ap_balance($db, $as_of, $location_id);
$ap_gl          = re_get_ap_gl_balance($db, $as_of, $location_id);
$ap_ok          = abs($ap_subledger - $ap_gl) < 0.05;

$inv_subledger  = re_get_inventory_subledger($db, $as_of, $location_id);
$inv_gl         = re_get_inventory_gl_balance($db, $as_of, $location_id);
$inv_diff       = abs($inv_subledger - $inv_gl);
$inv_ok         = ($inv_diff < 0.05);

echo "AR Sub: $ar_subledger | GL: $ar_gl | OK: " . ($ar_ok ? 'YES' : 'NO') . "\n";
echo "AP Sub: $ap_subledger | GL: $ap_gl | OK: " . ($ap_ok ? 'YES' : 'NO') . "\n";
echo "Inv Sub: $inv_subledger | GL: $inv_gl | OK: " . ($inv_ok ? 'YES' : 'NO') . "\n";
