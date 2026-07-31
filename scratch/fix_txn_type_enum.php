<?php
require_once 'database/DBConnection.php';
$db = db();

echo "--- ALTERING transaction_headers.txn_type TO VARCHAR(50) ---\n";
try {
    $db->execute("ALTER TABLE transaction_headers MODIFY COLUMN txn_type VARCHAR(50) NOT NULL");
    echo "SUCCESS: Column txn_type modified to VARCHAR(50)\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
