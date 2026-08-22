<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== TRANSACTION TYPES AND JOURNAL POSTINGS ===\n";
$rows = $pdo->query("
    SELECT th.txn_type, COUNT(DISTINCT th.id) as txn_count, COUNT(DISTINCT je.je_id) as je_count, COUNT(jl.jl_id) as jl_count
    FROM transaction_headers th
    LEFT JOIN journal_entries je ON je.transaction_id = th.id
    LEFT JOIN journal_lines jl ON jl.je_id = je.je_id
    WHERE th.is_deleted = 0
    GROUP BY th.txn_type
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    echo sprintf("Type: %-22s | Txn Count: %4d | JE Count: %4d | JL Count: %4d\n",
        $r['txn_type'], $r['txn_count'], $r['je_count'], $r['jl_count']
    );
}
