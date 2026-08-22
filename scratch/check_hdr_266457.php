<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$h = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = 266457");
print_r($h);
