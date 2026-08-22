<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
print_r($db->fetchAll("DESC customers"));
