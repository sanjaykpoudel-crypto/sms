<?php
/**
 * 04_delete_test_transactions.php
 * Completely removes test POS transactions created during test runs today (POS-GOKA-00144, POS-GOKA-00145)
 * and re-syncs stock & accounting balances.
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'sms_db';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Deleting test transactions created during verification testing...\n";

    // 1. Find POS IDs for test transactions
    $testPosIds = $pdo->query("SELECT id FROM pos_entry WHERE invoice_no IN ('POS-GOKA-00144', 'POS-GOKA-00145') OR DATE(date_time) = '2026-08-08'")->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($testPosIds)) {
        $inPos = implode(',', array_map('intval', $testPosIds));
        
        // Revert items stock in legacy items & inventory_balances table
        $posItems = $pdo->query("SELECT item_id, quantity, pos_id FROM pos_items WHERE pos_id IN ($inPos)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($posItems as $pi) {
            $itmId = $pi['item_id'];
            $qty = (float)$pi['quantity'];
            if ($itmId && $qty > 0) {
                $pdo->exec("UPDATE items SET current_stock = current_stock + $qty WHERE id = $itmId");
                $pdo->exec("UPDATE inventory_balances SET quantity_on_hand = quantity_on_hand + $qty, available_qty = available_qty + $qty WHERE item_id = $itmId AND location_id = 1");
            }
        }

        // Delete from legacy tables
        $pdo->exec("DELETE FROM pos_items WHERE pos_id IN ($inPos)");
        $pdo->exec("DELETE FROM pos_payments WHERE pos_id IN ($inPos)");
        $pdo->exec("DELETE FROM pos_entry WHERE id IN ($inPos)");
    }

    // Delete summary transaction headers created today for 2026-08-08
    $summaryHdrs = $pdo->query("SELECT id FROM transaction_headers WHERE txn_number IN ('INV-POS-20260808', 'PAY-POS-20260808') OR DATE(txn_date) = '2026-08-08'")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($summaryHdrs)) {
        foreach ($summaryHdrs as $hId) {
            $pdo->exec("DELETE FROM transaction_lines WHERE header_id = '$hId'");
            $pdo->exec("DELETE FROM customer_invoices WHERE header_id = '$hId'");
            $pdo->exec("DELETE FROM journal_entries WHERE header_id = '$hId'");
            $pdo->exec("DELETE FROM payments WHERE header_id = '$hId'");
            $pdo->exec("DELETE FROM transaction_links WHERE parent_id = '$hId' OR child_id = '$hId'");
            $pdo->exec("DELETE FROM transaction_headers WHERE id = '$hId'");
        }
    }

    // Re-run ETL migration to sync normalized erp_* tables cleanly
    include __DIR__ . '/02_etl_data_migration.php';

    echo "TEST_TRANSACTIONS_DELETED_SUCCESSFULLY\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
