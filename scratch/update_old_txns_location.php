<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
$db = db();

// Get default location ID
$default_location_id = get_accounting_preference('default_location_id');

if (empty($default_location_id)) {
    // Fallback to location where is_default = 1
    $loc = $db->fetchOne("SELECT id FROM locations WHERE is_default = 1 AND is_deleted = 0 LIMIT 1");
    if ($loc) {
        $default_location_id = $loc['id'];
    }
}

if (empty($default_location_id)) {
    // Fallback to first active location
    $loc = $db->fetchOne("SELECT id FROM locations WHERE is_active = 1 AND is_deleted = 0 ORDER BY name ASC LIMIT 1");
    if ($loc) {
        $default_location_id = $loc['id'];
    }
}

echo "Default Location ID: " . ($default_location_id ?: 'NONE FOUND') . "\n";

if ($default_location_id) {
    // Count transactions without location_id
    $count = $db->fetchOne("SELECT COUNT(*) as cnt FROM transaction_headers WHERE location_id IS NULL OR location_id = ''")['cnt'] ?? 0;
    echo "Transactions without location_id: $count\n";

    // Update old transactions
    $stmt = $db->execute("UPDATE transaction_headers SET location_id = ? WHERE location_id IS NULL OR location_id = ''", [$default_location_id]);
    echo "Successfully updated $count transaction(s) to location_id: $default_location_id\n";
} else {
    echo "Error: No default location could be determined.\n";
}
