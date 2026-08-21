<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../forms/modules/reports/rpt_helpers.php';
$db = db();

$_GET['date_from'] = '2026-08-01';
$_GET['date_to'] = '2026-08-20';
$_GET['location_id'] = '1';

ob_start();
include __DIR__ . '/../forms/modules/reports/sales/top_profit_items_list.php';
$out = ob_get_clean();

echo "Report execution successful. Output length: " . strlen($out) . " bytes.\n";
