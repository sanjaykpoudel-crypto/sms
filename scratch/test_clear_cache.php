<?php
require_once 'database/DBConnection.php';
$db = db();
try {
    $rows = $db->fetchAll("SELECT cache_key, expires_at, created_at FROM dashboard_kpi_cache");
    echo "Cached entries count: " . count($rows) . "\n";
    print_r($rows);
    $db->execute("DELETE FROM dashboard_kpi_cache");
    echo "Cleared all entries from dashboard_kpi_cache!\n";
} catch (Exception $e) {
    echo "dashboard_kpi_cache check error: " . $e->getMessage() . "\n";
}
