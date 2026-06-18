<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today      = date('Y-m-d');
$date_from  = $_GET['date_from'] ?? date('Y-m-01');
$date_to    = $_GET['date_to']   ?? $today;

// Revenue = Total amount paid on invoices within the period (Cash Basis)
// Note: We sum amount_paid for invoices dated in the range. 
// For a truer cash basis, we would sum payments in the range, but this matches the user's "not paid" exclusion request.
$revenue_data = $db->fetchOne("
    SELECT 
        COALESCE(SUM(ci.amount_paid), 0) AS paid_revenue,
        COALESCE(SUM(ci.total_amount), 0) AS total_revenue
    FROM customer_invoices ci 
    JOIN transaction_headers th ON ci.header_id = th.id 
    WHERE ci.invoice_date BETWEEN ? AND ? 
    AND th.is_deleted = 0 AND th.status != 'void'
", [$date_from, $date_to]);

$total_revenue = (float)$revenue_data['paid_revenue'];
$accrual_revenue = (float)$revenue_data['total_revenue'];
$payment_ratio = $accrual_revenue > 0 ? ($total_revenue / $accrual_revenue) : 1;

// COGS proportionate to the paid revenue to maintain correct margin
$total_cogs_accrual = (float)($db->fetchOne("SELECT COALESCE(SUM(l.cost_price * l.quantity),0) AS v FROM transaction_lines l JOIN transaction_headers h ON l.header_id=h.id WHERE h.txn_type IN ('customer_invoice','POS') AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'", [$date_from, $date_to])['v'] ?? 0);
$cogs = $total_cogs_accrual * $payment_ratio;
$gross_profit = $total_revenue - $cogs;

// Expenses from new expense module (pulling from expenses table)
$expenses_rows = $db->fetchAll("
    SELECT a.account_name AS description, SUM(e.amount) AS amount
    FROM expenses e
    JOIN transaction_headers h ON e.header_id = h.id
    JOIN accounts a ON e.expense_account_id = a.id
    WHERE h.txn_type = 'expense' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
    GROUP BY a.id
    ORDER BY a.account_name
", [$date_from, $date_to]);

$total_expenses = array_sum(array_column($expenses_rows, 'amount'));
$net_profit = $gross_profit - $total_expenses;
?>
<style>
.is-section{background:#003087;color:#fff;padding:8px 16px;font-weight:700;font-size:13px}
.is-row{display:flex;justify-content:space-between;padding:7px 16px;border-bottom:1px solid #f0f0f0;font-size:13px}
.is-row:hover{background:#f8f9fa}
.is-subtotal{display:flex;justify-content:space-between;padding:9px 16px;font-weight:700;background:#eef2ff;font-size:13px;border-top:2px solid #003087}
.is-total{display:flex;justify-content:space-between;padding:12px 16px;font-weight:900;font-size:15px;border-top:3px double #003087}
</style>

<?php rpt_filter_bar('Income Statement (P&L)', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], ''); ?>

<div class="ns-portlet" style="max-width:700px;margin:0 auto">
  <div class="ns-portlet-content" style="padding:0">
    <div style="text-align:center;padding:20px 16px;border-bottom:2px solid #003087">
      <div style="font-size:18px;font-weight:800;color:#003087">INCOME STATEMENT</div>
      <div style="font-size:13px;color:#666;margin-top:4px">Period: <?= $date_from ?> to <?= $date_to ?></div>
    </div>

    <div class="is-section">REVENUE</div>
    <div class="is-row"><span>Sales Revenue</span><span><?= rpt_currency($total_revenue) ?></span></div>
    <div class="is-subtotal"><span>Gross Revenue</span><span><?= rpt_currency($total_revenue) ?></span></div>

    <div class="is-section">COST OF GOODS SOLD</div>
    <div class="is-row"><span>Cost of Sales</span><span><?= rpt_currency($cogs) ?></span></div>
    <div class="is-subtotal"><span>Total COGS</span><span><?= rpt_currency($cogs) ?></span></div>

    <div class="is-subtotal" style="background:#d1ecf1;color:#0c5460;font-size:15px">
      <span>GROSS PROFIT</span>
      <span style="color:<?= $gross_profit >= 0 ? '#1a7f37' : '#c00' ?>"><?= rpt_currency($gross_profit) ?></span>
    </div>

    <div class="is-section">OPERATING EXPENSES</div>
    <?php if (empty($expenses_rows)): ?>
      <div class="is-row"><span style="color:#888">No expenses recorded.</span><span>Rs 0.00</span></div>
    <?php else: foreach ($expenses_rows as $e): ?>
      <div class="is-row"><span><?= htmlspecialchars($e['description']) ?></span><span><?= rpt_currency($e['amount']) ?></span></div>
    <?php endforeach; endif; ?>
    <div class="is-subtotal"><span>Total Expenses</span><span><?= rpt_currency($total_expenses) ?></span></div>

    <div class="is-total" style="background:<?= $net_profit>=0?'#d4edda':'#f8d7da' ?>">
      <span><?= $net_profit >= 0 ? 'NET PROFIT' : 'NET LOSS' ?></span>
      <span style="color:<?= $net_profit>=0?'#1a7f37':'#c00' ?>;font-size:18px"><?= rpt_currency(abs($net_profit)) ?></span>
    </div>
  </div>
</div>
