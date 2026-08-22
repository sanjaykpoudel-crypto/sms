<?php
$f = 'C:/xampp/htdocs/sms1/database/sms_db.sql';
$c = file_get_contents($f);

echo "Searching for krishna uuid in $f:\n";
preg_match_all('/[^\n\r]+e4098426-0a3c-4fca-a553-cb44d78f6cb9[^\n\r]+/i', $c, $m);
foreach ($m[0] as $l) {
    echo $l . "\n";
}

echo "\nSearching for 'krishna' in $f:\n";
preg_match_all('/[^\n\r]+krishna[^\n\r]+/i', $c, $m2);
foreach ($m2[0] as $l) {
    echo $l . "\n";
}
