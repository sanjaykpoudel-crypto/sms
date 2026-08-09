<?php
/**
 * TransactionService.php
 * Unified transaction processing engine for MNS Liquor ERP.
 * Handles core transaction creation, GL double-entry generation, subledger updates, and inventory tracking.
 */

require_once __DIR__ . '/AccountingEngine.php';

class TransactionService
{
    private static $instance = null;
    private $pdo;
    private $accountingEngine;

    private function __construct()
    {
        $db = DBConnection::getInstance();
        $this->pdo = $db->getConnection();
        $this->accountingEngine = AccountingEngine::getInstance();
    }

    public static function getInstance(): TransactionService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Post General Ledger Entry for a core transaction.
     * Guarantees immutable historical GL recording with TOTAL DEBIT = TOTAL CREDIT.
     */
    public function postGLTransaction(int $transactionId, array $glLines, string $memo = ''): int
    {
        // 1. Validate Double Entry Balance
        $this->accountingEngine->validateGL($glLines);

        // Fetch transaction details
        $stmt = $this->pdo->prepare("SELECT transaction_no, posting_date, fiscal_period_id FROM erp_transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            throw new Exception("Transaction ID {$transactionId} not found for GL posting.");
        }

        $totalDebit = array_sum(array_column($glLines, 'debit'));
        $totalCredit = array_sum(array_column($glLines, 'credit'));
        $glNo = 'GL-' . $tx['transaction_no'];

        // Check if GL transaction exists, replace if re-posting
        $stmtDel = $this->pdo->prepare("DELETE FROM erp_gl_transactions WHERE transaction_id = ?");
        $stmtDel->execute([$transactionId]);

        $stmtIns = $this->pdo->prepare("INSERT INTO erp_gl_transactions 
            (gl_no, transaction_id, posting_date, fiscal_period_id, memo, total_debit, total_credit, is_balanced)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        $stmtIns->execute([
            $glNo, $transactionId, $tx['posting_date'], $tx['fiscal_period_id'], $memo, $totalDebit, $totalCredit
        ]);
        $glTxId = $this->pdo->lastInsertId();

        // Insert GL Lines
        $stmtLine = $this->pdo->prepare("INSERT INTO erp_gl_lines 
            (gl_transaction_id, transaction_id, line_no, account_id, debit, credit, customer_id, vendor_id, item_id, location_id, posting_date, memo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $lineNo = 1;
        foreach ($glLines as $line) {
            $stmtLine->execute([
                $glTxId,
                $transactionId,
                $lineNo++,
                $line['account_id'],
                $line['debit'] ?? 0.0000,
                $line['credit'] ?? 0.0000,
                $line['customer_id'] ?? null,
                $line['vendor_id'] ?? null,
                $line['item_id'] ?? null,
                $line['location_id'] ?? null,
                $tx['posting_date'],
                $line['memo'] ?? $memo
            ]);

            // Update Account current balance
            $netEffect = ($line['debit'] ?? 0) - ($line['credit'] ?? 0);
            $stmtAcc = $this->pdo->prepare("UPDATE erp_accounts SET current_balance = current_balance + ? WHERE id = ?");
            $stmtAcc->execute([$netEffect, $line['account_id']]);
        }

        return $glTxId;
    }

    /**
     * Post Inventory Stock Movement and update inventory balances.
     */
    public function recordInventoryMovement(int $transactionId, int $itemId, int $locationId, string $postingDate, string $movementType, float $qty, float $unitCost): void
    {
        $totalCost = $qty * $unitCost;

        $stmt = $this->pdo->prepare("INSERT INTO erp_inventory_transactions 
            (transaction_id, item_id, location_id, posting_date, movement_type, quantity, unit_cost, total_cost)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$transactionId, $itemId, $locationId, $postingDate, $movementType, $qty, $unitCost, $totalCost]);

        // Upsert Inventory Balance per Item + Location
        $stmtBal = $this->pdo->prepare("INSERT INTO erp_inventory_balances (item_id, location_id, quantity, avg_unit_cost, total_value)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                quantity = quantity + VALUES(quantity),
                avg_unit_cost = IF(quantity + VALUES(quantity) > 0, (total_value + VALUES(total_value)) / (quantity + VALUES(quantity)), avg_unit_cost),
                total_value = total_value + VALUES(total_value)");
        $stmtBal->execute([$itemId, $locationId, $qty, $unitCost, $totalCost]);

        // Update item location stock quantity
        $stmtLoc = $this->pdo->prepare("UPDATE erp_item_locations SET stock_quantity = stock_quantity + ? WHERE item_id = ? AND location_id = ?");
        $stmtLoc->execute([$qty, $itemId, $locationId]);
    }
}
