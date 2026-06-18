<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}
require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$db  = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    $return_id       = generate_uuid();
    $original_pos_id = $data['pos_id'];
    $return_date     = date('Y-m-d');
    $items           = $data['items'] ?? [];
    $total_refund    = (float)($data['total_refund'] ?? 0);
    $refund_mode     = $data['refund_mode'] ?? 'cash';

    // 1. Fetch original POS info
    $pos = $db->fetchOne("SELECT * FROM pos_entry WHERE id = ?", [$original_pos_id]);
    if (!$pos) throw new Exception("Original POS record not found.");

    // 2. Create Return Record
    $db->execute(
        "INSERT INTO pos_returns (id, original_pos_id, return_date, total_return_amount, refund_mode, status, created_by)
         VALUES (?, ?, ?, ?, ?, 'completed', ?)",
        [$return_id, $original_pos_id, $return_date, $total_refund, $refund_mode, $_SESSION['user_id']]
    );

    // 3. Process Return Items
    $total_cost_reversal = 0;
    foreach ($items as $item) {
        $item_id = $item['id'];
        $qty     = (float)$item['qty']; // qty being returned
        $rate    = (float)$item['rate'];

        // pos_return_items
        $db->execute(
            "INSERT INTO pos_return_items (id, return_id, item_id, quantity, rate, amount)
             VALUES (?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $return_id, $item_id, $qty, $rate, $qty * $rate]
        );

        // Restore Stock
        $db->execute("UPDATE items SET current_stock = current_stock + ? WHERE id = ?", [$qty, $item_id]);

        // Get cost price for COGS reversal
        $item_info = $db->fetchOne("SELECT cost_price FROM items WHERE id = ?", [$item_id]);
        $total_cost_reversal += ((float)($item_info['cost_price'] ?? 0) * $qty);
    }

    // 4. ERP & GL Impact (Reversal)
    $fiscal = calculate_fiscal_info($return_date);
    $header_id = generate_uuid();
    $txn_number = 'RET-' . $pos['invoice_no'];

    // ERP Header (Credit Note / Return)
    $db->execute(
        "INSERT INTO transaction_headers (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, created_by, party_id, party_type)
         VALUES (?, ?, 'Journal', ?, ?, ?, ?, 'posted', ?, ?, 'customer')",
        [$header_id, $txn_number, $return_date, $fiscal['year'], $fiscal['month'], $fiscal['period'], $_SESSION['user_id'], $pos['customer_id']]
    );

    // GL Logic (Reversal)
    $sales_account     = get_accounting_preference('default_income_account')   ?: 'acc-4100';
    $tax_account       = get_accounting_preference('default_tax_account')      ?: 'acc-2200';
    $cogs_account      = get_accounting_preference('default_cogs_account')     ?: 'acc-5100';
    $inventory_account  = get_accounting_preference('default_asset_account')    ?: 'acc-1200';
    
    // Resolve Refund Account (default to Cash or Bank based on refund_mode)
    $refund_account = ($refund_mode == 'cash') ? 'acc-1100' : 'acc-1110';
    
    // 4a. Reverse Revenue (Dr Sales)
    // Note: This is simplified, assuming same proportions as original
    $db->execute(
        "INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) 
         VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)",
        [generate_uuid(), $header_id, $sales_account, $total_refund * 0.885, 'POS Return Reversal ' . $pos['invoice_no'], $_SESSION['user_id'], $return_date, $fiscal['period'], $fiscal['year']]
    );

    // 4b. Reverse VAT (Dr Tax)
    $db->execute(
        "INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) 
         VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)",
        [generate_uuid(), $header_id, $tax_account, $total_refund * 0.115, 'POS Return Tax Reversal ' . $pos['invoice_no'], $_SESSION['user_id'], $return_date, $fiscal['period'], $fiscal['year']]
    );

    // 4c. Credit Cash/Bank (Refund)
    $db->execute(
        "INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) 
         VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)",
        [generate_uuid(), $header_id, $refund_account, $total_refund, 'POS Refund ' . $pos['invoice_no'], $_SESSION['user_id'], $return_date, $fiscal['period'], $fiscal['year']]
    );

    // 4d. Reverse COGS (Cr COGS, Dr Inventory)
    if ($total_cost_reversal > 0) {
        $db->execute(
            "INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) 
             VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $header_id, $cogs_account, $total_cost_reversal, 'COGS Reversal ' . $pos['invoice_no'], $_SESSION['user_id'], $return_date, $fiscal['period'], $fiscal['year']]
        );
        $db->execute(
            "INSERT INTO journal_entries (id, header_id, account_id, entry_type, amount, memo, created_by, entry_date, fiscal_period, fiscal_year) 
             VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?)",
            [generate_uuid(), $header_id, $inventory_account, $total_cost_reversal, 'Inventory Restored ' . $pos['invoice_no'], $_SESSION['user_id'], $return_date, $fiscal['period'], $fiscal['year']]
        );
    }

    // 5. Update Original POS Status
    $db->execute("UPDATE pos_entry SET status = 'returned' WHERE id = ?", [$original_pos_id]);

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'POS Return processed successfully.', 'return_id' => $return_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
