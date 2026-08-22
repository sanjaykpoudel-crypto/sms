<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$r = $db->fetchAll("SELECT * FROM journal_lines WHERE jl_id IN (882, 117, 469, 587)");
print_r($r);
