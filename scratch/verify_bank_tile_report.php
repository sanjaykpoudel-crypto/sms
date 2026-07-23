<?php
$_SESSION['user_id'] = 'usr-admin-001';
$_GET['account_id'] = 'all_bank';

ob_start();
include __DIR__ . '/../forms/modules/reports/financial/cash_book_list.php';
$html = ob_get_clean();

if (strpos($html, 'All Bank Accounts') !== false && strpos($html, 'selected') !== false) {
    echo "SUCCESS: Cash Book report executes with 'All Bank Accounts' selected in dropdown!\n";
} elseif (strpos($html, 'All Bank Accounts') !== false) {
    echo "SUCCESS: Cash Book report includes 'All Bank Accounts' option!\n";
} else {
    echo "FAILED: All Bank Accounts option missing.\n";
}

// Test General Ledger as well
ob_start();
include __DIR__ . '/../forms/modules/reports/financial/general_ledger_list.php';
$gl_html = ob_get_clean();

if (strpos($gl_html, 'All Bank Accounts') !== false) {
    echo "SUCCESS: General Ledger report includes 'All Bank Accounts' option!\n";
} else {
    echo "FAILED: GL missing All Bank Accounts option.\n";
}
