<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

header('Content-Type: application/json');

$db  = db();
$pdo = $db->getConnection();

try {
    // 1. Ensure inventory_transfers table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_transfers (
            id VARCHAR(36) PRIMARY KEY,
            header_id VARCHAR(36) NOT NULL,
            transfer_number VARCHAR(50) NOT NULL,
            transfer_date DATE NOT NULL,
            from_location_id VARCHAR(36) NOT NULL,
            to_location_id VARCHAR(36) NOT NULL,
            status VARCHAR(20) DEFAULT 'posted',
            total_qty DECIMAL(14,2) DEFAULT 0,
            total_value DECIMAL(14,2) DEFAULT 0,
            memo TEXT,
            created_by VARCHAR(36),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY (header_id),
            KEY (from_location_id),
            KEY (to_location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $id               = $_POST['id'] ?? null;
    $txn_number       = $_POST['txn_number'] ?? '';
    $txn_date         = $_POST['txn_date'] ?? date('Y-m-d');
    $from_location_id = trim($_POST['from_location_id'] ?? '');
    $to_location_id   = trim($_POST['to_location_id'] ?? '');
    $memo             = trim($_POST['memo'] ?? '');
    $item_ids         = $_POST['item_id'] ?? [];
    $quantities       = $_POST['quantity'] ?? [];
    $unit_costs       = $_POST['unit_cost'] ?? [];

    if (empty($from_location_id)) {
        throw new Exception("Please select a Source (From) Location.");
    }
    if (empty($to_location_id)) {
        throw new Exception("Please select a Destination (To) Location.");
    }
    if ($from_location_id === $to_location_id) {
        throw new Exception("Source location and Destination location cannot be the same.");
    }
    if (empty($item_ids) || count($item_ids) === 0) {
        throw new Exception("Please add at least one item to transfer.");
    }

    check_fiscal_year_lock($txn_date);

    $pdo->beginTransaction();

    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('inventory_transfer');
    }

    $fiscal = calculate_fiscal_info($txn_date);

    // Calculate Totals
    $total_qty   = 0;
    $total_value = 0;
    $valid_lines = [];

    foreach ($item_ids as $idx => $item_id) {
        $item_id = trim($item_id);
        if (empty($item_id)) continue;

        $qty  = (float)($quantities[$idx] ?? 0);
        $cost = (float)($unit_costs[$idx] ?? 0);

        if ($qty <= 0) {
            throw new Exception("Transfer quantity for item must be greater than zero.");
        }

        $line_total   = round($qty * $cost, 2);
        $total_qty   += $qty;
        $total_value += $line_total;

        $valid_lines[] = [
            'item_id'    => $item_id,
            'quantity'   => $qty,
            'unit_cost'  => $cost,
            'line_total' => $line_total
        ];
    }

    if (empty($valid_lines)) {
        throw new Exception("Please specify valid items and quantities.");
    }

    if (!$id) {
        $id = generate_uuid();
        $db->execute("
            INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, net_amount, party_type, party_id, created_by, location_id)
            VALUES (?, ?, 'inventory_transfer', ?, ?, ?, ?, 'posted', ?, ?, ?, 'user', ?, ?, ?)
        ", [
            $id, $txn_number, $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $txn_number, $memo, $total_value, $to_location_id, $_SESSION['user_id'], $from_location_id
        ]);

        $db->execute("
            INSERT INTO inventory_transfers (id, header_id, transfer_number, transfer_date, from_location_id, to_location_id, status, total_qty, total_value, memo, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'posted', ?, ?, ?, ?)
        ", [
            generate_uuid(), $id, $txn_number, $txn_date, $from_location_id, $to_location_id, $total_qty, $total_value, $memo, $_SESSION['user_id']
        ]);

        incrementTransactionNumber('inventory_transfer');
    } else {
        $db->execute("
            UPDATE transaction_headers 
            SET txn_date = ?, fiscal_year = ?, fiscal_month = ?, fiscal_period = ?, memo = ?, net_amount = ?, party_id = ?, location_id = ?
            WHERE id = ?
        ", [$txn_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $memo, $total_value, $to_location_id, $from_location_id, $id]);

        $db->execute("
            UPDATE inventory_transfers
            SET transfer_date = ?, from_location_id = ?, to_location_id = ?, total_qty = ?, total_value = ?, memo = ?
            WHERE header_id = ?
        ", [$txn_date, $from_location_id, $to_location_id, $total_qty, $total_value, $memo, $id]);

        $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$id]);
    }

    // Insert transaction lines & sync inventory balances across locations
    foreach ($valid_lines as $idx => $line) {
        $item_id = $line['item_id'];
        $inv_account_id = function_exists('get_effective_account') ? (get_effective_account($item_id, 'inventory') ?: 'acc-1200') : 'acc-1200';
        $db->execute("
            INSERT INTO transaction_lines (id, header_id, line_number, item_id, account_id, quantity, unit_price, cost_price, tax_rate, tax_amount, line_total, gross_profit)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0)
        ", [
            generate_uuid(), $id, $idx + 1, $item_id, $inv_account_id, $line['quantity'], $line['unit_cost'], $line['unit_cost'], $line['line_total']
        ]);

        // Sync real-time inventory balances for Source & Destination locations
        sync_and_get_item_inventory_balances($db, $item_id);
    }

    $pdo->commit();
    clear_dashboard_cache();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Inventory Transfer saved successfully.',
        'id'      => $id
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}
