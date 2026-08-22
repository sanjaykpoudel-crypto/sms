<?php
$backup1 = __DIR__ . '/../database/sms_db.sql';
$backup2 = __DIR__ . '/../database/backups/sms_db_backup_20260822_173727.sql';

foreach ([$backup1, $backup2] as $f) {
    if (file_exists($f)) {
        echo "File: $f (" . filesize($f) . " bytes)\n";
        $content = file_get_contents($f);
        if (preg_match_all('/INSERT INTO [`"]?journal_entries[`"]?.*?;/s', $content, $matches)) {
            echo "  Found " . count($matches[0]) . " INSERT INTO journal_entries blocks\n";
            foreach ($matches[0] as $m) {
                echo "    " . substr($m, 0, 300) . "...\n";
            }
        }
    }
}
