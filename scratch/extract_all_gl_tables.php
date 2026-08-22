<?php
$files = [
    'C:/Users/USERE/Downloads/sms_db (3).sql',
    'C:/Users/USERE/Downloads/sms_db 06-08-26.sql',
    'C:/Users/USERE/Downloads/sms_db 16-06-26.sql'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    echo "=======================================================\n";
    echo "Inspecting GL tables in: $f\n";
    $c = file_get_contents($f);
    
    // Check gl_journal_headers
    if (preg_match_all('/INSERT INTO `?gl_journal_headers`?[^;]+;/is', $c, $m)) {
        echo "Found " . count($m[0]) . " INSERT blocks for gl_journal_headers\n";
        foreach ($m[0] as $stmt) {
            preg_match_all('/\(([^)]+)\)/', $stmt, $tuples);
            foreach ($tuples[1] as $t) {
                echo "  GL Header: $t\n";
            }
        }
    }

    // Check gl_journal_lines
    if (preg_match_all('/INSERT INTO `?gl_journal_lines`?[^;]+;/is', $c, $m)) {
        echo "Found " . count($m[0]) . " INSERT blocks for gl_journal_lines\n";
        foreach ($m[0] as $stmt) {
            preg_match_all('/\(([^)]+)\)/', $stmt, $tuples);
            foreach ($tuples[1] as $t) {
                echo "  GL Line: $t\n";
            }
        }
    }

    // Check transaction_lines where line is journal/opening
    if (preg_match_all('/INSERT INTO `?transaction_lines`?[^;]+;/is', $c, $m)) {
        echo "Found " . count($m[0]) . " INSERT blocks for transaction_lines\n";
        foreach ($m[0] as $stmt) {
            preg_match_all('/\(([^)]+)\)/', $stmt, $tuples);
            foreach ($tuples[1] as $t) {
                if (stripos($t, '174') !== false || stripos($t, 'Opening') !== false || stripos($t, 'JV-') !== false || stripos($t, 'Krishna') !== false) {
                    echo "  Txn Line: $t\n";
                }
            }
        }
    }
}
