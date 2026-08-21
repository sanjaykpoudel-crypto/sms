<?php
require_once 'database/DBConnection.php';
$db = db();

echo "====================================================================\n";
echo " EXACT INVENTORY SUBLEDGER VS GL AUDIT RECONCILIATION\n";
echo "====================================================================\n\n";

// 1. Current stock valuation by item
$subledger_items = $db->fetchAll("
    SELECT i.id, i.sku, i.item_name, i.current_stock, i.cost_price,
           (i.current_stock * i.cost_price) as sub_val
    FROM items i
    WHERE i.is_deleted = 0 AND i.is_active = 1
");

$total_sub_val = 0;
foreach ($subledger_items as $si) {
    $total_sub_val += (float)$si['sub_val'];
}

echo "Subledger Valuation (Sum of items.current_stock * cost_price): Rs " . number_format($total_sub_val, 2) . "\n";

// 2. GL Balance on Inventory Asset Account
$inv_acct = $db->fetchOne("
    SELECT id, account_name FROM accounts 
    WHERE account_subtype IN ('inventory', 'Inventory Asset', 'Inventory') 
      AND is_active = 1 AND is_deleted = 0 
    ORDER BY id ASC LIMIT 1
");
$inv_acct_id = $inv_acct['id'];

$gl_bal = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
", [$inv_acct_id])['bal'] ?? 0);

echo "GL Inventory Asset Account (#{$inv_acct_id} {$inv_acct['account_name']}) Balance: Rs " . number_format($gl_bal, 2) . "\n";
echo "Difference (Subledger - GL): Rs " . number_format($total_sub_val - $gl_bal, 2) . "\n\n";

// 3. Movement-by-movement subledger vs GL audit
$movements_sum = (float)($db->fetchOne("
    SELECT SUM(total_cost) as total_val FROM inventory_movements
")[0] ?? 0);

echo "Sum of inventory_movements.total_cost: Rs " . number_format($movements_sum, 2) . "\n\n";

// Check movements by movement_type
$by_mtype = $db->fetchAll("
    SELECT movement_type, SUM(net_qty) as total_qty, SUM(total_cost) as total_cost, COUNT(*) as cnt
    FROM inventory_movements
    GROUP BY movement_type
");

echo "Inventory Movements breakdown by movement_type:\n";
foreach ($by_mtype as $bm) {
    echo sprintf("Type: %-20s | Total Qty: %10.2f | Total Cost: %12.2f | Count: %d\n",
        $bm['movement_type'], $bm['total_qty'], $bm['total_cost'], $bm['cnt']
    );
}

echo "\n--------------------------------------------------------------------\n";
echo "Check GL Journal Entries by Transaction Header Type:\n";
$by_htype = $db->fetchAll("
    SELECT h.txn_type,
           SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) as gl_amount,
           COUNT(*) as cnt
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.account_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    GROUP BY h.txn_type
", [$inv_acct_id]);

foreach ($by_htype as $bh) {
    echo sprintf("Txn Type: %-22s | GL Net Amount: %12.2f | Count: %d\n",
        $bh['txn_type'], $bh['gl_amount'], $bh['cnt']
    );
}
