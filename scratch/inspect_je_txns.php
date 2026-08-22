<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$je = $db->fetchAll("
    SELECT je.je_id, je.transaction_id, je.je_type, je.memo, th.txn_number, th.txn_type, th.party_id, th.party_type
    FROM journal_entries je
    LEFT JOIN transaction_headers th ON je.transaction_id = th.id
    ORDER BY je.je_id DESC
    LIMIT 100
");

print_r($je);
