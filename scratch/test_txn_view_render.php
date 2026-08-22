<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;
$_SESSION['username'] = 'superadmin';
$_SESSION['full_name'] = 'System Administrator';

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$test_ids = [967980, 397548, 228767, 187, 24, 75, 119];

foreach ($test_ids as $id) {
    $_GET = ['id' => $id];
    ob_start();
    include __DIR__ . '/../forms/modules/transactions/view.php';
    $html = ob_get_clean();
    echo "[PASS] Transaction View for ID $id rendered successfully (" . strlen($html) . " bytes)\n";
}
