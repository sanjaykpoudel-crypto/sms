<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== INSPECTING JV-00002 IN JOURNAL ENTRIES ===\n\n";

$rows = $db->fetchAll("
    SELECT j.*, h.txn_number, h.memo as header_memo, h.party_id as header_party_id, h.party_type as header_party_type
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_number = 'JV-00002'
");

print_r($rows);
