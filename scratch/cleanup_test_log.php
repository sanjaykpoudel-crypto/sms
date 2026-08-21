<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$db->execute("DELETE FROM audit_logs WHERE id = 3602");
echo "Cleaned up test audit log 3602.\n";
