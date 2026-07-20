<?php
/**
 * Fiscal Year Closing Action Handler
 * Supports: validate, preview, close, reopen
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

$db = db();
$pdo = $db->getConnection();

$action = $_POST['action'] ?? '';
$fy_id = $_POST['id'] ?? '';
$reason = trim($_POST['reason'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (empty($action) || empty($fy_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Action and Fiscal Year ID are required.']);
    exit;
}

// Auto-seed default Income Summary and Dividends Payable accounts if they do not exist
try {
    $has_inc_summary = $db->fetchOne("SELECT id FROM accounts WHERE account_code = '3300' OR account_name = 'Income Summary'");
    if (!$has_inc_summary) {
        $db->insert('accounts', [
            'id' => 'acc-3300',
            'account_code' => '3300',
            'account_name' => 'Income Summary',
            'account_type' => 'equity',
            'account_subtype' => 'other',
            'normal_balance' => 'credit',
            'parent_account_id' => 'acc-3000',
            'currency' => 'NPR',
            'is_active' => 1
        ]);
    }
    
    $has_div_payable = $db->fetchOne("SELECT id FROM accounts WHERE account_code = '2400' OR account_name = 'Dividends Payable'");
    if (!$has_div_payable) {
        $db->insert('accounts', [
            'id' => 'acc-2400',
            'account_code' => '2400',
            'account_name' => 'Dividends Payable',
            'account_type' => 'liability',
            'account_subtype' => 'other',
            'normal_balance' => 'credit',
            'parent_account_id' => 'acc-2000',
            'currency' => 'NPR',
            'is_active' => 1
        ]);
    }
} catch (Exception $e) {
    // Fail silently, they might already exist or DB is locked
}

// Fetch accounting preferences
$retained_earnings_acct = get_accounting_preference('fy_retained_earnings_account') ?: 'acc-3200';
$income_summary_acct = get_accounting_preference('fy_income_summary_account') ?: 'acc-3300';
$dividend_payable_acct = get_accounting_preference('fy_dividend_payable_account') ?: 'acc-2400';
$opening_journal_type = get_accounting_preference('fy_opening_journal_type') ?: 'Journal';
$closing_prefix = get_accounting_preference('fy_closing_prefix') ?: 'JE-CLOSE-';
$reclose_behavior = get_accounting_preference('fy_reclose_behavior') ?: 'delete';
$auto_create_next = get_accounting_preference('fy_auto_create_next') !== null ? (int)get_accounting_preference('fy_auto_create_next') : 1;
$auto_lock_prev = get_accounting_preference('fy_auto_lock_prev') !== null ? (int)get_accounting_preference('fy_auto_lock_prev') : 1;

try {
    // Fetch fiscal year record
    $fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE id = ?", [$fy_id]);
    if (!$fy) {
        throw new Exception("Fiscal Year record not found.");
    }
    
    $start = $fy['start_date'];
    $end = $fy['end_date'];
    
    // Recalculate and sync all GL account mappings from the transaction headers
    try {
        $pdo->exec("CALL sp_sync_gl_accounts()");
    } catch (Exception $e) {
        // Ignore if stored procedure is not defined in the current DB context
    }
    
    switch ($action) {
        case 'validate':
            $results = run_validation($pdo, $db, $start, $end);
            echo json_encode(['status' => 'success', 'validations' => $results]);
            break;
            
        case 'preview':
            $preview = run_preview($pdo, $db, $start, $end, $retained_earnings_acct, $income_summary_acct, $dividend_payable_acct);
            echo json_encode(['status' => 'success', 'preview' => $preview]);
            break;
            
        case 'close':
            if (!has_permission('close_fiscal_year')) {
                throw new Exception("You do not have permission to close fiscal years.");
            }
            if ($fy['status'] === 'closed') {
                throw new Exception("This fiscal year is already closed.");
            }
            
            // 1. Run validations
            $validations = run_validation($pdo, $db, $start, $end);
            $has_errors = false;
            foreach ($validations as $v) {
                if ($v['status'] === 'error') {
                    $has_errors = true;
                    break;
                }
            }
            if ($has_errors) {
                throw new Exception("Cannot close Fiscal Year because validation errors exist. Please review the validation logs.");
            }
            
            $pdo->beginTransaction();
            
            // 2. Check if there is a previous closing journal and handle it
            if (!empty($fy['closing_journal_id'])) {
                if ($reclose_behavior === 'delete') {
                    $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$fy['closing_journal_id']]);
                    $db->execute("DELETE FROM transaction_headers WHERE id = ?", [$fy['closing_journal_id']]);
                } else {
                    // Reverse
                    reverse_journal_entry($pdo, $fy['closing_journal_id']);
                }
            }
            if (!empty($fy['opening_journal_id'])) {
                // Delete the next year opening journal generated previously
                $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$fy['opening_journal_id']]);
                $db->execute("DELETE FROM transaction_headers WHERE id = ?", [$fy['opening_journal_id']]);
            }
            
            // 3. Generate Closing Journal
            $preview = run_preview($pdo, $db, $start, $end, $retained_earnings_acct, $income_summary_acct, $dividend_payable_acct);
            $closing_lines = $preview['journal_lines'];
            
            $closing_journal_id = generate_uuid();
            $closing_number = $closing_prefix . $fy['name'];
            
            // Insert Closing Journal Header
            $db->execute("
                INSERT INTO transaction_headers 
                (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, created_by, source, is_readonly, is_locked, net_amount)
                VALUES (?, ?, 'Journal', ?, ?, ?, ?, 'posted', ?, ?, 'Fiscal Year Closing', 1, 1, ?)
            ", [
                $closing_journal_id,
                $closing_number,
                $end,
                date('Y', strtotime($end)),
                date('m', strtotime($end)),
                date('Y-m', strtotime($end)),
                "System-generated closing journal for " . $fy['name'] . ". Notes: " . $notes,
                $_SESSION['user_id'],
                $preview['net_profit']
            ]);
            
            // Insert Journal Lines
            $line_num = 1;
            foreach ($closing_lines as $line) {
                $db->execute("
                    INSERT INTO journal_entries
                    (id, header_id, account_id, entry_type, amount, memo, entry_date, fiscal_period, fiscal_year, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    generate_uuid(),
                    $closing_journal_id,
                    $line['account_id'],
                    $line['type'],
                    $line['amount'],
                    $line['memo'],
                    $end,
                    date('Y-m', strtotime($end)),
                    date('Y', strtotime($end)),
                    $_SESSION['user_id']
                ]);
            }
            
            // 4. Generate Opening Balances for the Next Year
            $next_start = date('Y-m-d', strtotime($end . ' +1 day'));
            $next_end = date('Y-m-d', strtotime($next_start . ' +1 year -1 day'));
            
            // Fetch next fiscal year or create one
            $next_fy = $db->fetchOne("SELECT id, name FROM fiscal_years WHERE ? BETWEEN start_date AND end_date", [$next_start]);
            $next_fy_id = null;
            if ($next_fy) {
                $next_fy_id = $next_fy['id'];
                $next_fy_name = $next_fy['name'];
            } else if ($auto_create_next) {
                $next_fy_id = generate_uuid();
                $next_year_num = date('Y', strtotime($next_start));
                $next_fy_name = "FY " . $next_year_num . "/" . substr($next_year_num + 1, 2);
                $db->insert('fiscal_years', [
                    'id' => $next_fy_id,
                    'name' => $next_fy_name,
                    'start_date' => $next_start,
                    'end_date' => $next_end,
                    'status' => 'open',
                    'notes' => 'Auto-created by closing process of ' . $fy['name']
                ]);
            }
            
            $opening_journal_id = null;
            if ($next_fy_id) {
                $opening_journal_id = generate_uuid();
                $opening_number = "OPEN-" . $next_fy_name;
                
                // Get all balance sheet accounts ending balances (including closing entries)
                $bs_balances = $db->fetchAll("
                    SELECT a.id as account_id, a.account_name, a.normal_balance,
                           SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
                    FROM accounts a
                    JOIN journal_entries j ON a.id = j.account_id
                    JOIN transaction_headers h ON j.header_id = h.id
                    WHERE h.txn_date <= ? AND a.account_type IN ('asset', 'liability', 'equity') AND h.is_deleted = 0 AND h.status != 'void'
                    GROUP BY a.id, a.account_name, a.normal_balance
                    HAVING bal != 0
                ", [$end]);
                
                // Insert Opening Journal Header
                $db->execute("
                    INSERT INTO transaction_headers 
                    (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, created_by, source, is_readonly, is_locked)
                    VALUES (?, ?, 'Journal', ?, ?, ?, ?, 'posted', ?, ?, 'Fiscal Year Opening', 1, 1)
                ", [
                    $opening_journal_id,
                    $opening_number,
                    $next_start,
                    date('Y', strtotime($next_start)),
                    date('m', strtotime($next_start)),
                    date('Y-m', strtotime($next_start)),
                    "System-generated opening balances for " . $next_fy_name,
                    $_SESSION['user_id']
                ]);
                
                // Insert Opening Lines
                foreach ($bs_balances as $b) {
                    $amt = (float)$b['bal'];
                    if ($amt == 0) continue;
                    
                    $entry_type = $amt > 0 ? 'debit' : 'credit';
                    $abs_amt = abs($amt);
                    
                    $db->execute("
                        INSERT INTO journal_entries
                        (id, header_id, account_id, entry_type, amount, memo, entry_date, fiscal_period, fiscal_year, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", [
                        generate_uuid(),
                        $opening_journal_id,
                        $b['account_id'],
                        $entry_type,
                        $abs_amt,
                        "Opening Balance: " . $b['account_name'],
                        $next_start,
                        date('Y-m', strtotime($next_start)),
                        date('Y', strtotime($next_start)),
                        $_SESSION['user_id']
                    ]);
                }
            }
            
            // 5. Update Status
            $db->update('fiscal_years', [
                'status' => 'closed',
                'closing_date' => date('Y-m-d'),
                'closed_by' => $_SESSION['user_id'],
                'closed_timestamp' => date('Y-m-d H:i:s'),
                'closing_journal_id' => $closing_journal_id,
                'opening_journal_id' => $opening_journal_id,
                'notes' => $notes
            ], "id = :id", ['id' => $fy_id]);
            
            // 6. Lock previous periods if configured
            if ($auto_lock_prev) {
                $db->execute("UPDATE transaction_headers SET is_locked = 1 WHERE txn_date <= ? AND is_deleted = 0", [$end]);
            }
            
            // 7. Audit Log
            $log_id = generate_uuid();
            // Version count
            $version_cnt = (int)$db->fetchOne("SELECT COUNT(*) as count FROM fiscal_year_audit_logs WHERE fiscal_year_id = ?", [$fy_id])['count'] + 1;
            
            $db->insert('fiscal_year_audit_logs', [
                'id' => $log_id,
                'fiscal_year_id' => $fy_id,
                'action_type' => 'close',
                'previous_status' => $fy['status'],
                'new_status' => 'closed',
                'user_id' => $_SESSION['user_id'],
                'reason' => $notes,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'machine_name' => gethostbyaddr($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?: 'Unknown',
                'closing_journal_id' => $closing_journal_id,
                'deleted_reversed_journal_id' => !empty($fy['closing_journal_id']) ? $fy['closing_journal_id'] : null,
                'version_number' => $version_cnt
            ]);
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Fiscal Year closed successfully. Closing journal ' . $closing_number . ' has been generated.']);
            break;
            
        case 'reopen':
            if (!has_permission('reopen_fiscal_year')) {
                throw new Exception("You do not have permission to reopen fiscal years.");
            }
            if ($fy['status'] !== 'closed') {
                throw new Exception("Only closed fiscal years can be reopened.");
            }
            if (empty($reason)) {
                throw new Exception("Reopening reason is required.");
            }
            
            $pdo->beginTransaction();
            
            // 1. Delete closing journal
            if (!empty($fy['closing_journal_id'])) {
                $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$fy['closing_journal_id']]);
                $db->execute("DELETE FROM transaction_headers WHERE id = ?", [$fy['closing_journal_id']]);
            }
            
            // 2. Delete opening journal of the next year
            if (!empty($fy['opening_journal_id'])) {
                $db->execute("DELETE FROM journal_entries WHERE header_id = ?", [$fy['opening_journal_id']]);
                $db->execute("DELETE FROM transaction_headers WHERE id = ?", [$fy['opening_journal_id']]);
            }
            
            // 3. Unlock transactions
            $db->execute("UPDATE transaction_headers SET is_locked = 0 WHERE txn_date BETWEEN ? AND ? AND is_deleted = 0", [$start, $end]);
            
            // 4. Update Fiscal Year status
            $db->update('fiscal_years', [
                'status' => 'reopened',
                'reopened_by' => $_SESSION['user_id'],
                'reopened_timestamp' => date('Y-m-d H:i:s'),
                'closing_journal_id' => null,
                'opening_journal_id' => null,
                'notes' => $reason
            ], "id = :id", ['id' => $fy_id]);
            
            // 5. Audit Log
            $log_id = generate_uuid();
            $version_cnt = (int)$db->fetchOne("SELECT COUNT(*) as count FROM fiscal_year_audit_logs WHERE fiscal_year_id = ?", [$fy_id])['count'] + 1;
            
            $db->insert('fiscal_year_audit_logs', [
                'id' => $log_id,
                'fiscal_year_id' => $fy_id,
                'action_type' => 'reopen',
                'previous_status' => 'closed',
                'new_status' => 'reopened',
                'user_id' => $_SESSION['user_id'],
                'reason' => $reason,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'machine_name' => gethostbyaddr($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?: 'Unknown',
                'closing_journal_id' => null,
                'deleted_reversed_journal_id' => $fy['closing_journal_id'],
                'version_number' => $version_cnt
            ]);
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Fiscal Year reopened successfully. System-generated closing and opening journals have been deleted.']);
            break;
            
        default:
            throw new Exception("Invalid action.");
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Validations helper
 */
