<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$links = $db->fetchAll("SELECT * FROM transaction_links WHERE child_id = 174 OR parent_id = 174");
print_r($links);
