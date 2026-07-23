<?php
$_SESSION['user_id'] = 'usr-admin-001';
$_GET['account_type'] = 'bank';

ob_start();
include __DIR__ . '/../forms/modules/reports/financial/general_ledger_list.php';
$html = ob_get_clean();

if (strpos($html, 'General Ledger') !== false && strpos($html, 'type="checkbox"') !== false) {
    echo "SUCCESS: General Ledger executes cleanly for account_type=bank with bank accounts selected!\n";
} else {
    echo "FAILED: General Ledger execution failed.\n";
}

// Test Cash Book delegation to General Ledger
$_GET = ['account_type' => 'bank'];
ob_start();
include __DIR__ . '/../forms/modules/reports/financial/cash_book_list.php';
$cb_html = ob_get_clean();

if (strpos($cb_html, 'General Ledger') !== false) {
    echo "SUCCESS: Cash Book delegates cleanly to General Ledger report!\n";
} else {
    echo "FAILED: Cash Book delegation failed.\n";
}
