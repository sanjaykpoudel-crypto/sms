<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

$ids = [220, 69, 85, 171, 193];
foreach ($ids as $id) {
    echo "Links for ID $id:\n";
    if (preg_match_all("/\([^)]*,\s*$id\s*,[^)]*\)/i", $c, $m)) {
        print_r($m[0]);
    }
}
