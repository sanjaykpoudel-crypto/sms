<?php
$f = 'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql';
$c = file_get_contents($f);

echo "Searching for journal_lines in $f:\n";
if (preg_match_all('/INSERT INTO `journal_lines`\s*\([^)]+\)\s*VALUES\s*(.+?);/is', $c, $m)) {
    echo "Found " . count($m[1]) . " INSERT blocks for journal_lines\n";
    $lines = [];
    foreach ($m[1] as $b) {
        preg_match_all('/\(([^)]+)\)/', $b, $tuples);
        foreach ($tuples[1] as $t) {
            $parts = str_getcsv($t, ',', "'");
            $lines[] = $parts;
            if (in_array($parts[0] ?? '', ['882', '117', '469', '587']) || stripos($t, '174') !== false) {
                echo "Matching Line: " . json_encode($parts) . "\n";
            }
        }
    }
    echo "Total journal_lines in backup: " . count($lines) . "\n";
}
