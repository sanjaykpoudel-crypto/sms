<?php
$_SESSION['user_id'] = 'usr-admin-001';

// Test 1: Multiple accounts array
$_GET['account_id'] = ['acc-1010', 'acc-1020'];
ob_start();
include __DIR__ . '/../forms/modules/reports/financial/general_ledger_list.php';
$html1 = ob_get_clean();

// Check for custom UI multiselect elements
if (strpos($html1, 'ms-container') !== false && 
    strpos($html1, 'ms-btn') !== false && 
    strpos($html1, 'ms-dropdown') !== false && 
    strpos($html1, 'type="checkbox"') !== false &&
    strpos($html1, 'Search accounts...') !== false) {
    echo "SUCCESS: Custom UI multi-select dropdown widget with search bar and checkboxes rendered perfectly!\n";
} else {
    echo "FAILED: Custom UI multi-select structure missing.\n";
}
