<?php
require_once 'database/DBConnection.php';
require_once 'api/ReportingEngine.php';
require_once 'api/InventoryEngine.php';

$db = db();
$today = date('Y-m-d');

echo "====================================================================\n";
echo " INVENTORY SUBLEDGER VS GL DETAILED INVESTIGATION SCRIPT\n";
echo "====================================================================\n\n";

// 1. Subledger Valuation (All Locations & Per Location)
$subledger_total = (float)($db->fetchOne("
    SELECT SUM(current_stock * cost_price) as val FROM items WHERE is_deleted = 0 AND is_active = 1
")['val'] ?? 0);

$subledger_ib = (float)($db->fetchOne("
    SELECT SUM(quantity_on_hand * i.cost_price) as val 
    FROM inventory_balances ib 
    JOIN items i ON ib.item_id = i.id 
    WHERE i.is_deleted = 0 AND i.is_active = 1
")['val'] ?? 0);

echo "1. SUBLEDGER VALUATION:\n";
echo "   - items table (current_stock * cost_price): Rs " . number_format($subledger_total, 2) . "\n";
echo "   - inventory_balances table (quantity_on_hand * cost_price): Rs " . number_format($subledger_ib, 2) . "\n\n";

// Breakdown per location in inventory_balances
$loc_balances = $db->fetchAll("
    SELECT location_id, SUM(quantity_on_hand * i.cost_price) as val, COUNT(*) as item_count
    FROM inventory_balances ib
    JOIN items i ON ib.item_id = i.id
    WHERE i.is_deleted = 0 AND i.is_active = 1
    GROUP BY location_id
");
echo "   Subledger breakdown by location_id:\n";
foreach ($loc_balances as $lb) {
    echo "   - Location ID '{$lb['location_id']}': Rs " . number_format($lb['val'], 2) . " ({$lb['item_count']} items)\n";
}
echo "\n";

// 2. GL Inventory Asset Account Balances
// Find all accounts that are inventory asset accounts
$inv_accounts = $db->fetchAll("
    SELECT id, account_name, account_type, account_subtype 
    FROM accounts 
    WHERE account_subtype IN ('inventory', 'Inventory Asset', 'Inventory') OR id IN ('7', 'acc-1200')
");
echo "2. INVENTORY GL ACCOUNTS IN COA:\n";
foreach ($inv_accounts as $ia) {
    echo "   - Account ID '{$ia['id']}' | Name: '{$ia['account_name']}' | Type: '{$ia['account_type']}' | Subtype: '{$ia['account_subtype']}'\n";
}
echo "\n";

// Check GL balance under different filters
$gl_all = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_subtype IN ('inventory', 'Inventory Asset', 'Inventory')
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
")['bal'] ?? 0);

echo "   GL Inventory Balance (ALL locations, active statuses): Rs " . number_format($gl_all, 2) . "\n";

// GL breakdown by h.location_id
$gl_by_loc = $db->fetchAll("
    SELECT h.location_id, SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as bal, COUNT(*) as entry_count
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_subtype IN ('inventory', 'Inventory Asset', 'Inventory')
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY h.location_id
");
echo "   GL breakdown by header location_id:\n";
foreach ($gl_by_loc as $gl_l) {
    echo "   - Location ID '{$gl_l['location_id']}': Rs " . number_format($gl_l['bal'], 2) . " ({$gl_l['entry_count']} entries)\n";
}
echo "\n";

// Check GL balance with specific location filter vs without location filter
$user_loc = function_exists('get_user_default_location_id') ? get_user_default_location_id() : 1;
echo "Default user location: {$user_loc}\n";

$gl_loc1 = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_subtype IN ('inventory', 'Inventory Asset', 'Inventory')
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.location_id = ?
", [$user_loc])['bal'] ?? 0);

echo "   GL Inventory Balance for location_id = {$user_loc}: Rs " . number_format($gl_loc1, 2) . "\n";

$diff_loc1 = $subledger_total - $gl_loc1;
echo "   Difference if Location {$user_loc} filtered GL: Rs " . number_format($diff_loc1, 2) . "\n\n";

echo "   Target Mismatch in User Prompt: Rs 74,320.83 (Subledger: 287,243.88 | GL: 212,923.05)\n";
echo "   Calculated (287,243.88 - 212,923.05): Rs " . number_format(287243.88 - 212923.05, 2) . "\n";
echo "   Calculated (287,243.88 - {$gl_loc1}): Rs " . number_format($subledger_total - $gl_loc1, 2) . "\n";
