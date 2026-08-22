<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

preg_match_all('/INSERT INTO `?([a-zA-Z0-9_]+)`?/i', $c, $m);
$tables = array_unique($m[1]);
echo "Tables in $f:\n";
print_r($tables);
