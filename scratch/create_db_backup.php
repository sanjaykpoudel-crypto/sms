<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

$backup_file = __DIR__ . '/../database/backups/sms_db_backup_' . date('Ymd_His') . '.sql';
echo "Creating database backup at: {$backup_file}\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$handle = fopen($backup_file, 'w');

fwrite($handle, "-- SMS ERP DATABASE BACKUP\n");
fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n\n");
fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

foreach ($tables as $table) {
    fwrite($handle, "-- Structure for table `{$table}`\n");
    $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
    fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
    fwrite($handle, $create . ";\n\n");

    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        fwrite($handle, "-- Data for table `{$table}`\n");
        foreach ($rows as $row) {
            $cols = array_map(function($c) { return "`" . $c . "`"; }, array_keys($row));
            $vals = array_map(function($v) use ($pdo) {
                if ($v === null) return "NULL";
                return $pdo->quote($v);
            }, array_values($row));
            fwrite($handle, "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n");
        }
        fwrite($handle, "\n");
    }
}

fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($handle);

echo "Backup created successfully! File size: " . number_format(filesize($backup_file)) . " bytes.\n";
