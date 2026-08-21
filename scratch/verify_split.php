<?php
require_once 'database/DBConnection.php';
$db = db();

$acc11 = $db->fetchOne("
    SELECT a.id, a.account_name, COALESCE(SUM(CASE WHEN j.entry_type='credit' THEN j.amount ELSE -j.amount END), 0) as bal
    FROM accounts a LEFT JOIN journal_entries j ON j.account_id = a.id
    LEFT JOIN transaction_headers h ON j.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
    WHERE a.id = 11 GROUP BY a.id, a.account_name
");

$acc42 = $db->fetchOne("
    SELECT a.id, a.account_name, COALESCE(SUM(CASE WHEN j.entry_type='credit' THEN j.amount ELSE -j.amount END), 0) as bal
    FROM accounts a LEFT JOIN journal_entries j ON j.account_id = a.id
    LEFT JOIN transaction_headers h ON j.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void','voided', 'draft')
    WHERE a.id = 42 GROUP BY a.id, a.account_name
");

$acc43 = $db->fetchOne("
    SELECT a.id, a.account_name, COALESCE(SUM(CASE WHEN j.entry_type='credit' THEN j.amount ELSE -j.amount END), 0) as bal
    FROM accounts a LEFT JOIN journal_entries j ON j.account_id = a.id
    LEFT JOIN transaction_headers h ON j.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void','voided', 'draft')
    WHERE a.id = 43 GROUP BY a.id, a.account_name
");

echo "Account 11 ({$acc11['account_name']}): Rs " . number_format($acc11['bal'], 2) . "\n";
echo "Account 42 ({$acc42['account_name']}): Rs " . number_format($acc42['bal'], 2) . "\n";
echo "Account 43 ({$acc43['account_name']}): Rs " . number_format($acc43['bal'], 2) . "\n";
