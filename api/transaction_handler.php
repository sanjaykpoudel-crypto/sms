<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!defined('TESTING')) {
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
        exit;
    }
    header('Content-Type: application/json');
    require_once '../database/DBConnection.php';
    require_once 'reference_helper.php';

    $inputJSON = file_get_contents('php://input');
    $input     = json_decode($inputJSON, true);

    if (!$input) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
        exit;
    }

    $db  = db();
    $pdo = $db->getConnection();

    try {
        $result = handleTransaction($input, $pdo, $db);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    require_once __DIR__ . '/../database/DBConnection.php';
    require_once __DIR__ . '/reference_helper.php';
}

/**
 * Main Transaction Function
 */
function handleTransaction($json, $pdo, $db) {
    $action       = $json['action']        ?? '';
    $tableName    = $json['table']         ?? '';
    $primaryKey   = $json['primary_key']   ?? 'id';
    $primaryValue = $json['primary_value'] ?? null;
    $data         = $json['data']          ?? [];
    $childTables  = $json['child_tables']  ?? [];
    $userId       = $_SESSION['user_id']   ?? 'system';
    $trigger_sync = false;

    if (empty($action) || empty($tableName)) {
        throw new Exception("Action and Table Name are required");
    }

    $pdo->beginTransaction();

    try {
        $insertId = $primaryValue;
        $oldData  = null;

        // Fetch old data for audit if updating or deleting
        if (($action === 'update' || $action === 'delete') && $primaryValue) {
            $stmt = $pdo->prepare("SELECT * FROM $tableName WHERE $primaryKey = ?");
            $stmt->execute([$primaryValue]);
            $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldData) throw new Exception("Record not found for $action");
        }

        switch ($action) {

            case 'save':
                if (empty($data['id'])) {
                    $data['id'] = generate_uuid();
                }

                // Auto-generate missing master record codes
                $refTypes = ['items' => 'item', 'customers' => 'customer', 'vendors' => 'vendor'];
                $codeFields = ['items' => 'sku', 'customers' => 'customer_code', 'vendors' => 'vendor_code'];
                
                if (isset($refTypes[$tableName]) && empty($data[$codeFields[$tableName]])) {
                    $data[$codeFields[$tableName]] = getNextTransactionNumber($refTypes[$tableName]);
                }
                $keys         = array_keys($data);
                $columns      = implode(', ', $keys);
                $placeholders = implode(', ', array_fill(0, count($keys), '?'));

                $stmt = $pdo->prepare("INSERT INTO $tableName ($columns) VALUES ($placeholders)");
                $stmt->execute(array_values($data));
                $insertId = $data['id'] ?? $pdo->lastInsertId();

                foreach ($childTables as $child) {
                    saveChildRows($child, $insertId, $pdo);
                }

                // Increment auto-numbering for master records
                $refTypes = ['items' => 'item', 'customers' => 'customer', 'vendors' => 'vendor'];
                if (isset($refTypes[$tableName])) {
                    incrementTransactionNumber($refTypes[$tableName]);
                }
                break;

            case 'update':
                if (!$primaryValue) throw new Exception("Primary Value required for update");

                $sets   = [];
                $values = [];
                
                // Auto-generate missing master codes on update if empty
                $refTypes = ['items' => 'item', 'customers' => 'customer', 'vendors' => 'vendor'];
                $codeFields = ['items' => 'sku', 'customers' => 'customer_code', 'vendors' => 'vendor_code'];
                if (isset($refTypes[$tableName])) {
                    $codeField = $codeFields[$tableName];
                    if (array_key_exists($codeField, $data) && empty($data[$codeField])) {
                        $data[$codeField] = getNextTransactionNumber($refTypes[$tableName]);
                        incrementTransactionNumber($refTypes[$tableName]);
                    }
                }

                foreach ($data as $key => $val) {
                    $sets[]   = "$key = ?";
                    $values[] = $val;
                }
                $values[] = $primaryValue;

                $stmt = $pdo->prepare("UPDATE $tableName SET " . implode(', ', $sets) . " WHERE $primaryKey = ?");
                $stmt->execute($values);

                // Call sp_sync_gl_accounts if account is changed on master records
                $account_changed = false;
                if ($tableName === 'items') {
                    if (isset($data['cogs_account_id']) && $data['cogs_account_id'] != ($oldData['cogs_account_id'] ?? null)) $account_changed = true;
                    if (isset($data['income_account_id']) && $data['income_account_id'] != ($oldData['income_account_id'] ?? null)) $account_changed = true;
                    if (isset($data['inventory_account_id']) && $data['inventory_account_id'] != ($oldData['inventory_account_id'] ?? null)) $account_changed = true;
                } else if ($tableName === 'customers') {
                    if (isset($data['receivable_account_id']) && $data['receivable_account_id'] != ($oldData['receivable_account_id'] ?? null)) $account_changed = true;
                } else if ($tableName === 'vendors') {
                    if (isset($data['payable_account_id']) && $data['payable_account_id'] != ($oldData['payable_account_id'] ?? null)) $account_changed = true;
                }

                if ($account_changed) {
                    $trigger_sync = true;
                }

                foreach ($childTables as $child) {
                    $fk     = $child['foreign_key'];
                    $ctable = $child['table'];
                    $pdo->prepare("DELETE FROM $ctable WHERE $fk = ?")->execute([$primaryValue]);
                    saveChildRows($child, $primaryValue, $pdo);
                }
                break;

            case 'delete':
                if (!$primaryValue) throw new Exception("Primary Value required for delete");

                // If deleting a transaction payment, reverse the applied balances on invoices/bills
                if ($tableName === 'transaction_headers') {
                    $txn_type = $oldData['txn_type'] ?? '';
                    
                    // Check if payment is linked to this customer invoice before deleting
                    if ($txn_type === 'customer_invoice') {
                        $inv_data = $db->fetchOne("SELECT amount_paid FROM customer_invoices WHERE header_id = ?", [$primaryValue]);
                        $amount_paid = (float)($inv_data['amount_paid'] ?? 0);
                        
                        $pay_count = $db->fetchOne("SELECT COUNT(*) as count FROM payments WHERE applied_to_txn_id = ?", [$primaryValue])['count'] ?? 0;
                        $link_count = $db->fetchOne("SELECT COUNT(*) as count FROM transaction_links WHERE child_id = ? AND link_type LIKE 'payment%'", [$primaryValue])['count'] ?? 0;
                        
                        if ($amount_paid > 0.01 || $pay_count > 0 || $link_count > 0) {
                            throw new Exception("Cannot delete invoice because a payment is linked to it. Please void the payment first.");
                        }
                    }

                    // Check if payment is linked to this vendor bill before deleting
                    if ($txn_type === 'vendor_bill') {
                        $bill_data = $db->fetchOne("SELECT amount_paid FROM vendor_bills WHERE header_id = ?", [$primaryValue]);
                        $amount_paid = (float)($bill_data['amount_paid'] ?? 0);
                        
                        $pay_count = $db->fetchOne("SELECT COUNT(*) as count FROM payments WHERE applied_to_txn_id = ?", [$primaryValue])['count'] ?? 0;
                        $link_count = $db->fetchOne("SELECT COUNT(*) as count FROM transaction_links WHERE child_id = ? AND link_type LIKE 'payment%'", [$primaryValue])['count'] ?? 0;
                        
                        if ($amount_paid > 0.01 || $pay_count > 0 || $link_count > 0) {
                            throw new Exception("Cannot delete vendor bill because a payment is linked to it. Please void the payment first.");
                        }
                    }

                    if ($txn_type === 'customer_payment' || $txn_type === 'vendor_payment') {
                        $party_type = ($txn_type === 'customer_payment') ? 'customer' : 'vendor';
                        $old_links = $db->fetchAll("SELECT child_id as applied_to_id, link_type FROM transaction_links WHERE parent_id = ?", [$primaryValue]);
                        foreach ($old_links as $link) {
                            $link_amount = (float)(explode(':', $link['link_type'])[1] ?? 0);
                            if ($link_amount <= 0) continue;
                            if ($party_type === 'customer') {
                                $pdo->prepare("UPDATE customer_invoices SET amount_paid = amount_paid - ?, balance_due = balance_due + ?, payment_status = CASE WHEN balance_due + ? >= total_amount THEN 'unpaid' ELSE 'partial' END WHERE header_id = ?")->execute([$link_amount, $link_amount, $link_amount, $link['applied_to_id']]);
                                $pdo->prepare("UPDATE transaction_headers SET status = CASE WHEN (SELECT balance_due FROM customer_invoices WHERE header_id = ?) >= (SELECT total_amount FROM customer_invoices WHERE header_id = ?) THEN 'open' ELSE 'partial' END WHERE id = ?")->execute([$link['applied_to_id'], $link['applied_to_id'], $link['applied_to_id']]);
                            } else {
                                $pdo->prepare("UPDATE vendor_bills SET amount_paid = amount_paid - ?, balance_due = balance_due + ?, payment_status = CASE WHEN balance_due + ? >= total_amount THEN 'unpaid' ELSE 'partial' END WHERE header_id = ?")->execute([$link_amount, $link_amount, $link_amount, $link['applied_to_id']]);
                                $pdo->prepare("UPDATE transaction_headers SET status = CASE WHEN (SELECT balance_due FROM vendor_bills WHERE header_id = ?) >= (SELECT total_amount FROM vendor_bills WHERE header_id = ?) THEN 'open' ELSE 'partial' END WHERE id = ?")->execute([$link['applied_to_id'], $link['applied_to_id'], $link['applied_to_id']]);
                            }
                        }
                        // Delete associated payments, links, and journal entries
                        $pdo->prepare("DELETE FROM payments WHERE header_id = ?")->execute([$primaryValue]);
                        $pdo->prepare("DELETE FROM transaction_links WHERE parent_id = ? OR child_id = ?")->execute([$primaryValue, $primaryValue]);
                        $pdo->prepare("DELETE FROM journal_entries WHERE header_id = ?")->execute([$primaryValue]);
                    }
                    // Delete cash denomination child rows when deleting a cash_denomination transaction
                    if ($txn_type === 'cash_denomination') {
                        $pdo->prepare("DELETE FROM cash_denominations WHERE header_id = ?")->execute([$primaryValue]);
                    }

                    // If deleting a POS daily summary invoice, also soft-delete and rename all corresponding pos_entry records on that day!
                    if ($txn_type === 'customer_invoice' && strpos($oldData['txn_number'], 'POS-SUM-') === 0) {
                        $date_str = substr($oldData['txn_number'], 8, 8); // YYYYMMDD
                        if (strlen($date_str) === 8) {
                            $txn_date = substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2);
                            
                            // Find all pos_entry records on this date that are not already deleted
                            $pos_entries = $db->fetchAll("SELECT id, invoice_no FROM pos_entry WHERE DATE(date_time) = ? AND is_deleted = 0", [$txn_date]);
                            foreach ($pos_entries as $pe) {
                                $new_pe_invoice = $pe['invoice_no'] . '-DEL-' . substr(md5(uniqid(rand(), true)), 0, 8);
                                $pdo->prepare("UPDATE pos_entry SET is_deleted = 1, invoice_no = ? WHERE id = ?")->execute([$new_pe_invoice, $pe['id']]);
                            }
                        }
                    }

                    // Rename the txn_number so the original name is freed up for a new transaction
                    $old_txn_number = $oldData['txn_number'];
                    $new_txn_number = $old_txn_number . '-DEL-' . substr(md5(uniqid(rand(), true)), 0, 8);
                    
                    $pdo->prepare("UPDATE transaction_headers SET txn_number = ? WHERE id = ?")->execute([$new_txn_number, $primaryValue]);
                    $pdo->prepare("UPDATE customer_invoices SET invoice_number = ? WHERE header_id = ?")->execute([$new_txn_number, $primaryValue]);
                    $pdo->prepare("UPDATE vendor_bills SET vendor_invoice_number = ? WHERE header_id = ?")->execute([$new_txn_number, $primaryValue]);
                }

                // If deleting a standalone pos_entry record, rename its invoice_no to free up unique constraint
                if ($tableName === 'pos_entry') {
                    $old_invoice_no = $oldData['invoice_no'] ?? '';
                    if ($old_invoice_no) {
                        $new_invoice_no = $old_invoice_no . '-DEL-' . substr(md5(uniqid(rand(), true)), 0, 8);
                        $pdo->prepare("UPDATE pos_entry SET invoice_no = ? WHERE id = ?")->execute([$new_invoice_no, $primaryValue]);
                    }
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

        if ($trigger_sync) {
            trigger_background_sync();
        }

        $messages = [
            'save'   => 'Record has been saved successfully.',
            'update' => 'Record has been updated successfully.',
            'delete' => 'Record has been deleted successfully.',
        ];

        return [
            'status'  => 'success',
            'message' => $messages[$action] ?? 'Operation completed successfully.',
            'id'      => $insertId
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Helper to save child rows
 */
function saveChildRows($child, $parentId, $pdo) {
    $cTable = $child['table'];
    $fk     = $child['foreign_key'];
    $rows   = $child['rows'] ?? [];

    foreach ($rows as $row) {
        $row[$fk] = $parentId;
        if (!isset($row['id'])) $row['id'] = generate_uuid();

        $keys         = array_keys($row);
        $columns      = implode(', ', $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        $pdo->prepare("INSERT INTO $cTable ($columns) VALUES ($placeholders)")
            ->execute(array_values($row));
    }
}

/**
 * Audit Logger
 */
function logAudit($table, $action, $old, $new, $refId, $userId, $pdo) {
    if ($action === 'save') $action = 'create';
    $pdo->prepare(
        "INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$table, $action, $refId, json_encode($old), json_encode($new), $userId]);
}

/**
 * Triggers sp_sync_gl_accounts runner asynchronously in background
 */
function trigger_background_sync() {
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
