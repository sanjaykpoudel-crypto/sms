<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$lines = $db->fetchAll("
    SELECT jl.* 
    FROM journal_lines jl 
    JOIN journal_entries je ON jl.je_id = je.je_id 
    WHERE je.transaction_id = 199
");
print_r($lines);
