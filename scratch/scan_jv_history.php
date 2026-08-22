<?php
$files = [
    'C:/xampp/htdocs/sms/database/sms_db.sql',
    'C:/xampp/htdocs/sms/database/sms_db 20072026.sql',
    'C:/xampp/htdocs/sms1/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db 20072026.sql',
    'C:/xampp/htdocs/sms-new/database/sms_db.sql',
    'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql'
];

$jv_ids = [174, 397548, 228767, 158622, 143134, 234, 231, 177, 163, 140, 122, 114, 108, 100, 99, 80, 30, 964351, 798903, 797608, 708565, 643775];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    echo "==================================================\n";
    echo "Scanning: $f\n";
    $content = file_get_contents($f);
    
    foreach ($jv_ids as $id) {
        $pattern = '/INSERT INTO [`"]?(journal_entries|journal_lines)[`"]?.*?VALUES\s*\([^)]*[\'"]?' . $id . '[\'"]?[^)]*\)/si';
        if (preg_match_all($pattern, $content, $m)) {
            echo "  Found match for $id in $f:\n";
            foreach ($m[0] as $match) {
                echo "    " . substr($match, 0, 200) . "\n";
            }
        }
    }
}
