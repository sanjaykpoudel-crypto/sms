<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';
require_once __DIR__ . '/InventoryEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}
enforce_csrf_protection();

$db = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $id = $_POST['id'] ?? null;
    $txn_number = $_POST['txn_number'] ?? '';
    if (empty($txn_number)) {
        $txn_number = getNextTransactionNumber('inventory_adjustment');
    }
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');

    // Check closed fiscal year lock
    if ($id) {
        $old_header = $db->fetchOne("SELECT txn_date FROM transaction_headers WHERE id = ?", [$id]);
        if ($old_header) {
            check_fiscal_year_lock($old_header['txn_date']);
        }
    }
    check_fiscal_year_lock($txn_date);
    $adjustment_account_id = $_POST['adjustment_account_id'] ?? null;
    $memo = $_POST['memo'] ?? '';
    $status = 'posted';
    
    if (!$adjustment_account_id) throw new Exception("Adjustment Account is required");

    $fiscal = calculate_fiscal_info($txn_date);

    $item_ids   = $_POST['item_id']           ?? [];
    $qtys       = $_POST['qty']               ?? [];
    $rates      = $_POST['rate']              ?? [];
    $line_locs  = $_POST['line_location_id']  ?? [];

    $net_amount = 0;
    $line_data_list = [];
    $first_location_id = null;

    // Calculate total net adjustment value first
    foreach ($item_ids as $idx => $item_id) {
        if (empty($item_id)) continue;
        $qty  = (float)($qtys[$idx]  ?? 0);
        $rate = (float)($rates[$idx] ?? 0);
        if ($qty == 0) continue;

        $loc_id = $line_locs[$idx] ?? null;
        if (!$first_location_id && $loc_id) $first_location_id = $loc_id;

        $line_total = $qty * $rate;
        $net_amount += $line_total;

        $line_data_list[] = [
            'item_id'     => $item_id,
            'qty'         => $qty,
            'rate'        => $rate,
            'line_total'  => $line_total,
            'location_id' => $loc_id
        ];
    }

    if (empty($line_data_list)) {
        throw new Exception("Please add at least one valid adjustment line with non-zero quantity.");
    }
    if (!$first_location_id) {
        $first_location_id = get_user_default_location_id();
    }

    if (!$id) {
        $id = generate_uuid();
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, net_amount, party_type, party_id, location_id, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)", [
            $id, $txn_number, 'inventory_adjustment', $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $status, $txn_number, $memo, $net_amount, $adjustment_account_id, $first_location_id, $_SESSION['user_id']
        ]);
        incrementTransactionNumber('inventory_adjustment');
    } else {
        // Reverse previous stock changes via InventoryEngine
        InventoryEngine::getInstance()->reverseMovementsForHeader($id, 'Inventory Adjustment Edit Reversal');

        $db->execute("UPDATE transaction_headers SET txn_date = ?, memo = ?, net_amount = ?, party_id = ?, location_id = ? WHERE id = ?", [
            $txn_date, $memo, $net_amount, $adjustment_account_id, $first_location_id, $id
        ]);
        
        $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    $total_adjustment_credit = 0;
    $total_adjustment_debit = 0;

    foreach ($line_data_list as $idx => $line) {
        $item_id   = $line['item_id'];
        $qty       = $line['qty'];
        $rate      = $line['rate'];
        $line_total = $line['line_total'];
        $line_loc  = $line['location_id'];

        $inventory_account_id = get_effective_account($item_id, 'inventory') ?: 'acc-1200';

        // Insert transaction line (with location_id)
        $db->execute("INSERT INTO transaction_lines (id, header_id, item_id, account_id, location_id, line_number, quantity, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0)", [
            generate_uuid(), $id, $item_id, $inventory_account_id, $line_loc, $idx + 1, $qty, $rate, $line_total, $rate
        ]);

        if ($rate > 0) {
            $db->execute("UPDATE items SET cost_price = ? WHERE id = ?", [$rate, $item_id]);
        }

        // Post stock adjustment via InventoryEngine
        InventoryEngine::getInstance()->adjustStock($item_id, $line_loc, $qty, $rate, $id, null, $memo, $txn_date, [
            'txn_number' => $txn_number
        ]);

        // Sync all-location stock figures from transaction history
        sync_and_get_item_inventory_balances($db, $item_id);

        // Journal Entries impact for the item's inventory asset account
        $abs_amount = abs($line_total);
        if ($abs_amount > 0) {
            if ($qty > 0) {
                // Increase: Dr Inventory
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $inventory_account_id, $item_id, $abs_amount, 'Inventory Adj IN - ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
                $total_adjustment_credit += $abs_amount;
            } else {
                // Decrease: Cr Inventory
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $inventory_account_id, $item_id, $abs_amount, 'Inventory Adj OUT - ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
                $total_adjustment_debit += $abs_amount;
            }
        }
    }

    // Insert the single summarized offsetting Journal Entry / Entries for the Adjustment Account
    if ($total_adjustment_credit > 0) {
        $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $adjustment_account_id, null, $total_adjustment_credit, 'Inventory Adj Offset CR - ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
        ]);
    }
    if ($total_adjustment_debit > 0) {
        $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
            generate_uuid(), $id, $adjustment_account_id, null, $total_adjustment_debit, 'Inventory Adj Offset DR - ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
        ]);
    }

    if (function_exists('sync_daily_pos_summary')) {
        sync_daily_pos_summary($txn_date);
    }

    $pdo->commit();
    clear_dashboard_cache();
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['status' => 'success', 'code' => 200, 'message' => 'Inventory Adjustment has been saved successfully.', 'id' => $id]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 400, 'message' => $e->getMessage()]);
    exit;
}
