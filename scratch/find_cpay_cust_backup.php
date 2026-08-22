<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

$ids = [220, 69, 85, 171, 193];
foreach ($ids as $id) {
    echo "=== ID $id in backup ===\n";
    if (preg_match_all("/[^\n\r]*'CPAY-0000[^\n\r]*/i", $c, $m)) {
        foreach ($m[0] as $l) {
            if (str_contains($l, "'$id'") || str_contains($l, ", $id,")) {
                echo "  $l\n";
            }
        }
    }
}
