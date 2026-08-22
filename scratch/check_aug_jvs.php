<?php
$f = 'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql';
$c = file_get_contents($f);

$ids = [643775, 708565, 797608, 798903, 964351, 143134, 158622, 228767, 397548];

echo "Checking journal lines for August 13-22 transactions in $f:\n";
foreach ($ids as $id) {
    if (preg_match_all("/\(\s*'\d+',\s*'$id',/i", $c, $m)) {
        echo "Found matches for ID $id in journal_entries\n";
    }
}
