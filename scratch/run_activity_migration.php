<?php
/**
 * Run database migrations for the Activities Module
 */
require_once __DIR__ . '/../database/DBConnection.php';

try {
    $db = db();
    $pdo = $db->getConnection();
    
    echo "Starting migration for activities...\n";
    
    $sql_file = __DIR__ . '/../database/activity_updates.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("Migration SQL file not found at: " . $sql_file);
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split SQL by semi-colon to execute statement by statement
    $statements = explode(';', $sql);
    
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;
        
        echo "Executing statement:\n" . substr($stmt, 0, 80) . "...\n";
        $pdo->exec($stmt);
    }
    
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
