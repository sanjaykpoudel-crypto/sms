<?php
$files = [
    'C:/Users/USERE/Downloads/sms_db (3).sql',
    'C:/Users/USERE/Downloads/sms_db 06-08-26.sql',
    'C:/Users/USERE/Downloads/sms_db 16-06-26.sql',
    'C:/Users/USERE/Downloads/sms_db (2).sql',
    'C:/Users/USERE/Downloads/sms_db new.sql',
    'C:/xampp/htdocs/sms-new/database/sms_db.sql',
    'C:/xampp/htdocs/sms-new/database/backups/sms_db_backup_20260822_173727.sql'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    echo "=======================================================\n";
    echo "Inspecting: $f (" . filesize($f) . " bytes)\n";
    $c = file_get_contents($f);
    
    // Check if journal_entries table exists and has rows
    if (preg_match_all('/INSERT INTO `?journal_entries`?[^;]+;/is', $c, $m)) {
        echo "  Found " . count($m[0]) . " INSERT statements for journal_entries\n";
        $tuples_count = 0;
        foreach ($m[0] as $stmt) {
            preg_match_all('/\(([^)]+)\)/', $stmt, $tuples);
            $tuples_count += count($tuples[1]);
            foreach ($tuples[1] as $t) {
                if (stripos($t, 'Opening') !== false || stripos($t, 'JV-') !== false || stripos($t, '174') !== false || stripos($t, '429015') !== false) {
                    echo "    JE Sample: " . substr(trim($t), 0, 150) . "...\n";
                }
            }
        }
        echo "  Total JE rows: $tuples_count\n";
    } else {
        echo "  No INSERT INTO journal_entries\n";
    }

    // Check if journal_lines table exists
    if (preg_match_all('/INSERT INTO `?journal_lines`?[^;]+;/is', $c, $m)) {
        echo "  Found " . count($m[0]) . " INSERT statements for journal_lines\n";
    } else {
        echo "  No INSERT INTO journal_lines\n";
    }
}
