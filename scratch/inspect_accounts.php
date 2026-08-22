<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "=== ACCOUNTS TABLE CONTENTS ===\n";
$accounts = $pdo->query("SELECT id, account_name, account_type, account_subtype, normal_balance FROM accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($accounts as $a) {
    echo sprintf("ID: %-3d | Name: %-35s | Type: %-12s | Subtype: %-22s | Normal: %s\n",
        $a['id'], $a['account_name'], $a['account_type'], $a['account_subtype'] ?? '', $a['normal_balance'] ?? ''
    );
}
