<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

echo "Searching for 174 or JV-00002 or Opening receivable in $f:\n";
$lines = explode("\n", $c);
foreach ($lines as $ln => $l) {
    if (stripos($l, 'JV-00002') !== false || stripos($l, 'Opening receivable') !== false || stripos($l, "'174'") !== false || stripos($l, ", 174,") !== false || stripos($l, ",174,") !== false) {
        echo "Line " . ($ln+1) . ": " . substr(trim($l), 0, 200) . "\n";
    }
}
