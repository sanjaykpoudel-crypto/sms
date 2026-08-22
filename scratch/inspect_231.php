<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

echo "Lines for JV-20260717-001 (ID 231) in original dump:\n";
if (preg_match_all('/INSERT INTO `journal_entries`\s*\((.+?)\)\s*VALUES\s*(.+?);/is', $c, $m)) {
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
            if (($row['header_id'] ?? $row['transaction_id'] ?? '') == '231') {
                print_r($row);
            }
        }
    }
}
