<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$jes = $db->fetchAll("SELECT * FROM journal_entries WHERE transaction_id = 236");
print_r($jes);

$jls = $db->fetchAll("SELECT * FROM journal_lines WHERE je_id IN (SELECT je_id FROM journal_entries WHERE transaction_id = 236)");
print_r($jls);
