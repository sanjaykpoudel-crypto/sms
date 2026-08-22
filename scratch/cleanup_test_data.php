<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/reference_helper.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "====================================================================\n";
echo " CASCADE TEST DATA CLEANUP ENGINE\n";
echo " Safely purging test transactions and orphaned records\n";
echo "====================================================================\n\n";

$test_headers = $pdo->query("
    SELECT id, txn_number, txn_type, txn_date, memo, is_deleted
    FROM transaction_headers
    WHERE memo LIKE '%test%'
       OR memo LIKE '%demo%'
       OR reference_number LIKE '%test%'
       OR reference_number LIKE '%demo%'
       OR txn_number LIKE 'TEST%'
       OR txn_number LIKE 'DEMO%'
       OR is_deleted = 1
")->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($test_headers) . " test/soft-deleted headers for removal:\n";
foreach ($test_headers as $th) {
    echo sprintf(" - ID: %-6d | Txn #: %-22s | Type: %-18s | Date: %s | Deleted: %d | Memo: %s\n",
        $th['id'], $th['txn_number'], $th['txn_type'], $th['txn_date'], $th['is_deleted'], $th['memo']
    );
}

if (empty($test_headers)) {
    echo "No test headers to purge.\n";
} else {
    $pdo->beginTransaction();
    try {
        $header_ids = array_column($test_headers, 'id');
        $in_clause = implode(',', array_map('intval', $header_ids));

        // 1. Delete journal lines & headers
        $je_ids = $pdo->query("SELECT je_id FROM journal_entries WHERE transaction_id IN ({$in_clause})")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($je_ids)) {
            $je_in = implode(',', array_map('intval', $je_ids));
            $c1 = $pdo->exec("DELETE FROM journal_lines WHERE je_id IN ({$je_in})");
            echo "Deleted {$c1} journal lines.\n";
            $c2 = $pdo->exec("DELETE FROM journal_entries WHERE je_id IN ({$je_in})");
            echo "Deleted {$c2} journal entry headers.\n";
        }

        // 2. Delete transaction lines
        $c3 = $pdo->exec("DELETE FROM transaction_lines WHERE header_id IN ({$in_clause})");
        echo "Deleted {$c3} transaction lines.\n";

        // 3. Delete inventory movements
        $c4 = $pdo->exec("DELETE FROM inventory_movements WHERE header_id IN ({$in_clause})");
        echo "Deleted {$c4} inventory movements.\n";

        // 4. Delete payments & links
        $c5 = $pdo->exec("DELETE FROM transaction_links WHERE parent_id IN ({$in_clause}) OR child_id IN ({$in_clause})");
        echo "Deleted {$c5} transaction links.\n";
        $c6 = $pdo->exec("DELETE FROM payments WHERE header_id IN ({$in_clause})");
        echo "Deleted {$c6} payments.\n";

        // 5. Delete specific document tables
        $pdo->exec("DELETE FROM vendor_bills WHERE header_id IN ({$in_clause})");
        $pdo->exec("DELETE FROM customer_invoices WHERE header_id IN ({$in_clause})");
        $pdo->exec("DELETE FROM credit_memos WHERE header_id IN ({$in_clause})");
        $pdo->exec("DELETE FROM vendor_credits WHERE header_id IN ({$in_clause})");

        // 6. Delete transaction headers
        $c7 = $pdo->exec("DELETE FROM transaction_headers WHERE id IN ({$in_clause})");
        echo "Deleted {$c7} transaction headers.\n";

        // 7. Clean orphaned records
        $c8 = $pdo->exec("DELETE FROM transaction_lines WHERE header_id NOT IN (SELECT id FROM transaction_headers)");
        echo "Deleted {$c8} orphaned transaction lines.\n";
        $c9 = $pdo->exec("DELETE FROM transaction_links WHERE parent_id NOT IN (SELECT id FROM transaction_headers) OR child_id NOT IN (SELECT id FROM transaction_headers)");
        echo "Deleted {$c9} orphaned transaction links.\n";

        $pdo->commit();
        echo "\nTest Data Purge Successfully Committed!\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Purge Failed: " . $e->getMessage() . "\n";
    }
}

// 8. Re-sync all inventory balances
echo "\nRe-synchronizing Inventory Balances...\n";
$items = $pdo->query("SELECT id FROM items WHERE is_deleted = 0")->fetchAll(PDO::FETCH_COLUMN);
foreach ($items as $item_id) {
    sync_and_get_item_inventory_balances($db, $item_id);
}
echo "Inventory balances re-synchronized for " . count($items) . " items.\n";
