<?php
require_once 'database/DBConnection.php';
$db = db();

echo "--- ALTERING transaction_headers.status TO VARCHAR(30) ---\n";
try {
    $db->execute("ALTER TABLE transaction_headers MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'open'");
    echo "SUCCESS: Column status modified to VARCHAR(30)\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
