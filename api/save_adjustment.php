<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}

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
    $adjustment_account_id = $_POST['adjustment_account_id'] ?? null;
    $memo = $_POST['memo'] ?? '';
    $status = 'posted';
    
    if (!$adjustment_account_id) throw new Exception("Adjustment Account is required");

    $fiscal = calculate_fiscal_info($txn_date);

    $item_ids = $_POST['item_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $rates = $_POST['rate'] ?? [];

    $net_amount = 0;
    $line_data_list = [];

    // Calculate total net adjustment value first
    foreach ($item_ids as $idx => $item_id) {
        if (empty($item_id)) continue;
        $qty = (float)($qtys[$idx] ?? 0);
        $rate = (float)($rates[$idx] ?? 0);
        if ($qty == 0) continue;

        $line_total = $qty * $rate;
        $net_amount += $line_total;

        $line_data_list[] = [
            'item_id' => $item_id,
            'qty' => $qty,
            'rate' => $rate,
            'line_total' => $line_total
        ];
    }

    if (empty($line_data_list)) {
        throw new Exception("Please add at least one valid adjustment line with non-zero quantity.");
    }

    if (!$id) {
        $id = generate_uuid();
        $db->execute("INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, reference_number, memo, net_amount, party_type, party_id, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'account', ?, ?)", [
            $id, $txn_number, 'inventory_adjustment', $txn_date,
            $fiscal['year'], $fiscal['month'], $fiscal['period'],
            $status, $txn_number, $memo, $net_amount, $adjustment_account_id, $_SESSION['user_id']
        ]);
        incrementTransactionNumber('inventory_adjustment');
    } else {
        // Reverse previous stock changes before updating
        $old_lines = $db->fetchAll("SELECT item_id, quantity FROM transaction_lines WHERE header_id = ?", [$id]);
        foreach ($old_lines as $ol) {
            $db->execute("UPDATE items SET current_stock = current_stock - ? WHERE id = ?", [$ol['quantity'], $ol['item_id']]);
        }

        $db->execute("UPDATE transaction_headers SET txn_date = ?, memo = ?, net_amount = ?, party_id = ? WHERE id = ?", [
            $txn_date, $memo, $net_amount, $adjustment_account_id, $id
        ]);
        
        $db->execute("DELETE FROM transaction_lines WHERE header_id = ?", [$id]);
        $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$id]);
    }

    foreach ($line_data_list as $idx => $line) {
        $item_id = $line['item_id'];
        $qty = $line['qty'];
        $rate = $line['rate'];
        $line_total = $line['line_total'];

        $inventory_account_id = get_effective_account($item_id, 'inventory') ?: 'acc-1200';

        // Insert transaction line
        $db->execute("INSERT INTO transaction_lines (id, header_id, item_id, account_id, line_number, quantity, unit_price, tax_rate, tax_amount, line_total, cost_price, gross_profit) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0)", [
            generate_uuid(), $id, $item_id, $inventory_account_id, $idx + 1, $qty, $rate, $line_total, $rate
        ]);

        // Update item stock and cost price
        $db->execute("UPDATE items SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?", [$qty, $rate, $item_id]);

        // Journal Entries impact
        $abs_amount = abs($line_total);
        if ($abs_amount > 0) {
            if ($qty > 0) {
                // Increase: Dr Inventory, Cr Adjustment Account
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $inventory_account_id, $item_id, $abs_amount, 'Inventory Adj IN - ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $adjustment_account_id, $item_id, $abs_amount, 'Inventory Adj IN - ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
            } else {
                // Decrease: Dr Adjustment Account, Cr Inventory
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $adjustment_account_id, $item_id, $abs_amount, 'Inventory Adj OUT - ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
                $db->execute("INSERT INTO journal_entries (id, header_id, account_id, item_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) VALUES (?, ?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)", [
                    generate_uuid(), $id, $inventory_account_id, $item_id, $abs_amount, 'Inventory Adj OUT - ' . $txn_number, $_SESSION['user_id'], $txn_date, $fiscal['period'], $fiscal['year']
                ]);
            }
        }
    }

    $pdo->commit();
    ob_end_clean();
    echo json_encode(['status' => 'success', 'message' => 'Inventory Adjustment has been saved successfully.', 'id' => $id]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
