<?php
require_once __DIR__ . '/../../database/DBConnection.php';
require_once __DIR__ . '/../../api/AccountingEngine.php';
require_once __DIR__ . '/../../api/InventoryEngine.php';

function seed_accounting_gl() {
    $db = db();
    $pdo = $db->getConnection();

    echo "--- SEEDING DOUBLE-ENTRY GENERAL LEDGER FROM TRANSACTIONS ---\n";

    // Clear journal entries & account balances
    $pdo->exec("TRUNCATE TABLE journal_lines");
    $pdo->exec("DELETE FROM journal_entries");
    $pdo->exec("TRUNCATE TABLE account_balances");

    $acctEngine = AccountingEngine::getInstance();

    // Fetch transaction headers
    $stmtTxns = $pdo->query("
        SELECT id, txn_number, txn_type, txn_date, net_amount, party_id, party_type, location_id
        FROM transaction_headers
        WHERE is_deleted = 0 AND status NOT IN ('void', 'voided', 'draft')
        ORDER BY txn_date ASC, id ASC
    ");
    $txns = $stmtTxns->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($txns) . " transaction headers to post to GL.\n";

    $postedCount = 0;
    foreach ($txns as $t) {
        $txnId  = (int)$t['id'];
        $type   = $t['txn_type'];
        $date   = $t['txn_date'];
        $locId  = (int)($t['location_id'] ?: 1);
        $pId    = !empty($t['party_id']) ? (int)$t['party_id'] : null;
        $totAmt = (float)$t['net_amount'];

        if (in_array($type, ['vendor_bill', 'purchase_receipt'])) {
            $acctEngine->postPurchaseBill($txnId, $pId, $locId, $totAmt, $date);
            $postedCount++;
        } elseif (in_array($type, ['customer_invoice', 'pos_sale', 'sales_issue'])) {
            // Compute COGS leg from inventory_ledger for this transaction
            $stmtCogs = $pdo->prepare("SELECT COALESCE(SUM(total_value_out), 0) FROM inventory_ledger WHERE transaction_id = ?");
            $stmtCogs->execute([$txnId]);
            $cogsVal = (float)$stmtCogs->fetchColumn();

            $acctEngine->postSaleInvoice($txnId, $pId, $locId, $totAmt, $cogsVal, $date);
            $postedCount++;
        } elseif (in_array($type, ['inventory_adjustment', 'adjustment'])) {
            $stmtAdj = $pdo->prepare("SELECT COALESCE(SUM(total_value_in - total_value_out), 0) FROM inventory_ledger WHERE transaction_id = ?");
            $stmtAdj->execute([$txnId]);
            $netVal = (float)$stmtAdj->fetchColumn();
            $isInc  = ($netVal >= 0);

            $acctEngine->postInventoryAdjustment($txnId, $locId, abs($netVal), $isInc, $date);
            $postedCount++;
        } elseif ($type === 'inventory_transfer') {
            $acctEngine->postTransfer($txnId, $locId, $locId, $totAmt, $date);
            $postedCount++;
        }
    }

    echo "Successfully posted $postedCount balanced journal entries to GL.\n";
    echo "--- GL SEED COMPLETE ---\n";
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    seed_accounting_gl();
}
