<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

echo "--- Check migration_audit_log ---\n";
$migs = $db->fetchAll("SELECT * FROM migration_audit_log");
print_r($migs);

echo "\n--- Check audit_logs where table_name = 'journal_entries' or table_name = 'journal_lines' ---\n";
$j_logs = $db->fetchAll("SELECT * FROM audit_logs WHERE table_name IN ('journal_entries', 'journal_lines') ORDER BY id DESC LIMIT 50");
print_r($j_logs);

echo "\n--- Check period_report_snapshots ---\n";
$snaps = $db->fetchAll("SELECT id, period_id, report_type, generated_at, LENGTH(snapshot_data) as len FROM period_report_snapshots");
print_r($snaps);
