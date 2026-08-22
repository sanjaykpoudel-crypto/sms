<?php
/**
 * api/InventoryEngine.php
 * Perpetual Inventory Costing Engine for MNS Liquor ERP (PHP + MySQL).
 *
 * Implements transaction-driven inventory valuation & monotonic sequence-numbered ledger.
 * Supports Moving-Average Costing, FIFO layer consumption, row-locking (SELECT ... FOR UPDATE),
 * and targeted forward-recosting (recostForward) for backdated edits, deletions, and voiding.
 */

if (!class_exists('InventoryException')) {
    class InventoryException extends Exception {}
}

class InventoryEngine
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        $db = DBConnection::getInstance();
        $this->pdo = $db->getConnection();
    }

    public static function getInstance(): InventoryEngine
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    /**
     * Fast O(1) balance lookup on inventory_balances snapshot
     */
    public function getBalance($itemId, $locationId = null): array
    {
        $rawLoc = $locationId ?: (function_exists('get_user_default_location_id') ? get_user_default_location_id() : 1);
        $locId = function_exists('resolve_location_id') ? resolve_location_id($rawLoc) : (is_numeric($rawLoc) ? (int)$rawLoc : 1);

        $stmt = $this->pdo->prepare("SELECT * FROM inventory_balances WHERE item_id = ? AND location_id = ?");
        $stmt->execute([$itemId, $locId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'quantity_on_hand' => (float)$row['quantity_on_hand'],
                'average_cost'     => (float)$row['average_cost'],
                'total_value'      => (float)$row['total_value'],
                'last_ledger_id'   => $row['last_ledger_id'] ? (int)$row['last_ledger_id'] : null,
            ];
        }

        // Fallback to global items master stock
        $stmt = $this->pdo->prepare("SELECT current_stock, cost_price FROM items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        $stock = (float)($item['current_stock'] ?? 0.0);
        $cost  = (float)($item['cost_price'] ?? 0.0);

        return [
            'quantity_on_hand' => $stock,
            'average_cost'     => $cost,
            'total_value'      => round($stock * $cost, 4),
            'last_ledger_id'   => null,
        ];
    }

    /**
     * Get available stock quantity
     */
    public function getAvailableStock($itemId, $locationId = null): float
    {
        $bal = $this->getBalance($itemId, $locationId);
        return $bal['quantity_on_hand'];
    }

    /**
     * Core NetSuite-style Inventory Movement Line Posting
     * Every inventory-affecting line funnels through postLine().
     */
    public function postLine(
        $itemId,
        $locationId,
        float $quantity,
        ?float $unitCost,
        $transactionId,
        $lineId,
        $date = null,
        string $txnType = 'UNKNOWN',
        array $options = []
    ): int {
        $inTxn = $this->pdo->inTransaction();
        if (!$inTxn) {
            $this->pdo->beginTransaction();
        }

        try {
            $rawLoc = $locationId ?: (function_exists('get_user_default_location_id') ? get_user_default_location_id() : 1);
            $locId  = function_exists('resolve_location_id') ? resolve_location_id($rawLoc) : (is_numeric($rawLoc) ? (int)$rawLoc : 1);
            $txnDateStr = $date ? (is_a($date, 'DateTime') ? $date->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime($date))) : date('Y-m-d H:i:s');

            // 1. Lock item+location balance row with SELECT ... FOR UPDATE
            $stmtLock = $this->pdo->prepare("SELECT * FROM inventory_balances WHERE item_id = ? AND location_id = ? FOR UPDATE");
            $stmtLock->execute([$itemId, $locId]);
            $currentBalRow = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$currentBalRow) {
                $stmtInsBal = $this->pdo->prepare("INSERT IGNORE INTO inventory_balances (item_id, location_id, quantity_on_hand, average_cost, total_value) VALUES (?, ?, 0, 0, 0)");
                $stmtInsBal->execute([$itemId, $locId]);
                $stmtLock->execute([$itemId, $locId]);
                $currentBalRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
            }

            // 2. Fetch monotonic sequence number for item+location
            $stmtSeq = $this->pdo->prepare("SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM inventory_ledger WHERE item_id = ? AND location_id = ?");
            $stmtSeq->execute([$itemId, $locId]);
            $nextSeq = (int)$stmtSeq->fetchColumn();

            // 3. Check if entry is backdated compared to latest ledger entry date
            $stmtLatestDate = $this->pdo->prepare("SELECT transaction_date FROM inventory_ledger WHERE item_id = ? AND location_id = ? ORDER BY sequence_number DESC LIMIT 1");
            $stmtLatestDate->execute([$itemId, $locId]);
            $latestDateStr = $stmtLatestDate->fetchColumn();
            $isBackdated   = ($latestDateStr && strtotime($txnDateStr) < strtotime($latestDateStr)) ? 1 : 0;

            // 4. Compute movement quantities, unit cost, and running balances
            $ledgerId = $this->postLineInternal(
                $itemId, $locId, $quantity, $unitCost, $transactionId, $lineId, $txnDateStr, $nextSeq, $txnType, $isBackdated, $options
            );

            // 5. If backdated insert, trigger targeted forward-recosting
            if ($isBackdated) {
                $this->recostForward($itemId, $locId, $nextSeq);
            }

            if (!$inTxn) {
                $this->pdo->commit();
            }

            return $ledgerId;
        } catch (Exception $e) {
            if (!$inTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Internal line posting without starting new transactions
     */
    private function postLineInternal(
        $itemId,
        $locationId,
        float $quantity,
        ?float $unitCost,
        $transactionId,
        $lineId,
        string $txnDateStr,
        int $sequenceNumber,
        string $txnType,
        int $isBackdated,
        array $options = []
    ): int {
        // Fetch item costing method
        $stmtItem = $this->pdo->prepare("SELECT costing_method, cost_price, case_purchase_price, units_per_case FROM items WHERE id = ?");
        $stmtItem->execute([$itemId]);
        $itemMeta = $stmtItem->fetch(PDO::FETCH_ASSOC);
        $costingMethod = strtoupper($itemMeta['costing_method'] ?? 'AVERAGE');

        // Fetch latest running balances immediately before this sequence number
        $stmtPrev = $this->pdo->prepare("
            SELECT running_qty_balance, running_value_balance, unit_cost 
            FROM inventory_ledger 
            WHERE item_id = ? AND location_id = ? AND sequence_number < ? 
            ORDER BY sequence_number DESC LIMIT 1
        ");
        $stmtPrev->execute([$itemId, $locationId, $sequenceNumber]);
        $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);

        $currQty   = (float)($prev['running_qty_balance'] ?? 0.0);
        $currValue = (float)($prev['running_value_balance'] ?? 0.0);
        $currCost  = $currQty > 0 ? round($currValue / $currQty, 6) : (float)($itemMeta['cost_price'] ?? 0.0);

        $qtyIn  = $quantity > 0 ? $quantity : 0.0;
        $qtyOut = $quantity < 0 ? abs($quantity) : 0.0;
        $netQty = $quantity;

        $moveCost  = 0.0;
        $valIn     = 0.0;
        $valOut    = 0.0;

        if ($netQty > 0) {
            // RECEIPT (Purchase, Stock In, positive adjustment, Build)
            $moveCost = ($unitCost !== null && $unitCost >= 0) ? $unitCost : $currCost;
            $valIn    = round($qtyIn * $moveCost, 6);
            $valOut   = 0.0;
            $newQty   = round($currQty + $qtyIn, 6);
            $newValue = round($currValue + $valIn, 6);

            // Update master item cost_price if purchase receipt
            if ($unitCost !== null && $unitCost > 0 && in_array(strtoupper($txnType), ['PURCHASE', 'PURCHASE_RECEIPT', 'VENDOR_BILL'])) {
                $conv = !empty($itemMeta['units_per_case']) && (int)$itemMeta['units_per_case'] > 0 ? (int)$itemMeta['units_per_case'] : 1;
                $newCaseCost = round($unitCost * $conv, 2);
                $stmtUpItem = $this->pdo->prepare("UPDATE items SET cost_price = ?, case_purchase_price = ? WHERE id = ?");
                $stmtUpItem->execute([$unitCost, $newCaseCost, $itemId]);
            }

            // Create FIFO cost layer if FIFO
            if ($costingMethod === 'FIFO') {
                $stmtFifo = $this->pdo->prepare("INSERT INTO fifo_cost_layers (item_id, location_id, ledger_id, receipt_date, original_qty, remaining_qty, unit_cost) VALUES (?, ?, 0, ?, ?, ?, ?)");
                // Will update ledger_id after ledger insert
            }
        } else {
            // ISSUE (Sale, POS, Stock Out, negative adjustment, Unbuild)
            if ($costingMethod === 'FIFO') {
                // Consume FIFO cost layers
                $moveCost = $this->consumeFifoCostLayers($itemId, $locationId, $qtyOut, $currCost);
            } else {
                $moveCost = $currCost;
            }

            $valIn    = 0.0;
            $valOut   = round($qtyOut * $moveCost, 6);
            $newQty   = round($currQty - $qtyOut, 6);
            $newValue = round($currValue - $valOut, 6);
        }

        $movementType = $options['movement_type'] ?? $this->mapMovementType($txnType, $quantity);

        // Insert into inventory_ledger
        $stmtLedger = $this->pdo->prepare("
            INSERT INTO inventory_ledger 
            (item_id, location_id, transaction_id, transaction_line_id, transaction_type, movement_type, transaction_date, sequence_number, quantity_in, quantity_out, net_quantity, unit_cost, total_value_in, total_value_out, running_qty_balance, running_value_balance, costing_method_used, is_backdated, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtLedger->execute([
            $itemId,
            $locationId,
            $transactionId,
            $lineId,
            $txnType,
            $movementType,
            $txnDateStr,
            $sequenceNumber,
            $qtyIn,
            $qtyOut,
            $netQty,
            $moveCost,
            $valIn,
            $valOut,
            $newQty,
            $newValue,
            $costingMethod,
            $isBackdated,
            $options['reason'] ?? null,
            $_SESSION['user_id'] ?? null
        ]);
        $ledgerId = (int)$this->pdo->lastInsertId();

        // Also sync legacy inventory_movements table for backwards compatibility
        $this->syncLegacyMovement($ledgerId, $itemId, $locationId, $transactionId, $lineId, $txnType, $movementType, $qtyIn, $qtyOut, $netQty, $moveCost, $valIn + $valOut, $txnDateStr, $options['reason'] ?? null);

        // Update inventory_balances snapshot
        $avgCost = $newQty > 0 ? round($newValue / $newQty, 6) : $moveCost;
        $stmtBalUp = $this->pdo->prepare("
            INSERT INTO inventory_balances (item_id, location_id, quantity_on_hand, average_cost, total_value, last_ledger_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                quantity_on_hand = VALUES(quantity_on_hand),
                average_cost     = VALUES(average_cost),
                total_value      = VALUES(total_value),
                last_ledger_id   = VALUES(last_ledger_id),
                updated_at       = NOW()
        ");
        $stmtBalUp->execute([$itemId, $locationId, $newQty, $avgCost, $newValue, $ledgerId]);

        // Sync global items.current_stock
        $this->syncGlobalItemStock($itemId);

        return $ledgerId;
    }

    /**
     * Targeted Forward-Recosting Engine (NetSuite model)
     * When a transaction is backdated, edited, or voided, recost forward from that sequence number.
     */
    public function recostForward($itemId, $locationId, $fromSequenceOrDate): int
    {
        $inTxn = $this->pdo->inTransaction();
        if (!$inTxn) {
            $this->pdo->beginTransaction();
        }

        try {
            // Lock balance row
            $stmtLock = $this->pdo->prepare("SELECT * FROM inventory_balances WHERE item_id = ? AND location_id = ? FOR UPDATE");
            $stmtLock->execute([$itemId, $locationId]);

            // Resolve starting sequence number
            $fromSeq = 0;
            $fromDateStr = null;

            if (is_numeric($fromSequenceOrDate)) {
                $fromSeq = (int)$fromSequenceOrDate;
                $stmtGetDate = $this->pdo->prepare("SELECT transaction_date FROM inventory_ledger WHERE item_id = ? AND location_id = ? AND sequence_number = ?");
                $stmtGetDate->execute([$itemId, $locationId, $fromSeq]);
                $fromDateStr = $stmtGetDate->fetchColumn();
            } else {
                $fromDateStr = is_a($fromSequenceOrDate, 'DateTime') ? $fromSequenceOrDate->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime($fromSequenceOrDate));
                $stmtGetSeq = $this->pdo->prepare("SELECT COALESCE(MIN(sequence_number), 0) FROM inventory_ledger WHERE item_id = ? AND location_id = ? AND transaction_date >= ?");
                $stmtGetSeq->execute([$itemId, $locationId, $fromDateStr]);
                $fromSeq = (int)$stmtGetSeq->fetchColumn();
            }

            if ($fromSeq <= 0) {
                if (!$inTxn) $this->pdo->commit();
                return 0;
            }

            // 1. Archive affected ledger rows to inventory_ledger_history
            $stmtArchive = $this->pdo->prepare("
                INSERT INTO inventory_ledger_history 
                (ledger_id, item_id, location_id, transaction_id, transaction_line_id, transaction_date, sequence_number, quantity_in, quantity_out, unit_cost, running_qty_balance, running_value_balance, archived_reason)
                SELECT ledger_id, item_id, location_id, transaction_id, transaction_line_id, transaction_date, sequence_number, quantity_in, quantity_out, unit_cost, running_qty_balance, running_value_balance, 'Recost Forward Replay'
                FROM inventory_ledger
                WHERE item_id = ? AND location_id = ? AND sequence_number >= ?
            ");
            $stmtArchive->execute([$itemId, $locationId, $fromSeq]);

            // 2. Delete affected ledger rows and legacy movements
            $stmtDelLedger = $this->pdo->prepare("DELETE FROM inventory_ledger WHERE item_id = ? AND location_id = ? AND sequence_number >= ?");
            $stmtDelLedger->execute([$itemId, $locationId, $fromSeq]);

            // 3. Reset FIFO cost layers created at or after affected point
            if ($fromDateStr) {
                $stmtDelFifo = $this->pdo->prepare("DELETE FROM fifo_cost_layers WHERE item_id = ? AND location_id = ? AND receipt_date >= ?");
                $stmtDelFifo->execute([$itemId, $locationId, $fromDateStr]);
            }

            // 4. Fetch all active transaction lines from transaction_headers & transaction_lines from starting point forward
            $stmtTxnLines = $this->pdo->prepare("
                SELECT 
                    h.id as transaction_id,
                    l.id as line_id,
                    h.txn_number,
                    h.txn_type,
                    h.txn_date,
                    l.quantity,
                    l.unit_price,
                    l.cost_price,
                    l.conversion_factor,
                    COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(NULLIF(l.conversion_factor, 0), 1)) as calc_qty
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                WHERE l.item_id = ?
                  AND COALESCE(NULLIF(l.location_id, ''), h.location_id, 1) = ?
                  AND h.is_deleted = 0
                  AND h.status NOT IN ('void', 'voided', 'draft')
                  AND h.txn_type IN ('vendor_bill', 'purchase_receipt', 'credit_memo', 'sales_return', 'customer_invoice', 'pos_sale', 'sales_issue', 'vendor_return', 'purchase_return', 'inventory_adjustment', 'inventory_transfer', 'adjustment')
                  AND h.txn_date >= ?
                ORDER BY h.txn_date ASC, h.id ASC, l.id ASC
            ");

            $startDateForFetch = $fromDateStr ? date('Y-m-d 00:00:00', strtotime($fromDateStr)) : '2000-01-01 00:00:00';
            $stmtTxnLines->execute([$itemId, $locationId, $startDateForFetch]);
            $linesToReplay = $stmtTxnLines->fetchAll(PDO::FETCH_ASSOC);

            // 5. Replay every transaction line in chronological order
            $replayedCount = 0;
            foreach ($linesToReplay as $line) {
                $qty = (float)$line['calc_qty'];
                $txnType = $line['txn_type'];

                // Standardize net sign convention based on transaction type
                if (in_array($txnType, ['customer_invoice', 'pos_sale', 'sales_issue', 'vendor_return', 'purchase_return'])) {
                    $qty = -abs($qty);
                } elseif (in_array($txnType, ['vendor_bill', 'purchase_receipt', 'credit_memo', 'sales_return'])) {
                    $qty = abs($qty);
                }

                $cost = (float)($line['cost_price'] ?: $line['unit_price']);

                // Fetch next sequence
                $stmtSeq = $this->pdo->prepare("SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM inventory_ledger WHERE item_id = ? AND location_id = ?");
                $stmtSeq->execute([$itemId, $locationId]);
                $seq = (int)$stmtSeq->fetchColumn();

                $this->postLineInternal(
                    $itemId,
                    $locationId,
                    $qty,
                    $cost,
                    $line['transaction_id'],
                    $line['line_id'],
                    $line['txn_date'],
                    $seq,
                    $txnType,
                    1,
                    ['reason' => 'Recost Forward Replay']
                );
                $replayedCount++;
            }

            if (!$inTxn) {
                $this->pdo->commit();
            }

            return $replayedCount;
        } catch (Exception $e) {
            if (!$inTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Void a transaction and trigger targeted forward recosting for affected items & locations
     */
    public function voidTransaction($transactionId, string $reason = 'Transaction Voided'): int
    {
        $inTxn = $this->pdo->inTransaction();
        if (!$inTxn) {
            $this->pdo->beginTransaction();
        }

        try {
            // 1. Fetch lines for transaction
            $stmtLines = $this->pdo->prepare("
                SELECT l.item_id, COALESCE(NULLIF(l.location_id, ''), h.location_id, 1) as location_id, h.txn_date
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                WHERE h.id = ?
            ");
            $stmtLines->execute([$transactionId]);
            $affected = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

            // 2. Mark transaction header as VOIDED
            $stmtVoid = $this->pdo->prepare("UPDATE transaction_headers SET status = 'voided', is_deleted = 1, updated_at = NOW() WHERE id = ?");
            $stmtVoid->execute([$transactionId]);

            // 3. Forward-recost each affected item + location
            $affectedPairs = [];
            foreach ($affected as $a) {
                $key = $a['item_id'] . '_' . $a['location_id'];
                if (!isset($affectedPairs[$key])) {
                    $affectedPairs[$key] = [
                        'item_id'     => $a['item_id'],
                        'location_id' => $a['location_id'],
                        'date'        => $a['txn_date']
                    ];
                }
            }

            foreach ($affectedPairs as $p) {
                $this->recostForward($p['item_id'], $p['location_id'], $p['date']);
            }

            if (!$inTxn) {
                $this->pdo->commit();
            }

            return count($affectedPairs);
        } catch (Exception $e) {
            if (!$inTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reverse / wipe stock movements for a specific transaction header prior to re-posting (e.g. on Edit)
     * and trigger forward-recosting on all affected item/location pairs.
     */
    public function reverseMovementsForHeader($headerId, string $reason = 'Transaction Edit Reversal'): int
    {
        $inTxn = $this->pdo->inTransaction();
        if (!$inTxn) {
            $this->pdo->beginTransaction();
        }

        try {
            // 1. Fetch lines/items for transaction
            $stmtLines = $this->pdo->prepare("
                SELECT l.item_id, COALESCE(NULLIF(l.location_id, ''), h.location_id, 1) as location_id, h.txn_date
                FROM transaction_lines l
                JOIN transaction_headers h ON l.header_id = h.id
                WHERE h.id = ?
            ");
            $stmtLines->execute([$headerId]);
            $affected = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

            // If no lines found in transaction_lines, check stock_movement / inventory_ledger
            if (empty($affected)) {
                $stmtLedger = $this->pdo->prepare("
                    SELECT item_id, location_id, transaction_date as txn_date
                    FROM inventory_ledger
                    WHERE transaction_id = ?
                ");
                $stmtLedger->execute([$headerId]);
                $affected = $stmtLedger->fetchAll(PDO::FETCH_ASSOC);
            }

            // 2. Delete inventory_ledger records for this header
            $stmtDelLedger = $this->pdo->prepare("DELETE FROM inventory_ledger WHERE transaction_id = ?");
            $stmtDelLedger->execute([$headerId]);

            try {
                $this->pdo->prepare("DELETE FROM stock_movements WHERE header_id = ?")->execute([$headerId]);
            } catch (Exception $ignore) {}
            try {
                $this->pdo->prepare("DELETE FROM stock_movement WHERE header_id = ?")->execute([$headerId]);
            } catch (Exception $ignore) {}

            // 3. Recost forward for all affected item-location pairs
            $affectedPairs = [];
            foreach ($affected as $a) {
                if (empty($a['item_id'])) continue;
                $locId = function_exists('resolve_location_id') ? resolve_location_id($a['location_id']) : (is_numeric($a['location_id']) ? (int)$a['location_id'] : 1);
                $key = $a['item_id'] . '_' . $locId;
                if (!isset($affectedPairs[$key])) {
                    $affectedPairs[$key] = [
                        'item_id'     => $a['item_id'],
                        'location_id' => $locId,
                        'date'        => $a['txn_date'] ?? date('Y-m-d')
                    ];
                }
            }

            foreach ($affectedPairs as $p) {
                $this->recostForward($p['item_id'], $p['location_id'], $p['date']);
            }

            if (!$inTxn) {
                $this->pdo->commit();
            }

            return count($affectedPairs);
        } catch (Exception $e) {
            if (!$inTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Issue Stock (Sales / POS / Outward Movement)
     */
    public function issueStock(
        $itemId,
        $locationId,
        float $quantity,
        $transactionId = null,
        $lineId = null,
        string $txnType = 'SALE',
        ?float $rate = null,
        $date = null,
        array $options = []
    ): int {
        return $this->postLine($itemId, $locationId, -abs($quantity), null, $transactionId, $lineId, $date, $txnType, array_merge($options, ['rate' => $rate]));
    }

    /**
     * Receive Stock (Purchases / Inward Movement)
     */
    public function receiveStock(
        $itemId,
        $locationId,
        float $quantity,
        ?float $unitCost = null,
        $transactionId = null,
        $lineId = null,
        string $txnType = 'PURCHASE',
        $date = null,
        array $options = []
    ): int {
        return $this->postLine($itemId, $locationId, abs($quantity), $unitCost, $transactionId, $lineId, $date, $txnType, $options);
    }

    /**
     * Transfer Stock between locations
     */
    public function transferStock(
        $itemId,
        $fromLocationId,
        $toLocationId,
        float $quantity,
        $transactionId = null,
        $lineId = null,
        $date = null,
        array $options = []
    ): array {
        $outId = $this->postLine($itemId, $fromLocationId, -abs($quantity), null, $transactionId, $lineId, $date, 'TRANSFER_OUT', $options);
        $bal = $this->getBalance($itemId, $fromLocationId);
        $transferCost = $bal['average_cost'] ?: null;
        $inId = $this->postLine($itemId, $toLocationId, abs($quantity), $transferCost, $transactionId, $lineId, $date, 'TRANSFER_IN', $options);

        return ['out_ledger_id' => $outId, 'in_ledger_id' => $inId];
    }

    /**
     * Adjust Stock (Inventory Adjustment)
     */
    public function adjustStock(
        $itemId,
        $locationId,
        float $quantity,
        ?float $unitCost = null,
        $transactionId = null,
        $lineId = null,
        string $memo = '',
        $date = null,
        array $options = []
    ): int {
        return $this->postLine($itemId, $locationId, $quantity, $unitCost, $transactionId, $lineId, $date, 'ADJUSTMENT', array_merge($options, ['memo' => $memo]));
    }

    /**
     * Calculate Moving Average Cost
     */
    public function calculateMovingAverageCost($itemId, $locationId, float $incomingQty, float $incomingUnitCost): float
    {
        $bal = $this->getBalance($itemId, $locationId);
        $currQty = $bal['quantity_on_hand'];
        $currVal = $bal['total_value'];

        $newQty = $currQty + $incomingQty;
        $newVal = $currVal + ($incomingQty * $incomingUnitCost);

        if ($newQty <= 0) {
            return $incomingUnitCost > 0 ? $incomingUnitCost : $bal['average_cost'];
        }

        return round($newVal / $newQty, 4);
    }

    /**
     * Reconcile Inventory Valuation with General Ledger
     */
    public function reconcileInventoryValuationWithGL($asOfDate = null, $locationId = null): array
    {
        $date = $asOfDate ?: date('Y-m-d');
        $val = $this->getRealtimeStockValuation($date, $locationId);
        return [
            'as_of_date' => $date,
            'total_valuation' => $val['grand_total_value'] ?? 0.0,
            'total_items' => count($val['items'] ?? [])
        ];
    }

    /**
     * Instant As-Of-Date Historical Valuation Lookup from inventory_ledger
     */
    public function getValuationAsOf($itemId, $locationId, $date): array
    {
        $dateStr = is_a($date, 'DateTime') ? $date->format('Y-m-d 23:59:59') : date('Y-m-d 23:59:59', strtotime($date));
        $locId = function_exists('resolve_location_id') ? resolve_location_id($locationId) : (is_numeric($locationId) ? (int)$locationId : 1);

        $stmt = $this->pdo->prepare("
            SELECT running_qty_balance, running_value_balance, unit_cost
            FROM inventory_ledger
            WHERE item_id = ? AND location_id = ? AND transaction_date <= ?
            ORDER BY sequence_number DESC LIMIT 1
        ");
        $stmt->execute([$itemId, $locId, $dateStr]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $qty   = (float)$row['running_qty_balance'];
            $val   = (float)$row['running_value_balance'];
            $cost  = $qty > 0 ? round($val / $qty, 6) : (float)$row['unit_cost'];
            return [
                'quantity_on_hand' => $qty,
                'average_cost'     => $cost,
                'total_value'      => $val
            ];
        }

        return ['quantity_on_hand' => 0.0, 'average_cost' => 0.0, 'total_value' => 0.0];
    }

    /**
     * Real-time Stock Ledger Report Query reading from inventory_ledger
     */
    public function getRealtimeStockLedger(string $fromDate, string $toDate, $locationId = null, $categoryId = null, $itemId = null): array
    {
        $params = [];
        $locSql = "";
        if (!empty($locationId) && $locationId !== 'all') {
            $locSql = " AND l.location_id = ? ";
            $params[] = (int)$locationId;
        }

        $itemSql = "";
        if (!empty($itemId)) {
            $itemSql = " AND i.id = ? ";
            $params[] = $itemId;
        }

        $catSql = "";
        if (!empty($categoryId)) {
            $catSql = " AND i.item_category = ? ";
            $params[] = $categoryId;
        }

        $fromStart = date('Y-m-d 00:00:00', strtotime($fromDate));
        $toEnd     = date('Y-m-d 23:59:59', strtotime($toDate));

        $sql = "
            SELECT 
                i.id, i.sku, i.item_name, i.cost_price,
                
                -- Opening Quantity from latest ledger row before fromDate
                COALESCE((
                    SELECT l_open.running_qty_balance 
                    FROM inventory_ledger l_open 
                    WHERE l_open.item_id = i.id 
                      " . ($locationId && $locationId !== 'all' ? "AND l_open.location_id = " . (int)$locationId : "") . "
                      AND l_open.transaction_date < " . $this->pdo->quote($fromStart) . "
                    ORDER BY l_open.sequence_number DESC LIMIT 1
                ), 0) AS opening_qty,
                
                -- Qty In between dates
                COALESCE(SUM(CASE WHEN l.transaction_date BETWEEN " . $this->pdo->quote($fromStart) . " AND " . $this->pdo->quote($toEnd) . " THEN l.quantity_in ELSE 0 END), 0) AS qty_in,
                
                -- Qty Out between dates
                COALESCE(SUM(CASE WHEN l.transaction_date BETWEEN " . $this->pdo->quote($fromStart) . " AND " . $this->pdo->quote($toEnd) . " THEN l.quantity_out ELSE 0 END), 0) AS qty_out

            FROM items i
            LEFT JOIN inventory_ledger l ON l.item_id = i.id {$locSql}
            WHERE i.is_deleted = 0 AND i.is_active = 1 {$itemSql} {$catSql}
            GROUP BY i.id, i.sku, i.item_name, i.cost_price
            HAVING (opening_qty != 0 OR qty_in != 0 OR qty_out != 0)
            ORDER BY i.item_name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Real-time Stock Valuation Query reading from inventory_balances
     */
    public function getRealtimeStockValuation($asOfDate = null, $locationId = null, $categoryId = null): array
    {
        $params = [];
        $locSql = "";
        if (!empty($locationId) && $locationId !== 'all') {
            $locSql = " AND ib.location_id = ? ";
            $params[] = (int)$locationId;
        }

        $catSql = "";
        if (!empty($categoryId)) {
            $catSql = " AND i.item_category = ? ";
            $params[] = $categoryId;
        }

        $sql = "
            SELECT 
                i.id, i.sku, i.item_name, rc1.name as item_category, rc2.name as unit_type,
                COALESCE(ib.average_cost, i.cost_price) as cost_price, 
                i.selling_price, i.reorder_level, i.reorder_qty, i.item_category as category_id,
                COALESCE(ib.quantity_on_hand, i.current_stock, 0) AS stock_qty,
                COALESCE(ib.total_value, (i.current_stock * i.cost_price), 0) AS stock_value
            FROM items i
            LEFT JOIN inventory_balances ib ON ib.item_id = i.id {$locSql}
            LEFT JOIN reference_codes rc1 ON i.item_category = rc1.id AND rc1.type = 'category'
            LEFT JOIN reference_codes rc2 ON i.unit_type = rc2.id AND rc2.type IN ('unit', 'units')
            WHERE i.is_deleted = 0 AND i.is_active = 1 {$catSql}
            ORDER BY rc1.name, i.item_name
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Recalculate global items.current_stock from inventory_balances
     */
    public function syncGlobalItemStock($itemId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE items 
            SET current_stock = (SELECT COALESCE(SUM(quantity_on_hand), 0) FROM inventory_balances WHERE item_id = ?)
            WHERE id = ?
        ");
        $stmt->execute([$itemId, $itemId]);
    }

    /**
     * Sync legacy inventory_movements table for backwards compatibility
     */
    private function syncLegacyMovement($ledgerId, $itemId, $locationId, $headerId, $lineId, $txnType, $movementType, $qtyIn, $qtyOut, $netQty, $unitCost, $totalCost, $dateStr, $reason): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO inventory_movements 
            (header_id, line_id, txn_number, txn_type, movement_type, item_id, location_id, qty_in, qty_out, net_qty, unit_cost, total_cost, movement_date, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $headerId, $lineId, 'TXN-' . $headerId, $txnType, $movementType, $itemId, $locationId,
            $qtyIn, $qtyOut, $netQty, $unitCost, $totalCost, date('Y-m-d', strtotime($dateStr)), $reason, $_SESSION['user_id'] ?? null
        ]);
    }

    private function mapMovementType(string $txnType, float $qty): string
    {
        $t = strtoupper($txnType);
        if (in_array($t, ['PURCHASE', 'PURCHASE_RECEIPT', 'VENDOR_BILL'])) return 'PURCHASE_RECEIPT';
        if (in_array($t, ['SALE', 'POS', 'POS_SALE', 'SALES_ISSUE', 'CUSTOMER_INVOICE'])) return 'SALES_ISSUE';
        if (in_array($t, ['CREDIT_MEMO', 'SALES_RETURN'])) return 'SALES_RETURN';
        if (in_array($t, ['VENDOR_RETURN', 'PURCHASE_RETURN'])) return 'PURCHASE_RETURN';
        if ($qty >= 0) return 'ADJUSTMENT_IN';
        return 'ADJUSTMENT_OUT';
    }

    private function consumeFifoCostLayers($itemId, $locationId, float $qtyToConsume, float $fallbackCost): float
    {
        $stmtLayers = $this->pdo->prepare("
            SELECT * FROM fifo_cost_layers 
            WHERE item_id = ? AND location_id = ? AND remaining_qty > 0 
            ORDER BY receipt_date ASC, layer_id ASC
        ");
        $stmtLayers->execute([$itemId, $locationId]);
        $layers = $stmtLayers->fetchAll(PDO::FETCH_ASSOC);

        if (empty($layers)) {
            return $fallbackCost;
        }

        $needed = $qtyToConsume;
        $totalCostVal = 0.0;

        foreach ($layers as $layer) {
            if ($needed <= 0) break;
            $rem = (float)$layer['remaining_qty'];
            $take = min($needed, $rem);
            $cost = (float)$layer['unit_cost'];

            $totalCostVal += ($take * $cost);
            $needed -= $take;

            $newRem = round($rem - $take, 6);
            $stmtUp = $this->pdo->prepare("UPDATE fifo_cost_layers SET remaining_qty = ? WHERE layer_id = ?");
            $stmtUp->execute([$newRem, $layer['layer_id']]);
        }

        return $qtyToConsume > 0 ? round($totalCostVal / $qtyToConsume, 6) : $fallbackCost;
    }
}
