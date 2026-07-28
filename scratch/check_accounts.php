<?php
require_once 'database/DBConnection.php';
$db = db();
$db->execute("DELETE FROM reference_codes WHERE type = 'status'");
echo "Deleted status records from reference_codes\n";
