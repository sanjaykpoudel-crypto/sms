<?php
require_once 'database/DBConnection.php';
$db = db();
$rows = $db->fetchAll("SELECT id, account_name, account_type, account_subtype, normal_balance FROM accounts WHERE is_deleted = 0 LIMIT 50");
print_r($rows);
