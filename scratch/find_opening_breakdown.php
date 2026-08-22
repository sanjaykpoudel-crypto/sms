<?php
$files = [
    'C:/xampp/htdocs/sms1/database/sms_db.sql',
    'C:/xampp/htdocs/sms1/database/sms_db 20072026.sql',
    'C:/xampp/htdocs/sms/database/sms_db.sql',
    'C:/xampp/htdocs/sms/database/sms_db 20072026.sql'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    echo "================================================\n";
    echo "File: $f\n";
    
    // Search for 429015 or opening receivable
    if (preg_match_all('/[^\n\r]*429015[^\n\r]*/i', $c, $m)) {
        echo "Matches for 429015 in $f: " . count($m[0]) . "\n";
        foreach ($m[0] as $l) echo "  $l\n";
    }
}
