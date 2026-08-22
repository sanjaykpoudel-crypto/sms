<?php
$files = [
    'C:/xampp/htdocs/sms/database/sms_db.sql',
    'C:/xampp/htdocs/sms/database/sms_db 20072026.sql',
    'C:/xampp/htdocs/sms1/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db 20072026.sql'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    echo "================================================\n";
    echo "File: $f\n";
    if (preg_match_all('/[^\n\r]+JV-[^\n\r]+/i', $c, $m)) {
        echo "Found " . count($m[0]) . " JV matches\n";
        foreach ($m[0] as $l) {
            echo "  " . substr($l, 0, 200) . "\n";
        }
    }
}
