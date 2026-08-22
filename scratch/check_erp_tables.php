<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$erps = $db->fetchAll("SHOW TABLES LIKE 'erp_%'");
print_r($erps);
