<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$r = $db->fetchOne("SELECT * FROM journal_lines WHERE jl_id = 59");
print_r($r);
