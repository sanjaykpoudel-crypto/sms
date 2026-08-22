<?php
$f = 'C:/xampp/htdocs/sms1/database/sms_db.sql';
$c = file_get_contents($f);

$uuids = ['41827908-3606-4639-9c96-809851f36d23', 'c9c03a99-b41e-4d26-8ae2-ab8552835395'];

foreach ($uuids as $u) {
    echo "=== Lines for UUID: $u ===\n";
    preg_match_all("/INSERT INTO `journal_entries`.*?VALUES\s*(.+?);/is", $c, $m);
    foreach ($m[1] as $block) {
        preg_match_all('/\(([^)]+)\)/', $block, $tuples);
        foreach ($tuples[1] as $t) {
            if (stripos($t, $u) !== false) {
                echo "  $t\n";
            }
        }
    }
}
