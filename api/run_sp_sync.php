<?php
// Prevent execution via web browser (allow only CLI)
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo "Forbidden: Only command line execution is allowed.";
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';

$log_dir = __DIR__ . '/../scratch';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0777, true);
}
$log_file = $log_dir . '/background_sync.log';

try {
    $db = db();
    $pdo = $db->getConnection();
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Call the stored procedure
    $pdo->exec("CALL sp_sync_gl_accounts()");
    
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - SUCCESS: sp_sync_gl_accounts executed successfully in background.\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR: Failed to run background sync: " . $e->getMessage() . "\n", FILE_APPEND);
}
