<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$accs = $db->fetchAll("SELECT id, account_number, account_name, account_type, account_subtype FROM accounts WHERE is_active = 1 ORDER BY account_number ASC");
foreach ($accs as $a) {
    echo "ID: {$a['id']} | Number: {$a['account_number']} | Name: {$a['account_name']} | Subtype: {$a['account_subtype']}\n";
}
