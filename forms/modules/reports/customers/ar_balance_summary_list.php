<?php
/**
 * Customer Accounts Receivable (AR) Balance Summary Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_to   = $_GET['date_to'] ?? $today;

$cust_balances = $db->fetchAll("
    SELECT c.id, c.full_name, c.credit_limit, COALESCE(SUM(ci.balance_due), 0.00) as balance
    FROM customers c
    JOIN customer_invoices ci ON c.id = ci.customer_id
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE c.is_active = 1 AND c.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date <= ?
    GROUP BY c.id, c.full_name, c.credit_limit
    HAVING balance > 0.01
    ORDER BY c.full_name ASC
", [$date_to]);

$total_ar_balance = array_sum(array_column($cust_balances, 'balance'));
?>

<?php rpt_filter_bar('Customer AR Balance Summary', [
    ['name' => 'date_to', 'label' => 'As of Date', 'type' => 'date', 'default' => $date_to],
], 'tbl-ar-summary'); ?>

<div class="ns-portlet" style="max-width: 950px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding:14px 18px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px;">
            <div style="font-size:13px; font-weight:700; color:#0369a1;">
                <i class="fas fa-users"></i> Total Accounts Receivable Outstanding Balance
            </div>
            <div style="font-size:20px; font-weight:800; color:#003087;">
                <?= rpt_currency($total_ar_balance) ?>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-ar-summary">
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name / Company</th>
                    <th style="text-align:right">Credit Limit</th>
                    <th style="text-align:right">Outstanding Balance (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cust_balances)): ?>
                    <tr><td colspan="4" style="text-align:center; padding:30px; color:#94a3b8;">No customer balances outstanding as of selected date.</td></tr>
                <?php else: ?>
                    <?php foreach ($cust_balances as $cb): 
                        $name = $cb['full_name'];
                    ?>
                        <tr>
                            <td style="font-weight:700; color:#64748b;"><?= htmlspecialchars($cb['id']) ?></td>
                            <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($name) ?></td>
                            <td style="text-align:right; color:#64748b;"><?= (float)$cb['credit_limit'] > 0 ? rpt_currency((float)$cb['credit_limit']) : '—' ?></td>
                            <td style="text-align:right; font-weight:700; color:#003087;"><?= rpt_currency((float)$cb['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:14px">
                    <td colspan="3" style="padding:12px 16px">TOTAL ACCOUNTS RECEIVABLE</td>
                    <td style="text-align:right; padding:12px 16px"><?= rpt_currency($total_ar_balance) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>
