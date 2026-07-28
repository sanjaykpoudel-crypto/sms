<?php
require_once 'database/DBConnection.php';
$db = db();
$rows = $db->fetchAll("SELECT * FROM reference_codes WHERE type IN ('tax', 'tax_code', 'vat')");
echo "Count of tax records in reference_codes: " . count($rows) . "\n";
print_r($rows);
