<?php
require_once 'database/DBConnection.php';
require_once 'api/ReportingEngine.php';
$db = db();

$bs = re_get_balance_sheet($db, date('Y-m-d'));

echo "=== ALL LIABILITY ACCOUNTS ON BALANCE SHEET ===\n\n";
foreach ($bs['liabilities'] as $l) {
    echo sprintf("ID: %-4d | Name: %-35s | Subtype: %-25s | Balance: %12.2f\n",
        $l['id'], $l['name'], $l['subtype'], $l['balance']
    );
}
