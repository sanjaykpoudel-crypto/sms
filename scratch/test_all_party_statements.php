<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;

require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$customers = [
    7 => 'Prabesh (Cust 7)',
    1 => 'Cust 1',
    21 => 'Krishna Lomus (Cust 21)',
    4 => 'Bluebird (Cust 4)',
    20 => 'Prabesh Khanal (Cust 20)',
    16 => 'Chiyapul (Cust 16)',
    9 => 'Cust 9'
];

echo "========================================================\n";
echo " VERIFYING CUSTOMER STATEMENTS FOR OPENING BALANCES\n";
echo "========================================================\n";

foreach ($customers as $cid => $cname) {
    $_GET = [
        'customer_id' => $cid,
        'from_date' => '2026-07-01',
        'to_date' => '2026-08-31'
    ];
    ob_start();
    include __DIR__ . '/../forms/modules/reports/customers/statement_list.php';
    $html = ob_get_clean();
    $has_jv = str_contains($html, 'JV-00002');
    echo "[CHECK] $cname | Includes JV-00002: " . ($has_jv ? 'YES' : 'NO') . " | Rendered bytes: " . strlen($html) . "\n";
}

echo "\n========================================================\n";
echo " VERIFYING VENDOR AP REGISTERS FOR OPENING BALANCES\n";
echo "========================================================\n";

$vendors = [
    5 => 'Friendship suppliers (Vend 5)',
    7 => 'Manish (Vend 7)'
];

foreach ($vendors as $vid => $vname) {
    $_GET = [
        'vendor_id' => $vid,
        'date_from' => '2026-07-01',
        'date_to' => '2026-08-31'
    ];
    ob_start();
    include __DIR__ . '/../forms/modules/reports/vendors/ap_register_list.php';
    $html = ob_get_clean();
    $has_jv = str_contains($html, 'JV-00002');
    echo "[CHECK] $vname | Includes JV-00002: " . ($has_jv ? 'YES' : 'NO') . " | Rendered bytes: " . strlen($html) . "\n";
}
