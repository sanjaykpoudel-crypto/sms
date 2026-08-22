<?php
$files = [
    'C:/xampp/htdocs/sms/database/sms_db.sql',
    'C:/xampp/htdocs/sms/database/sms_db 20072026.sql',
    'C:/xampp/htdocs/sms1/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db 20072026.sql',
    'C:/xampp/htdocs/sms-new/database/sms_db.sql',
    'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql'
];

$amounts = [
    '429015', '896792', '374671', '33448', '276129', '118000', '6806', '3000', '102.25', '12000', '7700', '53385', '10941', '12680', '20100', '6625.01', '650', '204', '7705'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    echo "================================================\n";
    echo "File: $f\n";
    foreach ($amounts as $amt) {
        $pattern = '/[^\n\r]*' . preg_quote($amt, '/') . '[^\n\r]*/i';
        if (preg_match_all($pattern, $c, $m)) {
            echo "  Match for amount $amt (" . count($m[0]) . "):\n";
            foreach (array_slice($m[0], 0, 3) as $l) {
                echo "    " . substr($l, 0, 200) . "\n";
            }
        }
    }
}
