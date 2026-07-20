<?php
/**
 * Run database migrations for the Fiscal Year Closing Module
 */
require_once __DIR__ . '/../database/DBConnection.php';

try {
    $db = db();
    $pdo = $db->getConnection();
    
    echo "Starting migration...\n";
    
    $sql_file = __DIR__ . '/../database/fiscal_year_updates.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("Migration SQL file not found at: " . $sql_file);
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split SQL by semi-colon to execute statement by statement (naive splitter but works for standard CREATE/ALTER)
    // We clean up comments and split.
    $statements = explode(';', $sql);
    
    // Execute DDL statement by statement without transaction since DDL causes implicit commit in MySQL
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
