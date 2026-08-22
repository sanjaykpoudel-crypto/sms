<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!defined('TESTING')) {
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
        exit;
    }
    header('Content-Type: application/json');
    require_once '../database/DBConnection.php';
    require_once 'reference_helper.php';

    $inputJSON = $GLOBALS['mock_input_payload'] ?? file_get_contents('php://input');
    $input = json_decode($inputJSON, true);

    if (!$input) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
        exit;
    }

    $db = db();
    $pdo = $db->getConnection();

    try {
        $result = handleTransaction($input, $pdo, $db);
        http_response_code(200);
        echo json_encode(array_merge(['code' => 200], is_array($result) ? $result : ['status' => 'success']));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'code' => 400, 'message' => $e->getMessage()]);
    }
} else {
    require_once __DIR__ . '/../database/DBConnection.php';
    require_once __DIR__ . '/reference_helper.php';
}

/**
 * Main Transaction Function
 */
function handleTransaction($json, $pdo, $db)
{
    $action = $json['action'] ?? '';
    $tableName = $json['table'] ?? '';
    $primaryKey = $json['primary_key'] ?? 'id';
    $primaryValue = $json['primary_value'] ?? null;
    $data = $json['data'] ?? [];
    $childTables = $json['child_tables'] ?? [];
    $userId = $_SESSION['user_id'] ?? 'system';
    $trigger_sync = false;

    if (empty($action) || empty($tableName)) {
        throw new Exception("Action and Table Name are required");
    }

    if ($tableName === 'vendors') {
        if (array_key_exists('payment_terms_days', $data) && ($data['payment_terms_days'] === '' || $data['payment_terms_days'] === null)) {
            $data['payment_terms_days'] = null;
        }
        if (array_key_exists('credit_limit', $data) && ($data['credit_limit'] === '' || $data['credit_limit'] === null)) {
            $data['credit_limit'] = 0.00;
        }
    }
    if ($tableName === 'customers') {
        if (array_key_exists('payment_terms_days', $data) && ($data['payment_terms_days'] === '' || $data['payment_terms_days'] === null)) {
            $data['payment_terms_days'] = null;
        }
        if (array_key_exists('credit_limit', $data) && ($data['credit_limit'] === '' || $data['credit_limit'] === null)) {
            $data['credit_limit'] = 0.00;
        }
    }

    $pdo->beginTransaction();

    try {
        $insertId = $primaryValue;
        $oldData = null;

        // Fetch old data for audit if updating or deleting
        if (($action === 'update' || $action === 'delete') && $primaryValue) {
            $stmt = $pdo->prepare("SELECT * FROM $tableName WHERE $primaryKey = ?");
            $stmt->execute([$primaryValue]);
            $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldData)
                throw new Exception("Record not found for $action");
        }

        // Check closed fiscal year locks
        if ($tableName === 'transaction_headers') {
            if ($action === 'delete') {
                check_fiscal_year_lock($oldData['txn_date'] ?? null);
            } else if ($action === 'save') {
                check_fiscal_year_lock($data['txn_date'] ?? null);
            } else if ($action === 'update') {
                check_fiscal_year_lock($oldData['txn_date'] ?? null);
                check_fiscal_year_lock($data['txn_date'] ?? ($oldData['txn_date'] ?? null));
            }
        } else if ($tableName === 'pos_entry') {
            if ($action === 'delete') {
                check_fiscal_year_lock(date('Y-m-d', strtotime($oldData['date_time'] ?? 'now')));
            } else if ($action === 'save') {
                check_fiscal_year_lock(date('Y-m-d', strtotime($data['date_time'] ?? 'now')));
            } else if ($action === 'update') {
                check_fiscal_year_lock(date('Y-m-d', strtotime($oldData['date_time'] ?? 'now')));
                check_fiscal_year_lock(date('Y-m-d', strtotime($data['date_time'] ?? ($oldData['date_time'] ?? 'now'))));
            }
        }

        switch ($action) {

            case 'save':
                // Location validations on save
                if ($tableName === 'locations') {
                    $loc_name = trim($data['name'] ?? '');
                    $loc_type = trim($data['type'] ?? '');
                    if (empty($loc_name))
                        throw new Exception("Location Name is required.");
                    if (empty($loc_type))
                        throw new Exception("Location Type is required.");

                    $stmt_loc = $pdo->prepare("SELECT COUNT(*) as count FROM locations WHERE LOWER(TRIM(name)) = LOWER(?) AND is_deleted = 0");
                    $stmt_loc->execute([$loc_name]);
                    if ((int) $stmt_loc->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                        throw new Exception("A location with the name '" . htmlspecialchars($loc_name) . "' already exists.");
                    }
                }

                // Account validations on save
                if ($tableName === 'accounts') {
                    $acc_name = trim($data['account_name'] ?? '');
                    $acc_type_id = $data['account_type_id'] ?? null;
                    if (empty($acc_name))
                        throw new Exception("Account Name is required.");
                    if (empty($acc_type_id))
                        throw new Exception("Account Type is required.");

                    $atm_stmt = $pdo->prepare("SELECT * FROM AccountTypeMaster WHERE AccountTypeId = ?");
                    $atm_stmt->execute([$acc_type_id]);
                    $atm_row = $atm_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$atm_row) {
                        throw new Exception("Selected Account Type is invalid.");
                    }
                    $data['account_subtype'] = $atm_row['AccountTypeName'];
                    $data['account_type'] = strtolower($atm_row['Category']);
                    $data['normal_balance'] = strtolower($atm_row['NormalBalance']);

                    $stmt_name = $pdo->prepare("SELECT COUNT(*) as count FROM accounts WHERE LOWER(TRIM(account_name)) = LOWER(?) AND is_deleted = 0");
                    $stmt_name->execute([$acc_name]);
                    if ((int) $stmt_name->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                        throw new Exception("An account with the name '" . htmlspecialchars($acc_name) . "' already exists.");
                    }
                    unset($data['account_code']);
                }

                if (empty($data['id'])) {
                    $data['id'] = generate_uuid();
                }

                // Auto-generate missing master record codes
                $refTypes = ['items' => 'item', 'customers' => 'customer', 'vendors' => 'vendor', 'accounts' => 'account', 'locations' => 'location'];
                $codeFields = ['items' => 'sku', 'customers' => 'customer_code', 'vendors' => 'vendor_code', 'accounts' => 'account_code', 'locations' => 'code'];

                if (isset($refTypes[$tableName]) && empty($data[$codeFields[$tableName]])) {
                    $data[$codeFields[$tableName]] = getNextTransactionNumber($refTypes[$tableName]);
                    incrementTransactionNumber($refTypes[$tableName]);
                }
                // Filter out non-column keys (such as array inputs loc_cost_price[...])
                $cleanData = [];
                foreach ($data as $k => $v) {
                    if (strpos($k, '[') === false && strpos($k, ']') === false) {
                        $cleanData[$k] = $v;
                    }
                }
                $data = $cleanData;

                $keys = array_keys($data);
                $columns = implode(', ', $keys);
                $placeholders = implode(', ', array_fill(0, count($keys), '?'));

                $stmt = $pdo->prepare("INSERT INTO $tableName ($columns) VALUES ($placeholders)");
                $stmt->execute(array_values($data));
                $insertId = $data['id'] ?? $pdo->lastInsertId();

                foreach ($childTables as $child) {
                    saveChildRows($child, $insertId, $pdo);
                }
                break;

            case 'update':
                if (!$primaryValue)
                    throw new Exception("Primary Value required for update");

                // Location validations on update
                if ($tableName === 'locations') {
                    $loc_name = trim($data['name'] ?? ($oldData['name'] ?? ''));
                    $loc_type = trim($data['type'] ?? ($oldData['type'] ?? ''));
                    if (empty($loc_name))
                        throw new Exception("Location Name is required.");
                    if (empty($loc_type))
                        throw new Exception("Location Type is required.");

                    $stmt_loc = $pdo->prepare("SELECT COUNT(*) as count FROM locations WHERE LOWER(TRIM(name)) = LOWER(?) AND id != ? AND is_deleted = 0");
                    $stmt_loc->execute([$loc_name, $primaryValue]);
                    if ((int) $stmt_loc->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                        throw new Exception("A location with the name '" . htmlspecialchars($loc_name) . "' already exists.");
                    }
                }

                // Account validations on update
                if ($tableName === 'accounts') {
                    $acc_name = trim($data['account_name'] ?? ($oldData['account_name'] ?? ''));
                    $acc_type_id = $data['account_type_id'] ?? ($oldData['account_type_id'] ?? null);
                    if (empty($acc_name))
                        throw new Exception("Account Name is required.");
                    if (empty($acc_type_id))
                        throw new Exception("Account Type is required.");

                    $atm_stmt = $pdo->prepare("SELECT * FROM AccountTypeMaster WHERE AccountTypeId = ?");
                    $atm_stmt->execute([$acc_type_id]);
                    $atm_row = $atm_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$atm_row) {
                        throw new Exception("Selected Account Type is invalid.");
                    }
                    $data['account_subtype'] = $atm_row['AccountTypeName'];
                    $data['account_type'] = strtolower($atm_row['Category']);
                    $data['normal_balance'] = strtolower($atm_row['NormalBalance']);

                    $stmt_name = $pdo->prepare("SELECT COUNT(*) as count FROM accounts WHERE LOWER(TRIM(account_name)) = LOWER(?) AND id != ? AND is_deleted = 0");
                    $stmt_name->execute([$acc_name, $primaryValue]);
                    if ((int) $stmt_name->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                        throw new Exception("An account with the name '" . htmlspecialchars($acc_name) . "' already exists.");
                    }
                    unset($data['account_code']);
                }

                $sets = [];
                $values = [];

                // Auto-generate missing master codes on update if empty
                $refTypes = ['items' => 'item', 'customers' => 'customer', 'vendors' => 'vendor', 'accounts' => 'account'];
                $codeFields = ['items' => 'sku', 'customers' => 'customer_code', 'vendors' => 'vendor_code', 'accounts' => 'account_code'];
                if (isset($refTypes[$tableName])) {
                    $codeField = $codeFields[$tableName];
                    if (array_key_exists($codeField, $data) && empty($data[$codeField])) {
                        $data[$codeField] = getNextTransactionNumber($refTypes[$tableName]);
                        incrementTransactionNumber($refTypes[$tableName]);
                    }
                }

                foreach ($data as $key => $val) {
                    if (strpos($key, '[') !== false || strpos($key, ']') !== false) {
                        continue;
                    }
                    $sets[] = "$key = ?";
                    $values[] = $val;
                }
                $values[] = $primaryValue;

                $stmt = $pdo->prepare("UPDATE $tableName SET " . implode(', ', $sets) . " WHERE $primaryKey = ?");
                $stmt->execute($values);

                // Call sp_sync_gl_accounts if account is changed on master records
                $account_changed = false;
                if ($tableName === 'items') {
                    if (isset($data['cogs_account_id']) && $data['cogs_account_id'] != ($oldData['cogs_account_id'] ?? null))
                        $account_changed = true;
                    if (isset($data['income_account_id']) && $data['income_account_id'] != ($oldData['income_account_id'] ?? null))
                        $account_changed = true;
                    if (isset($data['inventory_account_id']) && $data['inventory_account_id'] != ($oldData['inventory_account_id'] ?? null))
                        $account_changed = true;
                } else if ($tableName === 'customers') {
                    if (isset($data['receivable_account_id']) && $data['receivable_account_id'] != ($oldData['receivable_account_id'] ?? null))
                        $account_changed = true;
                } else if ($tableName === 'vendors') {
                    if (isset($data['payable_account_id']) && $data['payable_account_id'] != ($oldData['payable_account_id'] ?? null))
                        $account_changed = true;
                }

                if ($account_changed) {
                    $trigger_sync = true;
                }

                foreach ($childTables as $child) {
                    $fk = $child['foreign_key'];
                    $ctable = $child['table'];
                    $pdo->prepare("DELETE FROM $ctable WHERE $fk = ?")->execute([$primaryValue]);
                    saveChildRows($child, $primaryValue, $pdo);
                }
                break;

            case 'delete':
                if (!$primaryValue)
                    throw new Exception("Primary Value required for delete");
                $sync_date_to_run = null;

                // If deleting a transaction payment, reverse the applied balances on invoices/bills
                if ($tableName === 'transaction_headers') {
                    $txn_type = $oldData['txn_type'] ?? '';

                    // Check if payment is linked to this customer invoice before deleting
                    if ($txn_type === 'customer_invoice') {
                        $inv_data = $db->fetchOne("SELECT amount_paid FROM customer_invoices WHERE header_id = ?", [$primaryValue]);
                        $amount_paid = (float) ($inv_data['amount_paid'] ?? 0);

                        $pay_count = $db->fetchOne("SELECT COUNT(*) as count FROM payments WHERE applied_to_txn_id = ?", [$primaryValue])['count'] ?? 0;
                        $link_count = $db->fetchOne("SELECT COUNT(*) as count FROM transaction_links WHERE child_id = ? AND link_type LIKE 'payment%'", [$primaryValue])['count'] ?? 0;

                        if ($amount_paid > 0.01 || $pay_count > 0 || $link_count > 0) {
                            throw new Exception("Cannot delete invoice because a payment is linked to it. Please void the payment first.");
                        }
                    }

                    // Check if payment is linked to this vendor bill before deleting
                    if ($txn_type === 'vendor_bill') {
                        $bill_data = $db->fetchOne("SELECT amount_paid FROM vendor_bills WHERE header_id = ?", [$primaryValue]);
                        $amount_paid = (float) ($bill_data['amount_paid'] ?? 0);

                        $pay_count = $db->fetchOne("SELECT COUNT(*) as count FROM payments WHERE applied_to_txn_id = ?", [$primaryValue])['count'] ?? 0;
                        $link_count = $db->fetchOne("SELECT COUNT(*) as count FROM transaction_links WHERE child_id = ? AND link_type LIKE 'payment%'", [$primaryValue])['count'] ?? 0;

                        if ($amount_paid > 0.01 || $pay_count > 0 || $link_count > 0) {
                            throw new Exception("Cannot delete vendor bill because a payment is linked to it. Please void the payment first.");
                        }
                    }

                    if ($txn_type === 'customer_payment' || $txn_type === 'vendor_payment') {
                        // Collect all document IDs linked to this payment before deleting
                        $old_links = $db->fetchAll("SELECT child_id as applied_to_id FROM transaction_links WHERE parent_id = ?", [$primaryValue]);
                        $old_pay_links = $db->fetchAll("SELECT applied_to_txn_id FROM payments WHERE header_id = ? AND applied_to_txn_id IS NOT NULL", [$primaryValue]);

                        $affected_doc_ids = [];
                        foreach ($old_links as $l) {
                            if (!empty($l['applied_to_id']))
                                $affected_doc_ids[] = $l['applied_to_id'];
                        }
                        foreach ($old_pay_links as $pl) {
                            if (!empty($pl['applied_to_txn_id']))
                                $affected_doc_ids[] = $pl['applied_to_txn_id'];
                        }
                        $affected_doc_ids = array_unique($affected_doc_ids);

                        // Delete associated payments, links, and journal entries
                        $pdo->prepare("DELETE FROM payments WHERE header_id = ?")->execute([$primaryValue]);
                        $pdo->prepare("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?")->execute([$primaryValue, $primaryValue]);
                        AccountingEngine::getInstance()->deleteJournalForTransaction($primaryValue);

                        // Recalculate status and balance due on linked invoices, bills, and journal entries
                        foreach ($affected_doc_ids as $doc_id) {
                            recalculate_document_payment_status($doc_id, $pdo);
                        }
                    }
                    // Delete cash denomination child rows when deleting a cash_denomination transaction
                    if ($txn_type === 'cash_denomination') {
                        $pdo->prepare("DELETE FROM cash_denominations WHERE header_id = ?")->execute([$primaryValue]);
                    }

                    // POS summary invoice deletion does not soft-delete POS entries anymore (POS entries remain for aggregation/regeneration)

                    // Rename the txn_number so the original name is freed up for a new transaction
                    $old_txn_number = $oldData['txn_number'];
                    $new_txn_number = $old_txn_number . '-DEL-' . substr(md5(uniqid(rand(), true)), 0, 8);

                    $pdo->prepare("UPDATE transaction_headers SET txn_number = ? WHERE id = ?")->execute([$new_txn_number, $primaryValue]);
                    $pdo->prepare("UPDATE customer_invoices SET invoice_number = ? WHERE header_id = ?")->execute([$new_txn_number, $primaryValue]);
                    $pdo->prepare("UPDATE vendor_bills SET vendor_invoice_number = ? WHERE header_id = ?")->execute([$new_txn_number, $primaryValue]);

                    // If it is a POS summary invoice (starts with POS-SUM-), also mark the consolidated pos_entry as deleted and rename it
                    if (strpos($old_txn_number, 'POS-SUM-') === 0) {
                        $pdo->prepare("UPDATE pos_entry SET is_deleted = 1, invoice_no = ? WHERE invoice_no = ?")->execute([$new_txn_number, $old_txn_number]);
                    }
                }

                // Check if deleting an item that has linked transactions
                if ($tableName === 'items') {
                    $stmt_lines = $pdo->prepare("
                        SELECT h.txn_number, h.txn_type, h.txn_date
                        FROM transaction_lines l
                        JOIN transaction_headers h ON l.header_id = h.id
                        WHERE l.item_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided')
                        ORDER BY h.txn_date DESC
                    ");
                    $stmt_lines->execute([$primaryValue]);
                    $linked_txns = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);

                    $stmt_pos = $pdo->prepare("
                        SELECT pe.invoice_no as txn_number, 'POS Sale' as txn_type, DATE(pe.date_time) as txn_date
                        FROM pos_items pi
                        JOIN pos_entry pe ON pi.pos_id = pe.id
                        WHERE pi.item_id = ? AND pe.is_deleted = 0
                        ORDER BY pe.date_time DESC
                    ");
                    $stmt_pos->execute([$primaryValue]);
                    $linked_pos = $stmt_pos->fetchAll(PDO::FETCH_ASSOC);

                    $all_linked = array_merge($linked_txns, $linked_pos);
                    if (!empty($all_linked)) {
                        $total_count = count($all_linked);
                        $sample_items = array_slice($all_linked, 0, 5);
                        $formatted_list = [];
                        foreach ($sample_items as $st) {
                            $type_name = ucfirst(str_replace('_', ' ', $st['txn_type']));
                            $formatted_list[] = "{$type_name} #{$st['txn_number']} ({$st['txn_date']})";
                        }
                        $txn_summary = implode(', ', $formatted_list);
                        if ($total_count > 5) {
                            $txn_summary .= " and " . ($total_count - 5) . " more";
                        }
                        throw new Exception("Cannot delete item. This item has {$total_count} associated transaction(s): {$txn_summary}. Please void or delete these transactions first.");
                    }
                }

                // ── Customers: check linked invoices, payments, journal entries ──
                if ($tableName === 'customers') {
                    $cname = $oldData['full_name'] ?? $oldData['company_name'] ?? $primaryValue;

                    $linked = [];

                    $inv_rows = $pdo->prepare("
                        SELECT h.txn_number, h.txn_type, h.txn_date
                        FROM customer_invoices ci
                        JOIN transaction_headers h ON ci.header_id = h.id
                        WHERE ci.customer_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void','voided')
                        ORDER BY h.txn_date DESC LIMIT 10
                    ");
                    $inv_rows->execute([$primaryValue]);
                    foreach ($inv_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $linked[] = ucfirst(str_replace('_',' ',$r['txn_type'])) . " #{$r['txn_number']} ({$r['txn_date']})";
                    }

                    $pay_rows = $pdo->prepare("
                        SELECT h.txn_number, h.txn_type, h.txn_date
                        FROM payments p
                        JOIN transaction_headers h ON p.header_id = h.id
                        WHERE p.customer_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void','voided')
                        ORDER BY h.txn_date DESC LIMIT 5
                    ");
                    $pay_rows->execute([$primaryValue]);
                    foreach ($pay_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $linked[] = "Payment #{$r['txn_number']} ({$r['txn_date']})";
                    }

                    if (!empty($linked)) {
                        $total = count($linked);
                        $sample = array_slice($linked, 0, 5);
                        $msg = implode('; ', $sample);
                        if ($total > 5) $msg .= " and " . ($total - 5) . " more";
                        throw new Exception("Cannot delete customer '{$cname}'. There are {$total} related record(s): {$msg}. Please remove these records first.");
                    }
                }

                // ── Vendors: check linked bills, payments, journal entries ──
                if ($tableName === 'vendors') {
                    $vname = $oldData['company_name'] ?? $oldData['full_name'] ?? $primaryValue;

                    $linked = [];

                    $bill_rows = $pdo->prepare("
                        SELECT h.txn_number, h.txn_type, h.txn_date
                        FROM vendor_bills vb
                        JOIN transaction_headers h ON vb.header_id = h.id
                        WHERE vb.vendor_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void','voided')
                        ORDER BY h.txn_date DESC LIMIT 10
                    ");
                    $bill_rows->execute([$primaryValue]);
                    foreach ($bill_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $linked[] = ucfirst(str_replace('_',' ',$r['txn_type'])) . " #{$r['txn_number']} ({$r['txn_date']})";
                    }

                    $pay_rows = $pdo->prepare("
                        SELECT h.txn_number, h.txn_type, h.txn_date
                        FROM payments p
                        JOIN transaction_headers h ON p.header_id = h.id
                        WHERE p.vendor_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void','voided')
                        ORDER BY h.txn_date DESC LIMIT 5
                    ");
                    $pay_rows->execute([$primaryValue]);
                    foreach ($pay_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $linked[] = "Payment #{$r['txn_number']} ({$r['txn_date']})";
                    }

                    if (!empty($linked)) {
                        $total = count($linked);
                        $sample = array_slice($linked, 0, 5);
                        $msg = implode('; ', $sample);
                        if ($total > 5) $msg .= " and " . ($total - 5) . " more";
                        throw new Exception("Cannot delete vendor '{$vname}'. There are {$total} related record(s): {$msg}. Please remove these records first.");
                    }
                }

                // ── Locations: check linked transactions and inventory balances ──
                if ($tableName === 'locations') {
                    $lname = $oldData['name'] ?? $primaryValue;
                    $linked = [];

                    $txn_rows = $pdo->prepare("
                        SELECT txn_number, txn_type, txn_date FROM transaction_headers
                        WHERE location_id = ? AND is_deleted = 0 AND status NOT IN ('void','voided')
                        ORDER BY txn_date DESC LIMIT 10
                    ");
                    $txn_rows->execute([$primaryValue]);
                    foreach ($txn_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $linked[] = ucfirst(str_replace('_',' ',$r['txn_type'])) . " #{$r['txn_number']} ({$r['txn_date']})";
                    }

                    $inv_cnt = $pdo->prepare("SELECT COUNT(*) as c FROM inventory_balances WHERE location_id = ? AND quantity_on_hand != 0");
                    $inv_cnt->execute([$primaryValue]);
                    $inv_c = (int)($inv_cnt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                    if ($inv_c > 0) $linked[] = "{$inv_c} inventory balance record(s)";

                    if (!empty($linked)) {
                        $total = count($linked);
                        $sample = array_slice($linked, 0, 5);
                        $msg = implode('; ', $sample);
                        if ($total > 5) $msg .= " and " . ($total - 5) . " more";
                        throw new Exception("Cannot delete location '{$lname}'. There are {$total} related record(s): {$msg}. Please reassign or delete these records first.");
                    }
                }

                // ── Users: check linked transactions and activities ──
                if ($tableName === 'users') {
                    $uname = $oldData['username'] ?? $oldData['full_name'] ?? $primaryValue;
                    $linked = [];

                    $txn_rows = $pdo->prepare("
                        SELECT txn_number, txn_type, txn_date FROM transaction_headers
                        WHERE created_by = ? AND is_deleted = 0
                        ORDER BY txn_date DESC LIMIT 5
                    ");
                    $txn_rows->execute([$primaryValue]);
                    foreach ($txn_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $linked[] = ucfirst(str_replace('_',' ',$r['txn_type'])) . " #{$r['txn_number']} ({$r['txn_date']})";
                    }

                    if (!empty($linked)) {
                        $total = count($linked);
                        $sample = array_slice($linked, 0, 5);
                        $msg = implode('; ', $sample);
                        if ($total > 5) $msg .= " and " . ($total - 5) . " more";
                        throw new Exception("Cannot delete user '{$uname}'. This user has created {$total} transaction(s): {$msg}. Please reassign these records first.");
                    }
                }

                // ── Roles: check if any user is assigned this role ──
                if ($tableName === 'roles') {
                    $rname = $oldData['name'] ?? $primaryValue;

                    $user_rows = $pdo->prepare("SELECT username FROM users WHERE role_id = ? AND is_deleted = 0 LIMIT 10");
                    $user_rows->execute([$primaryValue]);
                    $users_assigned = $user_rows->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($users_assigned)) {
                        $total = count($users_assigned);
                        $sample = implode(', ', array_slice($users_assigned, 0, 5));
                        if ($total > 5) $sample .= " and " . ($total - 5) . " more";
                        throw new Exception("Cannot delete role '{$rname}'. It is assigned to {$total} user(s): {$sample}. Please reassign users to another role first.");
                    }
                }

                // Check if deleting an account that is system core or has linked records
                if ($tableName === 'accounts') {
                    $acc_code = $oldData['account_code'] ?? '';
                    $acc_name = $oldData['account_name'] ?? '';
                    $is_sys = (int) ($oldData['is_system_account'] ?? 0);
                    $core_codes = ['1010', '1020', '1200', '2100', '4100', '5100', '3100'];

                    if ($is_sys || in_array($acc_code, $core_codes)) {
                        throw new Exception("System Core Account '{$acc_code} - {$acc_name}' is protected and cannot be deleted.");
                    }

                    $stmt_je = $pdo->prepare("
                        SELECT h.txn_number, h.txn_type, h.txn_date
                        FROM journal_entries j
                        JOIN transaction_headers h ON j.header_id = h.id
                        WHERE j.account_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided')
                        ORDER BY h.txn_date DESC
                    ");
                    $stmt_je->execute([$primaryValue]);
                    $linked_jes = $stmt_je->fetchAll(PDO::FETCH_ASSOC);

                    $stmt_items = $pdo->prepare("
                        SELECT sku, item_name FROM items
                        WHERE (inventory_account_id = ? OR cogs_account_id = ? OR income_account_id = ?) AND is_deleted = 0
                    ");
                    $stmt_items->execute([$primaryValue, $primaryValue, $primaryValue]);
                    $linked_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($linked_jes) || !empty($linked_items)) {
                        $total_count = count($linked_jes) + count($linked_items);
                        $formatted_list = [];

                        foreach (array_slice($linked_jes, 0, 3) as $st) {
                            $type_name = ucfirst(str_replace('_', ' ', $st['txn_type']));
                            $formatted_list[] = "{$type_name} #{$st['txn_number']} ({$st['txn_date']})";
                        }
                        foreach (array_slice($linked_items, 0, 3) as $it) {
                            $formatted_list[] = "Item {$it['item_name']} ({$it['sku']})";
                        }

                        $summary = implode(', ', $formatted_list);
                        if ($total_count > count($formatted_list)) {
                            $summary .= " and " . ($total_count - count($formatted_list)) . " more";
                        }

                        throw new Exception("Cannot delete account '{$acc_name}'. This account has {$total_count} associated transaction/master record(s): {$summary}. Please reassign or void associated records first.");
                    }
                }

                // If deleting a standalone pos_entry record, rename its invoice_no to free up unique constraint
                if ($tableName === 'pos_entry') {
                    $old_invoice_no = $oldData['invoice_no'] ?? '';
                    if ($old_invoice_no) {
                        $new_invoice_no = $old_invoice_no . '-DEL-' . substr(md5(uniqid(rand(), true)), 0, 8);
                        $pdo->prepare("UPDATE pos_entry SET invoice_no = ? WHERE id = ?")->execute([$new_invoice_no, $primaryValue]);
                    }

                    // Revert stock of the deleted POS items
                    $stmt = $pdo->prepare("SELECT item_id, quantity FROM pos_items WHERE pos_id = ?");
                    $stmt->execute([$primaryValue]);
                    $old_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($old_items as $oi) {
                        $pdo->prepare("UPDATE items SET current_stock = current_stock + ? WHERE id = ?")->execute([$oi['quantity'], $oi['item_id']]);
                    }

                    // Set date to run daily summary sync
                    $sync_date_to_run = date('Y-m-d', strtotime($oldData['date_time']));
                }

                if (array_key_exists('is_deleted', $oldData)) {
                    $updateFields = ["is_deleted = 1"];
                    if (array_key_exists('updated_at', $oldData)) {
                        $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
                    }
                    $setClause = implode(', ', $updateFields);
                    $pdo->prepare("UPDATE $tableName SET $setClause WHERE $primaryKey = ?")->execute([$primaryValue]);
                } else {
                    $pdo->prepare("DELETE FROM $tableName WHERE $primaryKey = ?")->execute([$primaryValue]);
                }
                break;

            default:
                throw new Exception("Invalid action: $action");
        }

        // Audit Logging
        logAudit($tableName, $action, $oldData, $data, $insertId, $userId, $pdo);

        $pdo->commit();
        clear_dashboard_cache();
        if (function_exists('auto_sync_pos_items_and_invoices')) {
            auto_sync_pos_items_and_invoices(true);
        }

        if (isset($sync_date_to_run) && $sync_date_to_run) {
            sync_daily_pos_summary($sync_date_to_run);
        }

        if ($trigger_sync) {
            trigger_background_sync();
        }

        $messages = [
            'save' => 'Record has been saved successfully.',
            'update' => 'Record has been updated successfully.',
            'delete' => 'Record has been deleted successfully.',
        ];

        return [
            'status' => 'success',
            'message' => $messages[$action] ?? 'Operation completed successfully.',
            'id' => $insertId
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Helper to save child rows
 */
function saveChildRows($child, $parentId, $pdo)
{
    $cTable = $child['table'];
    $fk = $child['foreign_key'];
    $rows = $child['rows'] ?? [];

    foreach ($rows as $row) {
        $row[$fk] = $parentId;
        if (!isset($row['id']))
            $row['id'] = generate_uuid();

        $keys = array_keys($row);
        $columns = implode(', ', $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        $pdo->prepare("INSERT INTO $cTable ($columns) VALUES ($placeholders)")
            ->execute(array_values($row));
    }
}

/**
 * Audit Logger
 */
function logAudit($table, $action, $old, $new, $refId, $userId, $pdo)
{
    if ($action === 'save')
        $action = 'create';
    $pdo->prepare(
        "INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$table, $action, (string)$refId, json_encode($old), json_encode($new), $userId !== null ? (string)$userId : null]);

    if (in_array(strtolower($action), ['update', 'save', 'create']) && !empty($refId)) {
        try {
            if ($table === 'transaction_headers') {
                $pdo->prepare("UPDATE transaction_headers SET updated_by = ? WHERE id = ?")->execute([$userId, $refId]);
            } elseif ($table === 'items') {
                $pdo->prepare("UPDATE items SET updated_by = ? WHERE id = ?")->execute([$userId, $refId]);
            } elseif ($table === 'customers') {
                $pdo->prepare("UPDATE customers SET updated_by = ? WHERE id = ?")->execute([$userId, $refId]);
            } elseif ($table === 'vendors') {
                $pdo->prepare("UPDATE vendors SET updated_by = ? WHERE id = ?")->execute([$userId, $refId]);
            } elseif ($table === 'users') {
                $pdo->prepare("UPDATE users SET updated_by = ? WHERE id = ?")->execute([$userId, $refId]);
            }
        } catch (Throwable $e) {}
    }
}

/**
 * Triggers sp_sync_gl_accounts runner asynchronously in background
 */
function trigger_background_sync()
{
    $php_path = 'C:\\xampp\\php\\php.exe';
    if (defined('PHP_BINARY') && !empty(PHP_BINARY) && strpos(PHP_BINARY, 'php') !== false) {
        $php_path = PHP_BINARY;
    }
    $script = __DIR__ . '/run_sp_sync.php';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'start /B "" "' . $php_path . '" "' . $script . '" > NUL 2>&1';
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = '"' . $php_path . '" "' . $script . '" > /dev/null 2>&1 &';
        exec($cmd);
    }
}
