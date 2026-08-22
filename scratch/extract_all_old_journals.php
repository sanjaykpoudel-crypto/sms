<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

echo "Extracting all journal_entries from $f:\n";
if (preg_match_all('/INSERT INTO `journal_entries`\s*\([^)]+\)\s*VALUES\s*(.+?);/is', $c, $m)) {
    $rows = [];
    foreach ($m[1] as $b) {
        preg_match_all('/\(([^)]+)\)/', $b, $tuples);
        foreach ($tuples[1] as $t) {
            $parts = str_getcsv($t, ',', "'");
            // Check if memo is not a standard Invoice/Payment/Bill or if party_id is not null
            $memo = $parts[6] ?? '';
            $party_id = $parts[3] ?? '';
            $acc_id = $parts[2] ?? '';
            $amount = $parts[5] ?? '';
            $entry_type = $parts[4] ?? '';
            $hdr_id = $parts[1] ?? '';
            
            if (stripos($memo, 'JV-') !== false || stripos($memo, 'Journal') !== false || stripos($memo, 'Opening') !== false || stripos($memo, 'Loan') !== false || stripos($memo, 'Investment') !== false || stripos($memo, 'Adjustment') !== false || stripos($memo, 'Depreciation') !== false || stripos($memo, 'Recharge') !== false || stripos($memo, 'Rent') !== false || stripos($memo, 'Salary') !== false) {
                echo "Journal Row: Hdr: $hdr_id | Acc: $acc_id | Party: $party_id | Type: $entry_type | Amt: $amount | Memo: $memo\n";
            }
        }
    }
}
