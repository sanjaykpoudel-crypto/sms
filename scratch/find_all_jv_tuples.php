<?php
$files = [
    'C:/xampp/htdocs/sms/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db 20072026.sql',
    'C:/xampp/htdocs/sms-new/database/sms_db.sql'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    if (preg_match_all('/INSERT INTO [`"]?journal_entries[`"]?\s*\([^)]+\)\s*VALUES\s*(.+?);/is', $c, $m)) {
        echo "Found in $f (" . count($m[1]) . " blocks):\n";
        foreach ($m[1] as $val_block) {
            // Split tuples
            preg_match_all('/\(([^)]+)\)/', $val_block, $tuples);
            foreach ($tuples[1] as $t) {
                if (stripos($t, 'JV-') !== false || stripos($t, 'Opening') !== false || stripos($t, '174') !== false || stripos($t, 'Krishna') !== false) {
                    echo "  Tuple: $t\n";
                }
            }
        }
    }
}
