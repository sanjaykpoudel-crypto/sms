<?php
/**
 * api/InventoryEngine.php
 * Centralized, authoritative Inventory Engine for MNS Liquor ERP.
 * Single business layer for inventory quantity, movements, moving-average costing,
 * valuation, stock transfers, adjustments, stock reversals, and inventory-to-accounting integration.
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

    /**
     * Get available stock quantity for an item at a specific location
     */
    public function getAvailableStock($itemId, $locationId = null): float
    {
        $rawLoc = $locationId ?: get_user_default_location_id();
        $locationId = function_exists('resolve_location_id') ? resolve_location_id($rawLoc) : (is_numeric($rawLoc) ? (int)$rawLoc : 1);
        $stmt = $this->pdo->prepare("SELECT quantity_on_hand FROM inventory_balances WHERE item_id = ? AND location_id = ?");
        $stmt->execute([$itemId, $locationId]);
        $qty = $stmt->fetchColumn();
        if ($qty !== false) {
            return (float)$qty;
        }

        // Fallback to global items.current_stock
        $stmt = $this->pdo->prepare("SELECT current_stock FROM items WHERE id = ?");
        $stmt->execute([$itemId]);
        return (float)($stmt->fetchColumn() ?: 0.0);
    }

    /**
     * Calculate and update moving-average unit cost upon incoming inventory
     */
    public function calculateMovingAverageCost($itemId, $locationId, float $incomingQty, float $incomingUnitCost): float
    {
        $locationId = function_exists('resolve_location_id') ? resolve_location_id($locationId) : (is_numeric($locationId) ? (int)$locationId : 1);
        if ($incomingQty <= 0) {
            $stmt = $this->pdo->prepare("SELECT cost_price FROM items WHERE id = ?");
            $stmt->execute([$itemId]);
            return (float)($stmt->fetchColumn() ?: 0.0);
        }

        // Fetch current quantity and current avg cost
        $stmt = $this->pdo->prepare("SELECT quantity_on_hand, average_cost FROM inventory_balances WHERE item_id = ? AND location_id = ?");
        $stmt->execute([$itemId, $locationId]);
        $currBal = $stmt->fetch(PDO::FETCH_ASSOC);

        $currentQty = (float)($currBal['quantity_on_hand'] ?? 0);
        $currentCost = (float)($currBal['average_cost'] ?? 0);

        if ($currentQty <= 0) {
            $newAvgCost = $incomingUnitCost;
        } else {
            $totalVal = ($currentQty * $currentCost) + ($incomingQty * $incomingUnitCost);
            $newQty = $currentQty + $incomingQty;
            $newAvgCost = $newQty > 0 ? $totalVal / $newQty : $incomingUnitCost;
        }

        $newAvgCost = round($newAvgCost, 4);

        // Update items master cost_price
        $stmtUp = $this->pdo->prepare("UPDATE items SET cost_price = ? WHERE id = ?");
        $stmtUp->execute([$newAvgCost, $itemId]);

        return $newAvgCost;
    }

    /**
     * Internal helper to upsert inventory balance & record movement line
     */
    private function recordMovementAndSyncBalance(array $movement): int
    {
        $locId = function_exists('resolve_location_id') ? resolve_location_id($movement['location_id'] ?? 1) : (is_numeric($movement['location_id'] ?? 1) ? (int)$movement['location_id'] : 1);
        $movement['location_id'] = $locId;

        $stmt = $this->pdo->prepare("INSERT INTO inventory_movements 
            (header_id, line_id, txn_number, txn_type, movement_type, item_id, location_id, qty_in, qty_out, net_qty, unit_cost, total_cost, movement_date, reversal_of_id, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $movement['header_id'] ?? null,
            $movement['line_id'] ?? null,
            $movement['txn_number'] ?? null,
            $movement['txn_type'] ?? 'UNKNOWN',
            $movement['movement_type'],
            $movement['item_id'],
            $movement['location_id'],
            $movement['qty_in'] ?? 0.0,
            $movement['qty_out'] ?? 0.0,
            $movement['net_qty'] ?? 0.0,
            $movement['unit_cost'] ?? 0.0,
            $movement['total_cost'] ?? 0.0,
            $movement['movement_date'] ?? date('Y-m-d'),
            $movement['reversal_of_id'] ?? null,
            $movement['reason'] ?? null,
            $_SESSION['user_id'] ?? null
        ]);
        $movementId = (int)$this->pdo->lastInsertId();

        $itemId = $movement['item_id'];
        $locationId = $movement['location_id'];
        $netQty = (float)($movement['net_qty'] ?? 0.0);
        $unitCost = (float)($movement['unit_cost'] ?? 0.0);

        // Upsert inventory_balances
        $stmtBal = $this->pdo->prepare("
            INSERT INTO inventory_balances (item_id, location_id, quantity_on_hand, available_qty, average_cost)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                quantity_on_hand = quantity_on_hand + VALUES(quantity_on_hand),
                available_qty = available_qty + VALUES(available_qty),
                average_cost = IF(quantity_on_hand + VALUES(quantity_on_hand) > 0, 
                                  ((quantity_on_hand * average_cost) + (VALUES(quantity_on_hand) * VALUES(average_cost))) / (quantity_on_hand + VALUES(quantity_on_hand)), 
                                  average_cost)
        ");
        $stmtBal->execute([$itemId, $locationId, $netQty, $netQty, $unitCost]);

        // Sync items.current_stock
        $this->syncGlobalItemStock($itemId);

        return $movementId;
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
     * Stock Receipt (Purchases)
     */
    public function receiveStock($itemId, $locationId, float $qty, float $unitCost, $headerId = null, $lineId = null, string $txnType = 'PURCHASE', string $date = null, array $context = []): int
    {
        if ($qty <= 0) {
            throw new InventoryException("Stock receipt quantity must be greater than zero.");
        }
        $date = $date ?: date('Y-m-d');
        $locationId = $locationId ?: get_user_default_location_id();

        // Calculate Moving Average Cost
        $avgCost = $this->calculateMovingAverageCost($itemId, $locationId, $qty, $unitCost);

        return $this->recordMovementAndSyncBalance([
            'header_id' => $headerId,
            'line_id' => $lineId,
            'txn_number' => $context['txn_number'] ?? null,
            'txn_type' => $txnType,
            'movement_type' => 'PURCHASE_RECEIPT',
            'item_id' => $itemId,
            'location_id' => $locationId,
            'qty_in' => $qty,
            'qty_out' => 0.0,
            'net_qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => round($qty * $unitCost, 4),
            'movement_date' => $date,
            'reason' => $context['reason'] ?? 'Purchase Receipt'
        ]);
    }

    /**
     * Stock Issue (Sales & POS)
     */
    public function issueStock($itemId, $locationId, float $qty, $headerId = null, $lineId = null, string $txnType = 'SALE', float $unitPrice = 0.0, string $date = null, array $context = []): int
    {
        if ($qty <= 0) {
            throw new InventoryException("Stock issue quantity must be greater than zero.");
        }
        $date = $date ?: date('Y-m-d');
        $locationId = $locationId ?: get_user_default_location_id();

        // Stock availability check (unless forced)
        $available = $this->getAvailableStock($itemId, $locationId);
        if ($available < $qty && empty($context['force_issue'])) {
            $stmt = $this->pdo->prepare("SELECT item_name FROM items WHERE id = ?");
            $stmt->execute([$itemId]);
            $itemName = $stmt->fetchColumn() ?: 'Item';
            throw new InventoryException("Insufficient stock for '{$itemName}'. Available: {$available}, Requested: {$qty}.");
        }

        // Fetch item cost price for COGS
        $stmt = $this->pdo->prepare("SELECT cost_price FROM items WHERE id = ?");
        $stmt->execute([$itemId]);
        $unitCost = (float)($stmt->fetchColumn() ?: 0.0);

        $mType = ($txnType === 'POS') ? 'POS_ISSUE' : 'SALES_ISSUE';

        return $this->recordMovementAndSyncBalance([
            'header_id' => $headerId,
            'line_id' => $lineId,
            'txn_number' => $context['txn_number'] ?? null,
            'txn_type' => $txnType,
            'movement_type' => $mType,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'qty_in' => 0.0,
            'qty_out' => $qty,
            'net_qty' => -$qty,
            'unit_cost' => $unitCost,
            'total_cost' => round($qty * $unitCost, 4),
            'movement_date' => $date,
            'reason' => $context['reason'] ?? 'Stock Issue'
        ]);
    }

    /**
     * Stock Return (Sales Return / Purchase Return)
     */
    public function returnStock($itemId, $locationId, float $qty, $headerId = null, $lineId = null, string $txnType = 'SALES_RETURN', bool $isCustomerReturn = true, string $date = null, array $context = []): int
    {
        if ($qty <= 0) {
            throw new InventoryException("Return quantity must be greater than zero.");
        }
        $date = $date ?: date('Y-m-d');
        $locationId = $locationId ?: get_user_default_location_id();

        $stmt = $this->pdo->prepare("SELECT cost_price FROM items WHERE id = ?");
        $stmt->execute([$itemId]);
        $unitCost = (float)($stmt->fetchColumn() ?: 0.0);

        if ($isCustomerReturn) {
            $mType = 'SALES_RETURN';
            $netQty = $qty;
            $qtyIn = $qty;
            $qtyOut = 0.0;
        } else {
            $mType = 'PURCHASE_RETURN';
            $netQty = -$qty;
            $qtyIn = 0.0;
            $qtyOut = $qty;
        }

        return $this->recordMovementAndSyncBalance([
            'header_id' => $headerId,
            'line_id' => $lineId,
            'txn_number' => $context['txn_number'] ?? null,
            'txn_type' => $txnType,
            'movement_type' => $mType,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'net_qty' => $netQty,
            'unit_cost' => $unitCost,
            'total_cost' => round($qty * $unitCost, 4),
            'movement_date' => $date,
            'reason' => $context['reason'] ?? 'Stock Return'
        ]);
    }

    /**
     * Stock Transfer between two locations
     */
    public function transferStock($itemId, $fromLocationId, $toLocationId, float $qty, $headerId = null, $lineId = null, string $date = null, array $context = []): array
    {
        if ($qty <= 0) {
            throw new InventoryException("Transfer quantity must be greater than zero.");
        }
        $date = $date ?: date('Y-m-d');

        // Check source location stock
        $avail = $this->getAvailableStock($itemId, $fromLocationId);
        if ($avail < $qty && empty($context['force_issue'])) {
            throw new InventoryException("Insufficient stock for transfer at source location. Available: {$avail}, Requested: {$qty}.");
        }

        $stmt = $this->pdo->prepare("SELECT cost_price FROM items WHERE id = ?");
        $stmt->execute([$itemId]);
        $unitCost = (float)($stmt->fetchColumn() ?: 0.0);

        // 1. Transfer Out
        $mOut = $this->recordMovementAndSyncBalance([
            'header_id' => $headerId,
            'line_id' => $lineId,
            'txn_number' => $context['txn_number'] ?? null,
            'txn_type' => 'STOCK_TRANSFER',
            'movement_type' => 'TRANSFER_OUT',
            'item_id' => $itemId,
            'location_id' => $fromLocationId,
            'qty_in' => 0.0,
            'qty_out' => $qty,
            'net_qty' => -$qty,
            'unit_cost' => $unitCost,
            'total_cost' => round($qty * $unitCost, 4),
            'movement_date' => $date,
            'reason' => "Transfer to location {$toLocationId}"
        ]);

        // 2. Transfer In
        $mIn = $this->recordMovementAndSyncBalance([
            'header_id' => $headerId,
            'line_id' => $lineId,
            'txn_number' => $context['txn_number'] ?? null,
            'txn_type' => 'STOCK_TRANSFER',
            'movement_type' => 'TRANSFER_IN',
            'item_id' => $itemId,
            'location_id' => $toLocationId,
            'qty_in' => $qty,
            'qty_out' => 0.0,
            'net_qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => round($qty * $unitCost, 4),
            'movement_date' => $date,
            'reason' => "Transfer from location {$fromLocationId}"
        ]);

        return ['transfer_out_id' => $mOut, 'transfer_in_id' => $mIn];
    }

    /**
     * Stock Adjustment (Increase or Decrease)
     */
    public function adjustStock($itemId, $locationId, float $adjustmentQty, float $newRate = 0.0, $headerId = null, $lineId = null, string $reason = '', string $date = null, array $context = []): int
    {
        if (abs($adjustmentQty) < 0.0001) {
            return 0;
        }
        $date = $date ?: date('Y-m-d');
        $locationId = $locationId ?: get_user_default_location_id();

        $stmt = $this->pdo->prepare("SELECT cost_price FROM items WHERE id = ?");
        $stmt->execute([$itemId]);
        $unitCost = $newRate > 0 ? $newRate : (float)($stmt->fetchColumn() ?: 0.0);

        if ($newRate > 0) {
            $stmtUp = $this->pdo->prepare("UPDATE items SET cost_price = ? WHERE id = ?");
            $stmtUp->execute([$newRate, $itemId]);
        }

        $mType = $adjustmentQty > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT';
        $qtyIn = $adjustmentQty > 0 ? $adjustmentQty : 0.0;
        $qtyOut = $adjustmentQty < 0 ? abs($adjustmentQty) : 0.0;

        return $this->recordMovementAndSyncBalance([
            'header_id' => $headerId,
            'line_id' => $lineId,
            'txn_number' => $context['txn_number'] ?? null,
            'txn_type' => 'ADJUSTMENT',
            'movement_type' => $mType,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'net_qty' => $adjustmentQty,
            'unit_cost' => $unitCost,
            'total_cost' => round(abs($adjustmentQty) * $unitCost, 4),
            'movement_date' => $date,
            'reason' => $reason ?: 'Stock Adjustment'
        ]);
    }

    /**
     * Reverse all inventory movements associated with a transaction header ID
     */
    public function reverseMovementsForHeader($headerId, string $reason = 'Transaction Reversal/Edit'): int
    {
        if (empty($headerId)) return 0;

        $stmt = $this->pdo->prepare("SELECT * FROM inventory_movements WHERE header_id = ? AND reversal_of_id IS NULL");
        $stmt->execute([$headerId]);
        $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reversedCount = 0;
        foreach ($movements as $m) {
            $revNetQty = -$m['net_qty'];
            $revQtyIn = $m['qty_out'];
            $revQtyOut = $m['qty_in'];

            $this->recordMovementAndSyncBalance([
                'header_id' => $headerId,
                'line_id' => $m['line_id'],
                'txn_number' => $m['txn_number'],
                'txn_type' => $m['txn_type'],
                'movement_type' => 'REVERSAL',
                'item_id' => $m['item_id'],
                'location_id' => $m['location_id'],
                'qty_in' => $revQtyIn,
                'qty_out' => $revQtyOut,
                'net_qty' => $revNetQty,
                'unit_cost' => $m['unit_cost'],
                'total_cost' => $m['total_cost'],
                'movement_date' => date('Y-m-d'),
                'reversal_of_id' => $m['id'],
                'reason' => $reason
            ]);
            $reversedCount++;
        }

        return $reversedCount;
    }

    /**
     * Reconcile inventory stock valuation with GL asset account acc-1200 / Inventory Asset
     */
    public function reconcileInventoryValuationWithGL(): array
    {
        $stmt = $this->pdo->query("SELECT COALESCE(SUM(current_stock * cost_price), 0) as subledger_val FROM items WHERE is_deleted = 0");
        $subledgerVal = (float)($stmt->fetchColumn() ?: 0.0);

        $stmtGl = $this->pdo->query("
            SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as gl_bal
            FROM journal_entries j
            JOIN accounts a ON j.account_id = a.id
            JOIN transaction_headers th ON j.header_id = th.id
            WHERE (a.account_subtype IN ('inventory', 'Inventory Asset') OR a.id IN ('7', 'acc-1200'))
              AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
        ");
        $glBal = (float)($stmtGl->fetchColumn() ?: 0.0);

        $diff = round($subledgerVal - $glBal, 2);

        return [
            'subledger_val' => $subledgerVal,
            'gl_val' => $glBal,
            'adjustment_posted' => 0,
            'status' => abs($diff) < 0.05 ? 'MATCH' : 'DIFFERENCE'
        ];
    }
}
