<?php
$backup = 'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql';
$c = file_get_contents($backup);

if (preg_match_all('/INSERT INTO `journal_entries`.*?VALUES\s*\(([^)]+)\);/is', $c, $m)) {
    echo "Total JE rows in backup: " . count($m[1]) . "\n";
    $types = [];
    foreach ($m[1] as $r) {
        $parts = explode(',', $r);
        $type = trim($parts[3] ?? '', " '\"");
        $types[$type] = ($types[$type] ?? 0) + 1;
    }
    print_r($types);
}
