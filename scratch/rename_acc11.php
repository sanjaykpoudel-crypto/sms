<?php
require_once 'database/DBConnection.php';
$db = db();

$db->execute("UPDATE accounts SET account_name = 'Other Current Liabilities' WHERE id = 11 AND account_name = 'Liabilities'");
echo "Account ID 11 successfully updated to 'Other Current Liabilities'.\n";
