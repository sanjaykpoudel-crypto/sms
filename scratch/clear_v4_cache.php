<?php
/**
 * Clear Dashboard V4 Cache
 * Access this file via browser: http://localhost/sms/scratch/clear_v4_cache.php
 */
require_once '../database/DBConnection.php';

$db = db();
try {
    $db->execute('DELETE FROM dashboard_kpi_cache WHERE cache_key LIKE ?', ['dash_v4_%']);
    echo "Dashboard V4 cache cleared successfully!";
} catch (Exception $e) {
    echo "Error (might not exist yet): " . $e->getMessage();
}