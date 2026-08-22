<?php
$f = 'C:/Users/USERE/Downloads/sms_db (3).sql';
$c = file_get_contents($f);

echo "Extracting all journal entries from original dump $f:\n";

// Find CREATE TABLE `journal_entries` to see column order
if (preg_match('/CREATE TABLE `journal_entries`\s*\((.+?)\)\s*ENGINE/is', $c, $m)) {
    echo "Table schema:\n" . $m[1] . "\n\n";
}

// Find all INSERT INTO `journal_entries`
if (preg_match_all('/INSERT INTO `journal_entries`\s*\((.+?)\)\s*VALUES\s*(.+?);/is', $c, $m)) {
    $cols = str_getcsv($m[1][0], ',', '`');
    $cols = array_map('trim', $cols);
    echo "Columns: " . implode(', ', $cols) . "\n";
    
    $all_journals = [];
    foreach ($m[2] as $b) {
        preg_match_all('/\(([^)]+)\)/', $b, $tuples);
        foreach ($tuples[1] as $t) {
            $parts = str_getcsv($t, ',', "'");
            $row = [];
            foreach ($cols as $idx => $colName) {
                $row[$colName] = $parts[$idx] ?? null;
            }
            $hdr_id = $row['header_id'] ?? $row['transaction_id'] ?? '';
            $all_journals[$hdr_id][] = $row;
        }
    }
    
    echo "\nGrouped by header_id / transaction_id:\n";
    foreach ($all_journals as $hid => $lines) {
        echo "--------------------------------------------------------\n";
        echo "Header ID: $hid (Total lines: " . count($lines) . ")\n";
        $tot_dr = 0; $tot_cr = 0;
        foreach ($lines as $l) {
            $type = $l['entry_type'] ?? '';
            $amt = (float)($l['amount'] ?? 0);
            if ($type === 'debit') $tot_dr += $amt;
            if ($type === 'credit') $tot_cr += $amt;
            echo "  ID: {$l['id']} | Type: {$type} | Amt: {$amt} | Acc: {$l['account_id']} | Party: {$l['party_type']} ({$l['party_id']}) | Memo: {$l['memo']}\n";
        }
        echo "  Sum Debit: Rs. " . number_format($tot_dr, 2) . " | Sum Credit: Rs. " . number_format($tot_cr, 2) . "\n";
    }
}
