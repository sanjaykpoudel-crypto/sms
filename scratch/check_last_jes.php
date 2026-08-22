<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$last_jes = $db->fetchAll("SELECT * FROM journal_entries ORDER BY je_id DESC LIMIT 10");
print_r($last_jes);
