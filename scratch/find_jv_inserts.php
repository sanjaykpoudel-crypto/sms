<?php
$backup = file_get_contents(__DIR__ . '/../database/sms_db.sql');

echo "--- Search for JV in sms_db.sql ---\n";
if (preg_match_all('/.*JV-[^\n\r]+.*/', $backup, $matches)) {
    foreach (array_slice($matches[0], 0, 30) as $line) {
        echo $line . "\n";
    }
}

echo "\n--- Search for journal_lines in backup ---\n";
if (preg_match_all('/INSERT INTO [`"]?journal_lines[`"]?.*?;/s', $backup, $matches)) {
    echo "Found " . count($matches[0]) . " journal_lines insert blocks\n";
    foreach ($matches[0] as $m) {
        echo substr($m, 0, 300) . "...\n";
    }
}
