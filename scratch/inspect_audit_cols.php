<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$cols = $db->fetchAll("SHOW COLUMNS FROM audit_logs");
print_r($cols);
$samples = $db->fetchAll("SELECT * FROM audit_logs WHERE table_name = 'transaction_headers' OR table_name = 'journal_entries' OR table_name = 'journal_lines' ORDER BY id DESC LIMIT 20");
print_r($samples);