function run_validation($pdo, $db, $start, $end) {
    $validations = [];
    
    // 1. Check draft journals / transaction headers
    $draft_vouchers = $db->fetchOne("
        SELECT COUNT(*) as count FROM transaction_headers 
        WHERE txn_date BETWEEN ? AND ? AND status IN ('draft', 'approved') AND is_deleted = 0
    ", [$start, $end])['count'] ?? 0;
    
    $validations[] = [
        'name' => 'No Draft or Unapproved Transactions',
        'status' => $draft_vouchers == 0 ? 'success' : 'error',
        'message' => $draft_vouchers == 0 
            ? 'All transactions in this period are posted or voided.' 
            : "There are {$draft_vouchers} draft or unapproved transactions/vouchers. All transactions must be approved and posted before closing.",
        'action_url' => $draft_vouchers == 0 ? null : '?page=transactions/journal'
    ];
    
    // 2. Check balanced Trial Balance
    $tb_totals = $db->fetchOne("
        SELECT 
            SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE 0 END) as total_debit,
            SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE 0 END) as total_credit
        FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
    ", [$start, $end]);
    
    $dr = (float)($tb_totals['total_debit'] ?? 0);
    $cr = (float)($tb_totals['total_credit'] ?? 0);
    $diff = abs($dr - $cr);
    
    $validations[] = [
        'name' => 'Trial Balance is Balanced',
        'status' => $diff < 0.05 ? 'success' : 'error',
        'message' => $diff < 0.05 
            ? "Trial Balance balances perfectly (Dr: NPR " . number_format($dr, 2) . ", Cr: NPR " . number_format($cr, 2) . ")."
            : "Trial Balance is UNBALANCED. Debits: NPR " . number_format($dr, 2) . ", Credits: NPR " . number_format($cr, 2) . ". Discrepancy: NPR " . number_format($diff, 2),
        'action_url' => $diff < 0.05 ? null : '?page=reports/financial/trial_balance'
    ];
    
    // 3. General Ledger balanced
    $unbalanced_txns = $db->fetchAll("
        SELECT h.txn_number, 
               SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as diff
        FROM transaction_headers h
        JOIN journal_entries j ON j.header_id = h.id
        WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
        GROUP BY h.id, h.txn_number
        HAVING ABS(diff) >= 0.05
        LIMIT 5
    ", [$start, $end]);
    
    $validations[] = [
        'name' => 'General Ledger is Balanced',
        'status' => empty($unbalanced_txns) ? 'success' : 'error',
        'message' => empty($unbalanced_txns)
            ? 'All transaction vouchers have balanced double-entry ledger lines.'
            : 'There are unbalanced transactions in the GL. First 5: ' . implode(', ', array_map(function($t) { return $t['txn_number'] . " (diff: " . number_format($t['diff'], 2) . ")"; }, $unbalanced_txns)),
        'action_url' => empty($unbalanced_txns) ? null : '?page=reports/financial/general_ledger'
    ];
    
    // 4. Accounts Receivable agrees with GL
    // GL Receivable accounts
    $gl_ar = (float)($db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_subtype = 'receivable' AND h.txn_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status != 'void'
    ", [$start, $end])['bal'] ?? 0);
    
    // Subledger customer invoices outstanding as of period end date ($end)
    $sub_ar_rows = $db->fetchAll("
        SELECT 
            ci.total_amount,
            COALESCE((
                SELECT SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(14,2)))
                FROM transaction_links tl
                JOIN transaction_headers hp ON tl.parent_id = hp.id
                WHERE tl.child_id = ci.header_id 
                  AND hp.txn_date <= ?
                  AND hp.is_deleted = 0 AND hp.status != 'void'
            ), 0.00) as paid_amount
        FROM customer_invoices ci
        JOIN transaction_headers h ON ci.header_id = h.id
        WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
    ", [$end, $start, $end]);
    
    $sub_ar = 0.0;
    foreach ($sub_ar_rows as $row) {
        $sub_ar += ((float)$row['total_amount'] - (float)$row['paid_amount']);
    }
    
    $ar_diff = abs($gl_ar - $sub_ar);
    
    $validations[] = [
        'name' => 'Accounts Receivable agrees with GL',
        'status' => $ar_diff < 0.05 ? 'success' : 'warning',
        'message' => $ar_diff < 0.05
            ? "AR Subledger (NPR " . number_format($sub_ar, 2) . ") matches GL Accounts Receivable (NPR " . number_format($gl_ar, 2) . ")."
            : "AR Discrepancy detected! GL Receivable: NPR " . number_format($gl_ar, 2) . ", Invoices Balance Due: NPR " . number_format($sub_ar, 2) . ". Difference: NPR " . number_format($ar_diff, 2),
        'action_url' => $ar_diff < 0.05 ? null : '?page=reports/customers/receivable_aging'
    ];
    
    // 5. Accounts Payable agrees with GL
    $gl_ap = -(float)($db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_subtype = 'payable' AND h.txn_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status != 'void'
    ", [$start, $end])['bal'] ?? 0);
    
    // Subledger vendor bills outstanding as of period end date ($end)
    $sub_ap_rows = $db->fetchAll("
        SELECT 
            vb.total_amount,
            COALESCE((
                SELECT SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(14,2)))
                FROM transaction_links tl
                JOIN transaction_headers hp ON tl.parent_id = hp.id
                WHERE tl.child_id = vb.header_id 
                  AND hp.txn_date <= ?
                  AND hp.is_deleted = 0 AND hp.status != 'void'
            ), 0.00) as paid_amount
        FROM vendor_bills vb
        JOIN transaction_headers h ON vb.header_id = h.id
        WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
    ", [$end, $start, $end]);
    
    $sub_ap = 0.0;
    foreach ($sub_ap_rows as $row) {
        $sub_ap += ((float)$row['total_amount'] - (float)$row['paid_amount']);
    }
    
    $ap_diff = abs($gl_ap - $sub_ap);
    
    $validations[] = [
        'name' => 'Accounts Payable agrees with GL',
        'status' => $ap_diff < 0.05 ? 'success' : 'warning',
        'message' => $ap_diff < 0.05
            ? "AP Subledger (NPR " . number_format($sub_ap, 2) . ") matches GL Accounts Payable (NPR " . number_format($gl_ap, 2) . ")."
            : "AP Discrepancy detected! GL Payable: NPR " . number_format($gl_ap, 2) . ", Bills Balance Due: NPR " . number_format($sub_ap, 2) . ". Difference: NPR " . number_format($ap_diff, 2),
        'action_url' => $ap_diff < 0.05 ? null : '?page=reports/vendors/payable_aging'
    ];
    
    // 6. Inventory Valuation Completed (GL Inventory vs Items stock valuation as of period end date)
    $gl_inv = (float)($db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_subtype = 'inventory' AND h.txn_date <= ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status != 'void'
    ", [$end])['bal'] ?? 0);
    
    $items_inv_rows = $db->fetchAll("
        SELECT 
            i.cost_price,
            COALESCE(SUM(CASE 
                WHEN h.txn_type = 'vendor_bill' THEN l.quantity 
                WHEN h.txn_type IN ('customer_invoice','POS') THEN -l.quantity 
                WHEN h.txn_type = 'inventory_adjustment' THEN l.quantity
                ELSE 0 
            END), 0) AS computed_stock
        FROM items i
        LEFT JOIN transaction_lines l ON l.item_id = i.id
        LEFT JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') AND h.txn_date <= ?
        WHERE i.is_deleted = 0
        GROUP BY i.id
    ", [$end]);
    
    $items_inv = 0.0;
    foreach ($items_inv_rows as $row) {
        $items_inv += (float)$row['computed_stock'] * (float)$row['cost_price'];
    }
    
    $inv_diff = abs($gl_inv - $items_inv);
    
    $validations[] = [
        'name' => 'Inventory Valuation Check',
        'status' => $inv_diff < 1000 ? 'success' : 'warning', // Warning since stock valuation might have slight timing differences
        'message' => $inv_diff < 1000
            ? "Inventory Subledger valuation (NPR " . number_format($items_inv, 2) . ") is in sync with GL Inventory Assets (NPR " . number_format($gl_inv, 2) . ")."
            : "GL Inventory asset balance (NPR " . number_format($gl_inv, 2) . ") differs from the stock status valuation (NPR " . number_format($items_inv, 2) . ") by NPR " . number_format($inv_diff, 2) . ". This is a warning.",
        'action_url' => $inv_diff < 1000 ? null : '?page=reports/inventory/stock_summary'
    ];
    
    // 7. Fixed Asset Depreciation Check
    $dep_entries = $db->fetchOne("
        SELECT COUNT(*) as count FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE h.txn_date BETWEEN ? AND ? AND (j.memo LIKE '%depreciation%' OR j.memo LIKE '%depr%') AND h.is_deleted = 0 AND h.status != 'void'
    ", [$start, $end])['count'] ?? 0;
    
    $validations[] = [
        'name' => 'Fixed Asset Depreciation Posted',
        'status' => $dep_entries > 0 ? 'success' : 'warning',
        'message' => $dep_entries > 0
            ? "Depreciation transactions detected (Count: {$dep_entries})."
            : "Warning: No depreciation journal entries detected in this period. Please verify if depreciation needs to be posted.",
        'action_url' => $dep_entries > 0 ? null : '?page=transactions/journal/manage'
    ];
    
    // 8. Bank Reconciliation Check
    $validations[] = [
        'name' => 'Bank Reconciliation Check (Optional)',
        'status' => 'success',
        'message' => 'Please verify that all bank statement reconciliations are completed and in sync with bank ledgers.'
    ];
    
    // 9. No transaction outside fiscal year dates
    $outside_txns = $db->fetchOne("
        SELECT COUNT(*) as count FROM transaction_headers 
        WHERE fiscal_year = ? AND (txn_date < ? OR txn_date > ?) AND is_deleted = 0
    ", [date('Y', strtotime($start)), $start, $end])['count'] ?? 0;
    
    $validations[] = [
        'name' => 'No Transactions Outside Period Range',
        'status' => $outside_txns == 0 ? 'success' : 'error',
        'message' => $outside_txns == 0
            ? 'All transactions mapped to this fiscal year fall within its start and end dates.'
            : "There are {$outside_txns} transactions mapped to this fiscal year number but with dates outside the start/end ranges. Please fix their dates or fiscal periods.",
        'action_url' => $outside_txns == 0 ? null : '?page=reports/financial/general_ledger'
    ];
    
    // 10. Negative Inconsistencies Check (Negative Cash on Hand)
    $cash_bal = (float)($db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_code = '1010' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
    ", [$start, $end])['bal'] ?? 0);
    
    $validations[] = [
        'name' => 'No Negative Cash Balances',
        'status' => $cash_bal >= -0.01 ? 'success' : 'error',
        'message' => $cash_bal >= -0.01
            ? "Cash on Hand balance is positive (NPR " . number_format($cash_bal, 2) . ")."
            : "Negative cash balance detected! Cash on hand balance is NPR " . number_format($cash_bal, 2) . ".",
        'action_url' => $cash_bal >= -0.01 ? null : '?page=reports/financial/cash_book'
    ];
    
    return $validations;
}

/**
 * Preview Helper
 */
function run_preview($pdo, $db, $start, $end, $retained_earnings_acct, $income_summary_acct, $dividend_payable_acct) {
    // 1. Fetch Revenue Accounts Balances (Credit is positive for Revenue)
    $revenues = $db->fetchAll("
        SELECT a.id, a.account_code, a.account_name,
               -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as balance
        FROM accounts a
        JOIN journal_entries j ON a.id = j.account_id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_type = 'income' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
          AND h.source IS NULL -- Exclude any previous closing entries
        GROUP BY a.id, a.account_code, a.account_name
        HAVING balance != 0
    ", [$start, $end]);
    
    // 2. Fetch Expense Accounts Balances (Debit is positive for Expenses)
    $expenses = $db->fetchAll("
        SELECT a.id, a.account_code, a.account_name, a.account_subtype,
               SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as balance
        FROM accounts a
        JOIN journal_entries j ON a.id = j.account_id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_type = 'expense' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
          AND h.source IS NULL
        GROUP BY a.id, a.account_code, a.account_name, a.account_subtype
        HAVING balance != 0
    ", [$start, $end]);
    
    $total_revenue = 0.0;
    foreach ($revenues as $r) $total_revenue += (float)$r['balance'];
    
    $total_cogs = 0.0;
    $total_operating_expenses = 0.0;
    
    foreach ($expenses as $e) {
        $bal = (float)$e['balance'];
        if ($e['account_subtype'] === 'cogs') {
            $total_cogs += $bal;
        } else {
            $total_operating_expenses += $bal;
        }
    }
    
    $gross_profit = $total_revenue - $total_cogs;
    $net_profit = $gross_profit - $total_operating_expenses;
    
    // Generate simulated Journal Lines
    $lines = [];
    
    // Close Revenue: Debit Revenue, Credit Income Summary
    foreach ($revenues as $r) {
        $lines[] = [
            'account_id' => $r['id'],
            'account_code' => $r['account_code'],
            'account_name' => $r['account_name'],
            'type' => 'debit',
            'amount' => (float)$r['balance'],
            'memo' => "Close Revenue: " . $r['account_name']
        ];
    }
    if ($total_revenue != 0) {
        $lines[] = [
            'account_id' => $income_summary_acct,
            'account_code' => '3300',
            'account_name' => 'Income Summary',
            'type' => 'credit',
            'amount' => $total_revenue,
            'memo' => "Close Revenue Summary"
        ];
    }
    
    // Close Expenses: Credit Expenses, Debit Income Summary
    if (($total_cogs + $total_operating_expenses) != 0) {
        $lines[] = [
            'account_id' => $income_summary_acct,
            'account_code' => '3300',
            'account_name' => 'Income Summary',
            'type' => 'debit',
            'amount' => $total_cogs + $total_operating_expenses,
            'memo' => "Close Expense Summary"
        ];
    }
    foreach ($expenses as $e) {
        $lines[] = [
            'account_id' => $e['id'],
            'account_code' => $e['account_code'],
            'account_name' => $e['account_name'],
            'type' => 'credit',
            'amount' => (float)$e['balance'],
            'memo' => "Close Expense: " . $e['account_name']
        ];
    }
    
    // Net profit transfer:
    // Profit: Debit Income Summary, Credit Retained Earnings
    // Loss: Debit Retained Earnings, Credit Income Summary
    if ($net_profit > 0) {
        $lines[] = [
            'account_id' => $income_summary_acct,
            'account_code' => '3300',
            'account_name' => 'Income Summary',
            'type' => 'debit',
            'amount' => $net_profit,
            'memo' => "Transfer Net Profit to Retained Earnings"
        ];
        $lines[] = [
            'account_id' => $retained_earnings_acct,
            'account_code' => '3200',
            'account_name' => 'Retained Earnings',
            'type' => 'credit',
            'amount' => $net_profit,
            'memo' => "Post Net Profit to Retained Earnings"
        ];
    } else if ($net_profit < 0) {
        $abs_loss = abs($net_profit);
        $lines[] = [
            'account_id' => $retained_earnings_acct,
            'account_code' => '3200',
            'account_name' => 'Retained Earnings',
            'type' => 'debit',
            'amount' => $abs_loss,
            'memo' => "Post Net Loss to Retained Earnings"
        ];
        $lines[] = [
            'account_id' => $income_summary_acct,
            'account_code' => '3300',
            'account_name' => 'Income Summary',
            'type' => 'credit',
            'amount' => $abs_loss,
            'memo' => "Transfer Net Loss from Income Summary"
        ];
    }
    
    // Check if Dividends account is configured and has a balance
    // In this ERP, let's check if there is an account of subtype other or code 3400 for Dividends
    $dividend_bal = 0.0;
    $div_acc = $db->fetchOne("SELECT id, account_code, account_name FROM accounts WHERE account_code = '3400' OR account_name LIKE '%dividend%'");
    if ($div_acc) {
        $dividend_bal = (float)($db->fetchOne("
            SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            WHERE j.account_id = ? AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
              AND h.source IS NULL
        ", [$div_acc['id'], $start, $end])['bal'] ?? 0);
        
        if ($dividend_bal > 0) {
            // Close Dividends: Debit Retained Earnings, Credit Dividends Payable
            $lines[] = [
                'account_id' => $retained_earnings_acct,
                'account_code' => '3200',
                'account_name' => 'Retained Earnings',
                'type' => 'debit',
                'amount' => $dividend_bal,
                'memo' => "Close Dividends to Retained Earnings"
            ];
            $lines[] = [
                'account_id' => $dividend_payable_acct,
                'account_code' => '2400',
                'account_name' => 'Dividends Payable',
                'type' => 'credit',
                'amount' => $dividend_bal,
                'memo' => "Close Dividends to Dividend Payable"
            ];
        }
    }
    
    // Calculate Post-Closing Balance Sheet (carrying forward Assets, Liabilities, and Equity)
    // Dynamic Retained Earnings in post-closing includes the net profit and closed dividends
    $carried_forward = [];
    $bs_accounts = $db->fetchAll("
        SELECT a.account_code, a.account_name, a.account_type, a.normal_balance,
               SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM accounts a
        JOIN journal_entries j ON a.id = j.account_id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE h.txn_date <= ? AND a.account_type IN ('asset', 'liability', 'equity') AND h.is_deleted = 0 AND h.status != 'void'
          AND h.source IS NULL
        GROUP BY a.id, a.account_code, a.account_name, a.normal_balance, a.account_type
        HAVING bal != 0
    ", [$end]);
    
    foreach ($bs_accounts as $b) {
        $bal = (float)$b['bal'];
        
        // Adjust Retained Earnings (acc-3200) manually in preview
        if ($b['account_code'] === '3200') {
            $bal += ($b['normal_balance'] === 'credit') ? ($net_profit - $dividend_bal) : (-$net_profit + $dividend_bal);
        }
        
        // Adjust Dividend Payable (acc-2400) if closed
        if ($b['account_code'] === '2400') {
            $bal += ($b['normal_balance'] === 'credit') ? $dividend_bal : -$dividend_bal;
        }
        
        $carried_forward[] = [
            'code' => $b['account_code'],
            'name' => $b['account_name'],
            'type' => $b['account_type'],
            'balance' => abs($bal),
            'normal' => $bal >= 0 ? 'debit' : 'credit'
        ];
    }
    
    return [
        'total_revenue' => $total_revenue,
        'total_cogs' => $total_cogs,
        'gross_profit' => $gross_profit,
        'operating_expenses' => $total_operating_expenses,
        'net_profit' => $net_profit,
        'dividend_payable_adjustment' => $dividend_bal,
        'retained_earnings_adjustment' => $net_profit - $dividend_bal,
        'journal_lines' => $lines,
        'post_closing_balances' => $carried_forward
    ];
}

/**
 * Reversal Helper for Reopening
 */
function reverse_journal_entry($pdo, $header_id) {
    $db = db();
    $header = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$header_id]);
    if (!$header) return;
    
    $lines = $db->fetchAll("SELECT * FROM journal_entries WHERE header_id = ?", [$header_id]);
    if (empty($lines)) return;
    
    $rev_header_id = generate_uuid();
    $rev_number = "REV-" . $header['txn_number'];
    
    // Insert Reversal Header
    $db->execute("
        INSERT INTO transaction_headers 
        (id, txn_number, txn_type, txn_date, fiscal_year, fiscal_month, fiscal_period, status, memo, created_by, source, is_readonly, is_locked, net_amount)
        VALUES (?, ?, 'Journal', ?, ?, ?, ?, 'posted', ?, ?, ?, 1, 1, ?)
    ", [
        $rev_header_id,
        $rev_number,
        date('Y-m-d'),
        $header['fiscal_year'],
        $header['fiscal_month'],
        $header['fiscal_period'],
        "System-generated reversal for Closing Journal " . $header['txn_number'],
        $_SESSION['user_id'],
        'Fiscal Year Closing Reversal',
        $header['net_amount']
    ]);
    
    // Insert Reversed Lines (swap debit/credit)
    foreach ($lines as $line) {
        $rev_type = $line['entry_type'] === 'debit' ? 'credit' : 'debit';
        $db->execute("
            INSERT INTO journal_entries
            (id, header_id, account_id, entry_type, amount, memo, entry_date, fiscal_period, fiscal_year, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            generate_uuid(),
            $rev_header_id,
            $line['account_id'],
            $rev_type,
            $line['amount'],
            "Reversal: " . $line['memo'],
            date('Y-m-d'),
            $line['fiscal_period'],
            $line['fiscal_year'],
            $_SESSION['user_id']
        ]);
    }
}
