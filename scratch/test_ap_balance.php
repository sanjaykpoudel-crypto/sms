<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/ReportingEngine.php';
$db = db();

$ap_sub = re_get_ap_balance($db);
$ap_gl = re_get_ap_gl_balance($db);

echo "AP Subledger: Rs. " . number_format($ap_sub, 2) . "\n";
echo "AP GL: Rs. " . number_format($ap_gl, 2) . "\n";
echo "Diff: Rs. " . ($ap_sub - $ap_gl) . "\n";
