<?php
require_once 'database/DBConnection.php';
require_once 'api/ReportingEngine.php';

$db = db();
$today = date('Y-m-d');

$subledger_val = (float)($db->fetchOne("
    SELECT SUM(current_stock * cost_price) as val FROM items WHERE is_deleted = 0 AND is_active = 1
")['val'] ?? 0);

$gl_bal = re_get_inventory_gl_balance($db, $today);

echo "Subledger Items Valuation: {$subledger_val}\n";
echo "GL Inventory Asset Balance: {$gl_bal}\n";
echo "Variance: " . ($subledger_val - $gl_bal) . "\n\n";

// Check if items table current_stock matches inventory_movements sum
$items = $db->fetchAll("
    SELECT i.id, i.sku, i.item_name, i.current_stock, i.cost_price,
           (i.current_stock * i.cost_price) as total_val,
           COALESCE(SUM(m.net_qty), 0) as movement_stock
    FROM items i
    LEFT JOIN inventory_movements m ON m.item_id = i.id
    WHERE i.is_deleted = 0 AND i.is_active = 1
    GROUP BY i.id, i.sku, i.item_name, i.current_stock, i.cost_price
    HAVING current_stock != movement_stock
");

echo "Items where items.current_stock != inventory_movements sum:\n";
foreach ($items as $it) {
    echo "ID: {$it['id']} | SKU: {$it['sku']} | Name: {$it['item_name']} | Master Stock: {$it['current_stock']} | Movement Stock: {$it['movement_stock']}\n";
}

// Check journal entries posted to Inventory Asset account
$inv_acct = $db->fetchOne("SELECT id, account_name FROM accounts WHERE account_subtype IN ('Inventory Asset', 'inventory') AND is_active = 1");
echo "\nInventory GL Account ID: {$inv_acct['id']} ({$inv_acct['account_name']})\n";

$entries = $db->fetchAll("
    SELECT h.txn_number, h.txn_type, h.txn_date, h.memo, j.entry_type, j.amount
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ORDER BY h.txn_date DESC, h.id DESC
    LIMIT 20
", [$inv_acct['id']]);

echo "\nRecent 20 GL Journal Entries on Inventory Account:\n";
foreach ($entries as $e) {
    echo "Txn: {$e['txn_number']} | Type: {$e['txn_type']} | Date: {$e['txn_date']} | Entry: {$e['entry_type']} | Amount: {$e['amount']} | Memo: {$e['memo']}\n";
}
