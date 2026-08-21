<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$cols = $db->fetchAll("DESCRIBE items");
print_r($cols);
