<?php
$dump = file_get_contents('C:/xampp/htdocs/sms-new/database/sms_db.sql');

echo "--- Search for 'Opening receivable and payable' or '174' in dump ---\n";
if (preg_match_all('/[^\n\r]+Opening receivable and payable[^\n\r]+/', $dump, $m)) {
    foreach ($m[0] as $l) {
        echo $l . "\n";
    }
}

if (preg_match_all('/[^\n\r]+JV-00002[^\n\r]+/', $dump, $m2)) {
    foreach ($m2[0] as $l) {
        echo $l . "\n";
    }
}
