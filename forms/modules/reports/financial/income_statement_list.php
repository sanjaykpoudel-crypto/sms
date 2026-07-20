<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today      = date('Y-m-d');
$date_from  = $_GET['date_from'] ?? date('Y-m-01');
$date_to    = $_GET['date_to']   ?? $today;

// Fetch GL Revenue (Credit balances on income accounts)
$revenue_rows = $db->fetchAll("
    SELECT a.account_name, -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'income'
      AND h.txn_date BETWEEN ? AND ?
      AND h.txn_type != 'inventory_adjustment'
      AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
    GROUP BY a.id, a.account_name
    HAVING bal != 0
", [$date_from, $date_to]);

$total_revenue = array_sum(array_column($revenue_rows, 'bal'));

// Fetch GL COGS (Debit balances on expense accounts with subtype cogs)
$cogs = (float)($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND a.account_subtype = 'cogs'
      AND h.txn_type != 'inventory_adjustment'
      AND h.txn_date BETWEEN ? AND ?
      AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
", [$date_from, $date_to])['bal'] ?? 0);

$gross_profit = $total_revenue - $cogs;

// Fetch GL Operating Expenses (Debit balances on non-COGS expense accounts)
$expenses_rows = $db->fetchAll("
    SELECT a.account_name AS description, SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as amount
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND a.account_subtype != 'cogs'
      AND h.txn_type != 'inventory_adjustment'
      AND h.txn_date BETWEEN ? AND ?
      AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
    GROUP BY a.id, a.account_name
    HAVING amount != 0
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
    <?php if (empty($revenue_rows)): ?>
      <div class="is-row"><span style="color:#888">No revenue recorded.</span><span>Rs 0.00</span></div>
    <?php else: foreach ($revenue_rows as $r): ?>
      <div class="is-row"><span><?= htmlspecialchars($r['account_name']) ?></span><span><?= rpt_currency($r['bal']) ?></span></div>
    <?php endforeach; endif; ?>
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
