<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== DISTINCT ACCOUNT IDs IN JOURNAL_LINES ===\n";
$rows = $pdo->query("
    SELECT jl.account_id, a.account_name, a.account_type, a.account_subtype, COUNT(*) as cnt,
           SUM(jl.debit) as tot_dr, SUM(jl.credit) as tot_cr
    FROM journal_lines jl
    LEFT JOIN accounts a ON jl.account_id = a.id
    GROUP BY jl.account_id, a.account_name, a.account_type, a.account_subtype
    ORDER BY jl.account_id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    echo sprintf("Acc ID: %-3d | Name: %-35s | Type: %-10s | Count: %4d | Dr: %10.2f | Cr: %10.2f\n",
        $r['account_id'], $r['account_name'] ?? 'UNKNOWN', $r['account_type'] ?? 'UNKNOWN', $r['cnt'], $r['tot_dr'], $r['tot_cr']
    );
}
