<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

echo "Cross-referencing all Journal entries from original dump $f:\n\n";

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

echo "Found " . count($j_headers) . " Journal headers in original dump:\n";
foreach ($j_headers as $hid => $h) {
    echo "Header ID $hid: Txn # {$h['txn_number']} | Date: {$h['txn_date']} | Net: {$h['net_amount']} | Memo: {$h['memo']}\n";
}

// 2. Get all journal lines for these headers
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
    
    echo "\n\n=======================================================\n";
    echo "EXACT ORIGINAL JOURNAL LINES FROM DATABASE BACKUP:\n";
    echo "=======================================================\n";
    foreach ($j_headers as $hid => $h) {
        echo "\n--------------------------------------------------------\n";
        echo "JOURNAL: {$h['txn_number']} (ID: $hid) | Date: {$h['txn_date']} | Amount: Rs. {$h['net_amount']}\n";
        echo "Memo: {$h['memo']}\n";
        if (empty($j_lines[$hid])) {
            echo "  [No lines in old dump]\n";
        } else {
            echo "  Lines count: " . count($j_lines[$hid]) . "\n";
            $tot_dr = 0; $tot_cr = 0;
            foreach ($j_lines[$hid] as $l) {
                $type = $l['entry_type'] ?? '';
                $amt = (float)($l['amount'] ?? 0);
                if ($type === 'debit') $tot_dr += $amt;
                if ($type === 'credit') $tot_cr += $amt;
                echo "    Line ID: {$l['id']} | Type: {$type} | Amt: " . number_format($amt, 2) . " | Acc: {$l['account_id']} | Party: {$l['party_type']} (ID: {$l['party_id']}) | Memo: {$l['memo']}\n";
            }
            echo "  Sum Debit: Rs. " . number_format($tot_dr, 2) . " | Sum Credit: Rs. " . number_format($tot_cr, 2) . "\n";
        }
    }
}
