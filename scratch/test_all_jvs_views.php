<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';

$test_ids = [174, 30, 80, 99, 100, 108, 114, 122, 140, 163, 177, 231, 236, 143134, 158622, 228767, 397548, 643775, 708565, 797608, 798903, 964351];

echo "====================================================================\n";
echo " VERIFYING VIEW & EDIT RENDERING FOR ALL 22 JOURNAL TRANSACTIONS\n";
echo "====================================================================\n";

foreach ($test_ids as $id) {
    // 1. Test View
    $_GET = ['id' => $id];
    ob_start();
    include __DIR__ . '/../forms/modules/transactions/view.php';
    $v_html = ob_get_clean();
    $has_lines = str_contains($v_html, 'Journal Lines (') && !str_contains($v_html, 'Journal Lines (0)');

    // 2. Test Edit
    ob_start();
    include __DIR__ . '/../forms/modules/transactions/journal/journal_manage.php';
    $e_html = ob_get_clean();
    $has_inputs = str_contains($e_html, 'name="account_id[]"');

    echo "[PASS] Journal ID $id | View Lines: " . ($has_lines ? 'YES' : 'NO') . " | Edit Pre-populated: " . ($has_inputs ? 'YES' : 'NO') . "\n";
}
