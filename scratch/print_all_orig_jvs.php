<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

// 1. Get all transaction_headers with txn_type = 'Journal' or 'journal_entry'
$j_headers = [];
if (preg_match_all('/INSERT INTO `transaction_headers`\s*\((.+?)\)\s*VALUES\s*(.+?);/is', $c, $m)) {
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
            if (in_array(strtolower($row['txn_type'] ?? ''), ['journal', 'journal_entry'])) {
                $j_headers[$row['id']] = $row;
            }
        }
    }
}

// 2. Get all journal lines
if (preg_match_all('/INSERT INTO `journal_entries`\s*\((.+?)\)\s*VALUES\s*(.+?);/is', $c, $m)) {
    $cols = str_getcsv($m[1][0], ',', '`');
    $cols = array_map('trim', $cols);
    
    $j_lines = [];
    foreach ($m[2] as $b) {
        preg_match_all('/\(([^)]+)\)/', $b, $tuples);
        foreach ($tuples[1] as $t) {
            $parts = str_getcsv($t, ',', "'");
            $row = [];
            foreach ($cols as $idx => $colName) {
                $row[$colName] = $parts[$idx] ?? null;
            }
            $hid = $row['header_id'] ?? $row['transaction_id'] ?? '';
            if (isset($j_headers[$hid])) {
                $j_lines[$hid][] = $row;
            }
        }
    }
    
    foreach ($j_headers as $hid => $h) {
        echo "========================================================\n";
        echo "HEADER ID: $hid | TXN: {$h['txn_number']} | DATE: {$h['txn_date']} | NET: {$h['net_amount']}\n";
        echo "MEMO: {$h['memo']}\n";
        if (empty($j_lines[$hid])) {
            echo "  (No lines in backup)\n";
        } else {
            foreach ($j_lines[$hid] as $l) {
                echo "  Line {$l['id']}: {$l['entry_type']} | Rs. {$l['amount']} | Acc: {$l['account_id']} | Party: {$l['party_type']} ({$l['party_id']}) | Memo: {$l['memo']}\n";
            }
        }
    }
}
