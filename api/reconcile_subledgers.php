<?php
/**
 * api/reconcile_subledgers.php
 * ─────────────────────────────────────────────────────────────
 * AUTOMATED CROSS-REPORT RECONCILIATION MODULE
 * ─────────────────────────────────────────────────────────────
 * Runs ALL reconciliation assertions using the central ReportingEngine.
 * Can be called via:
 *   - CLI: php reconcile_subledgers.php
 *   - API: GET /api/reconcile_subledgers.php
 *   - Internal: require + re_run_reconciliation($db, $from, $to)
 *
 * Checks:
 *  1. Trial Balance Debits == Credits
 *  2. Balance Sheet Assets == Liabilities + Equity
 *  3. AR Subledger == AR GL Control Account
 *  4. AP Subledger == AP GL Control Account
 *  5. Inventory Subledger == Inventory GL Control Account
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && PHP_SAPI !== 'cli') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';
require_once __DIR__ . '/ReportingEngine.php';

$db = db();

try {
    // Get fiscal year dates for trial balance period
    $fy = $db->fetchOne("
        SELECT start_date, end_date FROM fiscal_years
        WHERE status IN ('active', 'open', 'current')
        ORDER BY start_date DESC LIMIT 1
    ");
    $from_date = $fy['start_date'] ?? date('Y-01-01');
    $to_date   = date('Y-m-d');

    $result = re_run_reconciliation($db, $from_date, $to_date);

    // Reformat for display
    $output = [
        'success'       => true,
        'all_reconciled'=> $result['all_pass'],
        'timestamp'     => $result['timestamp'],
        'period'        => ['from' => $from_date, 'to' => $to_date],
        'reconciliation'=> [],
    ];

    foreach ($result['checks'] as $key => $check) {
        $row = ['status' => $check['status']];
        // TB
        if ($key === 'trial_balance') {
            $row['closing_dr']  = $check['closing_dr'];
            $row['closing_cr']  = $check['closing_cr'];
            $row['difference']  = $check['difference'];
        }
        // BS
        elseif ($key === 'balance_sheet') {
            $row['total_assets']      = $check['total_assets'];
            $row['total_liab_equity'] = $check['total_liab_equity'];
            $row['difference']        = $check['difference'];
        }
        // Sub/GL pairs
        else {
            $row['subledger']   = $check['subledger'];
            $row['gl']          = $check['gl'];
            $row['difference']  = $check['difference'];
        }
        $output['reconciliation'][$key] = $row;
    }

    echo json_encode($output, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
