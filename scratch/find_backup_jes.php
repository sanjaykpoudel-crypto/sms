<?php
$backup = 'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql';
$c = file_get_contents($backup);

echo "Searching for all journal_entries with je_type = 'JOURNAL' or similar in backup:\n";
if (preg_match_all('/INSERT INTO `journal_entries`.*?VALUES\s*\(([^)]+)\);/is', $c, $m)) {
    foreach ($m[1] as $val) {
        if (stripos($val, 'JOURNAL') !== false || stripos($val, 'OPENING') !== false || stripos($val, 'JV-') !== false) {
            echo "JE Header in backup: $val\n";
        }
    }
}
