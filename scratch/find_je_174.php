<?php
$f = 'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql';
$c = file_get_contents($f);

echo "Searching for journal_entries in $f where memo has 'Opening' or transaction_id is 174:\n";
if (preg_match_all('/INSERT INTO `journal_entries`\s*\([^)]+\)\s*VALUES\s*(.+?);/is', $c, $m)) {
    foreach ($m[1] as $b) {
        preg_match_all('/\(([^)]+)\)/', $b, $tuples);
        foreach ($tuples[1] as $t) {
            $parts = str_getcsv($t, ',', "'");
            if (($parts[1] ?? '') == '174' || stripos($t, 'Opening receivable') !== false || stripos($t, 'JV-00002') !== false) {
                echo "Matching JE: " . json_encode($parts) . "\n";
            }
        }
    }
}
