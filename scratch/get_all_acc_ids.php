<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$accs = $db->fetchAll("SELECT id, account_name, account_type, account_subtype FROM accounts ORDER BY id ASC");
foreach ($accs as $a) {
    echo "ID: {$a['id']} | Name: {$a['account_name']} | Type: {$a['account_type']} | Subtype: {$a['account_subtype']}\n";
}
