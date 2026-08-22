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
    echo "================================================\n";
    echo "File: $f\n";
    if (preg_match_all('/INSERT INTO [`"]?customers[`"]?\s*\([^)]+\)\s*VALUES\s*(.+?);/is', $c, $m)) {
        echo "Found customers INSERT in $f\n";
        foreach ($m[1] as $b) {
            preg_match_all('/\(([^)]+)\)/', $b, $tuples);
            foreach ($tuples[1] as $t) {
                if (stripos($t, 'Krishna') !== false) {
                    echo "  Krishna in customers: $t\n";
                }
            }
        }
    }
}
