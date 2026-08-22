<?php
$f = 'C:/xampp/htdocs/sms1/database/sms_db.sql';
$c = file_get_contents($f);

echo "Searching for journal_entries in $f:\n";
if (preg_match_all('/INSERT INTO [`"]?journal_entries[`"]?\s*\([^)]+\)\s*VALUES\s*(.+?);/is', $c, $m)) {
    foreach ($m[1] as $block) {
        preg_match_all('/\(([^)]+)\)/', $block, $tuples);
        foreach ($tuples[1] as $t) {
            if (stripos($t, 'c9c03a99-b41e-4d26-8ae2-ab8552835395') !== false) {
                echo "Found JE line: $t\n";
            }
        }
    }
}
