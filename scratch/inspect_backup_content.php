<?php
$backup = 'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql';
$content = file_get_contents($backup);

echo "--- Tables created in backup ---\n";
preg_match_all('/CREATE TABLE [`"]?(\w+)[`"]?/i', $content, $m);
print_r($m[1]);

echo "\n--- Search for 'JV-' or 'Journal' in backup ---\n";
preg_match_all('/.*JV-GOKA-00027.*/', $content, $m2);
print_r($m2[0]);

preg_match_all('/.*JV-00002.*/', $content, $m3);
print_r($m3[0]);

echo "\n--- journal_lines in backup ---\n";
if (preg_match_all('/INSERT INTO `journal_lines`.*?;/s', $content, $m4)) {
    echo "Found " . count($m4[0]) . " blocks\n";
    $total_lines = 0;
    foreach ($m4[0] as $b) {
        $total_lines += substr_count($b, '(');
    }
    echo "Total lines in backup: $total_lines\n";
}
