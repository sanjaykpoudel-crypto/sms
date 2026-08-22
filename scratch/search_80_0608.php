<?php
$f = 'C:/Users/USERE/Downloads/sms_db 06-08-26.sql';
$c = file_get_contents($f);

echo "Searching for 484f03c7-9f74-4cc9-8d93-20d5e3c65b78 in $f:\n";
$lines = explode("\n", $c);
foreach ($lines as $ln => $l) {
    if (stripos($l, '484f03c7-9f74-4cc9-8d93-20d5e3c65b78') !== false) {
        echo "Line $ln: " . trim($l) . "\n";
    }
}
