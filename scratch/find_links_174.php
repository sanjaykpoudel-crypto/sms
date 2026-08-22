<?php
$files = [
    'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql',
    'C:/xampp/htdocs/sms-new/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db 20072026.sql',
    'C:/xampp/htdocs/sms/database/sms_db.sql'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    echo "================================================\n";
    echo "File: $f\n";
    
    // Check transaction_links mentioning 174
    if (preg_match_all('/INSERT INTO `?transaction_links`?[^;]+;/is', $c, $m)) {
        foreach ($m[0] as $block) {
            preg_match_all('/\(([^)]+)\)/', $block, $tuples);
            $found_174 = 0;
            foreach ($tuples[1] as $t) {
                if (stripos($t, "'174'") !== false || stripos($t, ", 174,") !== false || stripos($t, ",174,") !== false) {
                    echo "  Link tuple with 174: $t\n";
                    $found_174++;
                }
            }
            if ($found_174 > 0) {
                echo "  Found $found_174 links with 174 in $f\n";
            }
        }
    }
}
