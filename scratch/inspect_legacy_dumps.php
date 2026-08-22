<?php
$files = [
    'C:/xampp/htdocs/sms/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db.sql',
    'C:/xampp/htdocs/sms-new/database/sms_db.sql',
    'C:/xampp/htdocs/sms/database/sms_db 20072026.sql'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        echo "================================================\n";
        echo "File: $f (" . filesize($f) . " bytes)\n";
        $c = file_get_contents($f);
        if (preg_match('/CREATE TABLE [`"]?journal_entries[`"]?.*?\);/s', $c, $m)) {
            echo "Schema:\n" . $m[0] . "\n";
        }
        if (preg_match_all('/INSERT INTO [`"]?journal_entries[`"]?.*?;/s', $c, $m_ins)) {
            echo "Found " . count($m_ins[0]) . " INSERT blocks into journal_entries\n";
            foreach ($m_ins[0] as $ins) {
                echo substr($ins, 0, 500) . "...\n";
            }
        }
    }
}
