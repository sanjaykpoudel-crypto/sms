<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- All Tables in Database ---\n";
$tables = $db->fetchAll("SHOW TABLES");
foreach ($tables as $t) {
    $tname = array_values($t)[0];
    $count = $db->fetchOne("SELECT COUNT(*) as c FROM `$tname`")['c'];
    echo "$tname: $count rows\n";
}

echo "\n--- Columns of journal_entries ---\n";
$cols = $db->fetchAll("SHOW COLUMNS FROM journal_entries");
print_r($cols);

echo "\n--- Columns of journal_lines ---\n";
$cols2 = $db->fetchAll("SHOW COLUMNS FROM journal_lines");
print_r($cols2);

echo "\n--- Sample rows in journal_entries ---\n";
$samples = $db->fetchAll("SELECT * FROM journal_entries LIMIT 10");
print_r($samples);
