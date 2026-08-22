<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$cols = $db->fetchAll("DESCRIBE accounts");
print_r($cols);
