<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- Check transaction_lines for JV headers ---\n";
$tlines = $db->fetchAll("SELECT tl.* FROM transaction_lines tl JOIN transaction_headers th ON tl.header_id = th.id WHERE th.txn_type = 'Journal'");
echo "Count in transaction_lines: " . count($tlines) . "\n";
print_r($tlines);

echo "\n--- Check backup files in database/ or scratch/ ---\n";
$files = glob(__DIR__ . '/../*.sql');
$files = array_merge($files, glob(__DIR__ . '/../database/*.sql'));
$files = array_merge($files, glob(__DIR__ . '/../database/migrations/*.sql'));
$files = array_merge($files, glob(__DIR__ . '/../database/backups/*.sql'));
$files = array_merge($files, glob(__DIR__ . '/../backups/*.sql'));
$files = array_merge($files, glob(__DIR__ . '/../scratch/*.sql'));
print_r($files);
