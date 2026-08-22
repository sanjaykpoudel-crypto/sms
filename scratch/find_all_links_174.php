<?php
$f = 'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql';
$c = file_get_contents($f);

echo "Searching for all transaction_links involving 174:\n";
if (preg_match_all('/INSERT INTO `transaction_links`\s*\([^)]+\)\s*VALUES\s*(.+?);/is', $c, $m)) {
    foreach ($m[1] as $b) {
        preg_match_all('/\(([^)]+)\)/', $b, $tuples);
        foreach ($tuples[1] as $t) {
            $parts = str_getcsv($t, ',', "'");
            if (in_array('174', $parts)) {
                echo "Link: " . json_encode($parts) . "\n";
            }
        }
    }
}
