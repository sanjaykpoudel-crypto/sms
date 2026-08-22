<?php
/**
 * Expense Register & Categorization Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;

$loc_sql = rpt_location_sql('h');

$expenses = $db->fetchAll("
    SELECT a.id as account_id, a.account_name, je.je_date as entry_date, (jl.debit - jl.credit) as amount,
           h.txn_number, h.txn_type, COALESCE(je.memo, h.memo) as memo
    FROM accounts a
    JOIN journal_lines jl ON a.id = jl.account_id
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE a.account_type = 'expense' AND h.txn_date BETWEEN ? AND ? 
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    ORDER BY je.je_date DESC, a.account_name ASC
", [$date_from, $date_to]);

// Aggregate expenses by account
$exp_by_account = [];
$total_expense  = 0.0;

foreach ($expenses as $e) {
    $acc_id  = $e['account_id'];
    $net_amt = (float)$e['amount'];
    
    if (!isset($exp_by_account[$acc_id])) {
        $exp_by_account[$acc_id] = [
            'name'  => $e['account_name'],
            'total' => 0.0,
            'items' => []
        ];
    }
    $exp_by_account[$acc_id]['total'] += $net_amt;
    $exp_by_account[$acc_id]['items'][] = $e;
    $total_expense += $net_amt;
}
?>

<?php rpt_filter_bar('Expense Register & Account Breakdown', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], 'tbl-expense-register'); ?>

<div class="ns-portlet" style="max-width: 1050px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding:14px 18px; background:#fff5f5; border:1px solid #fed7d7; border-radius:8px;">
            <div style="font-size:13px; font-weight:700; color:#9b2c2c;">
                <i class="fas fa-file-invoice"></i> Total Operating Expenses for Period
            </div>
            <div style="font-size:20px; font-weight:800; color:#c53030;">
                <?= rpt_currency($total_expense) ?>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-expense-register">
            <thead>
                <tr>
                    <th>Account Name</th>
                    <th>Transactions Count</th>
                    <th style="text-align:right">Expense Share (%)</th>
                    <th style="text-align:right">Total Amount (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($exp_by_account)): ?>
                    <tr><td colspan="4" style="text-align:center; padding:30px; color:#94a3b8;">No expense transactions recorded in selected period.</td></tr>
                <?php else: ?>
                    <?php foreach ($exp_by_account as $acc_id => $acc): 
                        $pct = $total_expense > 0 ? (($acc['total'] / $total_expense) * 100) : 0;
                    ?>
                        <tr style="font-weight:600;">
                            <td style="color:#0f172a;">
                                <?= htmlspecialchars($acc['name']) ?>
                            </td>
                            <td><?= count($acc['items']) ?> entries</td>
                            <td style="text-align:right; color:#475569;"><?= number_format($pct, 1) ?>%</td>
                            <td style="text-align:right; font-weight:700; color:#dc2626;"><?= rpt_currency($acc['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:14px">
                    <td colspan="3" style="padding:12px 16px">TOTAL EXPENSES</td>
                    <td style="text-align:right; padding:12px 16px"><?= rpt_currency($total_expense) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>
