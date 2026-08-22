<?php
require_once __DIR__ . '/../../database/DBConnection.php';
require_once __DIR__ . '/../../api/InventoryEngine.php';

function seed_perpetual_inventory_ledger() {
    $db = db();
    $pdo = $db->getConnection();

    echo "--- INITIALIZING PERPETUAL INVENTORY LEDGER & BALANCES ---\n";

    // 1. Truncate perpetual ledger tables
    $pdo->exec("TRUNCATE TABLE inventory_ledger");
    $pdo->exec("TRUNCATE TABLE inventory_balances");
    $pdo->exec("TRUNCATE TABLE fifo_cost_layers");
    $pdo->exec("TRUNCATE TABLE inventory_movements");

    // 2. Fetch all posted active transaction lines in exact chronological order
    $stmt = $pdo->query("
        SELECT 
            h.id as transaction_id,
            l.id as line_id,
            h.txn_number,
            h.txn_type,
            h.txn_date,
            l.item_id,
            COALESCE(NULLIF(l.location_id, ''), h.location_id, 1) as location_id,
            l.quantity,
            l.unit_price,
            l.cost_price,
            l.conversion_factor,
            COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(NULLIF(l.conversion_factor, 0), 1)) as calc_qty
        FROM transaction_lines l
        JOIN transaction_headers h ON l.header_id = h.id
        WHERE h.is_deleted = 0 
          AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.txn_type IN ('vendor_bill', 'purchase_receipt', 'credit_memo', 'sales_return', 'customer_invoice', 'pos_sale', 'sales_issue', 'vendor_return', 'purchase_return', 'inventory_adjustment', 'inventory_transfer', 'adjustment')
        ORDER BY h.txn_date ASC, h.id ASC, l.id ASC
    ");

    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($lines) . " transaction lines to seed into perpetual ledger.\n";

    $engine = InventoryEngine::getInstance();
    $count = 0;

    foreach ($lines as $line) {
        $itemId = (int)$line['item_id'];
        $locId  = (int)$line['location_id'];
        $qty    = (float)$line['calc_qty'];
        $txnType = $line['txn_type'];

        // Standardize net sign convention
        if (in_array($txnType, ['customer_invoice', 'pos_sale', 'sales_issue', 'vendor_return', 'purchase_return'])) {
            $qty = -abs($qty);
        } elseif (in_array($txnType, ['vendor_bill', 'purchase_receipt', 'credit_memo', 'sales_return'])) {
            $qty = abs($qty);
        }

        $cost = (float)($line['cost_price'] ?: $line['unit_price']);

        // Fetch next sequence for item+loc
        $stmtSeq = $pdo->prepare("SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM inventory_ledger WHERE item_id = ? AND location_id = ?");
        $stmtSeq->execute([$itemId, $locId]);
        $seq = (int)$stmtSeq->fetchColumn();

        $engine->postLine(
            $itemId,
            $locId,
            $qty,
            $cost,
            $line['transaction_id'],
            $line['line_id'],
            $line['txn_date'],
            $txnType,
            ['reason' => 'Perpetual Ledger Initial Seed']
        );
        $count++;
    }

    echo "Successfully posted $count ledger rows into perpetual inventory_ledger.\n";

    // 3. Resync global items.current_stock
    $items = $db->fetchAll("SELECT id FROM items WHERE is_deleted = 0");
    foreach ($items as $it) {
        $engine->syncGlobalItemStock($it['id']);
    }
    echo "Global item stock synchronized across all items.\n";
    echo "--- SEED COMPLETE ---\n";
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    seed_perpetual_inventory_ledger();
}
