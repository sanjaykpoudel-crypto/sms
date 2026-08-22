<?php
$files = [
    'C:/Users/USERE/Downloads/sms_db 06-08-26.sql',
    'C:/Users/USERE/Downloads/sms_db (3).sql',
    'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    echo "========================================================\n";
    echo "Inspecting accounts in: $f\n";
    $c = file_get_contents($f);
    if (preg_match_all('/INSERT INTO `?accounts`?\s*\((.+?)\)\s*VALUES\s*(.+?);/is', $c, $m)) {
        $cols = str_getcsv($m[1][0], ',', '`');
        $cols = array_map('trim', $cols);
        foreach ($m[2] as $b) {
            preg_match_all('/\(([^)]+)\)/', $b, $tuples);
            foreach ($tuples[1] as $t) {
                $parts = str_getcsv($t, ',', "'");
                $row = [];
                foreach ($cols as $idx => $colName) {
                    $row[$colName] = $parts[$idx] ?? null;
                }
                echo "  ID: {$row['id']} | Code: {$row['account_code']} | Name: {$row['account_name']} | Type: {$row['account_type']} | Subtype: {$row['account_subtype']} | OpenBal: {$row['opening_balance']}\n";
            }
        }
    }
}
