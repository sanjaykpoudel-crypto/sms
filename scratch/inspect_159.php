<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

print_r($db->fetchOne("SELECT * FROM transaction_headers WHERE id = 159"));
print_r($db->fetchAll("SELECT jl.*, a.account_name FROM journal_lines jl JOIN journal_entries je ON jl.je_id = je.je_id JOIN accounts a ON jl.account_id = a.id WHERE je.transaction_id = 159"));
