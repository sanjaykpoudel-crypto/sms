<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$je = $db->fetchOne("SELECT * FROM journal_entries WHERE je_id = 1064");
print_r($je);

$lines = $db->fetchAll("SELECT * FROM journal_lines WHERE je_id = 1064");
print_r($lines);
