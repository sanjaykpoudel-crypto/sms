<?php
$files = [
    'C:/Users/USERE/Downloads/sms_db (3).sql',
    'C:/Users/USERE/Downloads/sms_db 06-08-26.sql',
    'C:/Users/USERE/Downloads/sms_db 16-06-26.sql'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    echo "================================================\n";
    echo "File: $f\n";
    if (preg_match_all('/[^\n\r]*JV-GOKA-00008[^\n\r]*/i', $c, $m)) {
        foreach ($m[0] as $l) echo "  $l\n";
    }
    if (preg_match_all('/[^\n\r]*247453[^\n\r]*/i', $c, $m)) {
        foreach ($m[0] as $l) echo "  $l\n";
    }
}
