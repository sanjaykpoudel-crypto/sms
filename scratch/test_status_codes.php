<?php
require_once 'database/DBConnection.php';
$db = db();
$rows = $db->fetchAll("SELECT * FROM reference_codes WHERE type = 'status'");
echo "Count of status records in reference_codes: " . count($rows) . "\n";
print_r($rows);
