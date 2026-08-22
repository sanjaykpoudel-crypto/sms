<?php
$backup = 'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql';
$content = file_get_contents($backup);

$jv_ids = [174, 397548, 228767, 158622, 143134, 234, 231, 177, 163, 140, 122, 114, 108, 100, 99, 80, 30, 964351, 798903, 797608, 708565, 643775];

foreach ($jv_ids as $id) {
    if (preg_match_all("/INSERT INTO `journal_entries`.*?VALUES \('(\d+)', '$id',.*?\);/s", $content, $m)) {
        echo "JV Transaction $id has JE: " . $m[1][0] . "\n";
        $je_id = $m[1][0];
        if (preg_match_all("/INSERT INTO `journal_lines`.*?VALUES \('(\d+)', '$je_id',.*?\);/s", $content, $m2)) {
            echo "  Lines for JE $je_id: " . count($m2[0]) . "\n";
            foreach ($m2[0] as $l) {
                echo "    $l\n";
            }
        }
    } else {
        // Also check if any old journal_entries table exists with (id, header_id, account_id, amount, entry_type...)
        if (preg_match_all("/INSERT INTO `journal_entries`.*?VALUES \([^)]*'$id'[^)]*\);/s", $content, $m_old)) {
            echo "JV Transaction $id found in old-style journal_entries:\n";
            foreach ($m_old[0] as $old_l) {
                echo "  $old_l\n";
            }
        }
    }
}
