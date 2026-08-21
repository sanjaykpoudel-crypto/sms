<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../forms/modules/reports/rpt_helpers.php';
$db = db();

// Test case 1: Page open without params
$_GET = ['page' => 'reports/sales/top_profit_items'];
$default_from = date('Y-m-01');
$date_from = $_GET['date_from'] ?? $default_from;
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$loc_sql = rpt_location_sql('h');

echo "Initial load: date_from={$date_from}, date_to={$date_to}, loc_sql={$loc_sql}\n";

// Test case 2: User runs filter with location 1
$_GET = [
    'page' => 'reports/sales/top_profit_items',
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-20',
    'location_id' => '1'
];
$date_from = $_GET['date_from'];
$date_to = $_GET['date_to'];
$loc_sql = rpt_location_sql('h');

echo "Filtered load: date_from={$date_from}, date_to={$date_to}, loc_sql={$loc_sql}\n";
