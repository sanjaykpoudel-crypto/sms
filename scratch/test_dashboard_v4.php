<?php
/**
 * Test script to verify dashboard V4 API data
 * Run this from browser or command line
 */
session_start();
$_SESSION['user_id'] = '1';
$_SESSION['role'] = 'admin';

$_GET['nocache'] = '1';

// Capture the API output
ob_start();
include '../api/get_dashboard_v4.php';
$output = ob_get_clean();

$data = json_decode($output, true);

echo "API Status: " . ($data['status'] ?? 'error') . PHP_EOL . PHP_EOL;

echo "=== KPI DATA ===" . PHP_EOL;
foreach ($data['kpi'] ?? [] as $key => $kpi) {
    echo "$key: value=" . ($kpi['value'] ?? 'N/A') . ", trend=" . ($kpi['trend'] ?? 'N/A') . PHP_EOL;
}

echo PHP_EOL . "=== CASH SUMMARY ===" . PHP_EOL;
print_r($data['cash_summary'] ?? []);

echo PHP_EOL . "=== REMINDERS ===" . PHP_EOL;
print_r($data['reminders'] ?? []);