<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$ar_accs = $db->fetchAll("SELECT * FROM accounts WHERE account_subtype IN ('Accounts Receivable', 'receivable', 'AR') OR account_name LIKE '%Receivable%'");
print_r($ar_accs);
