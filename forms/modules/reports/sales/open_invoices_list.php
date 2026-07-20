<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today      = date('Y-m-d');
$as_of_date = $_GET['as_of_date'] ?? $today;
$customer_id = $_GET['customer_id'] ?? '';
$status     = $_GET['status']     ?? ''; // '' or 'overdue'

// Fetch customers for filter dropdown
$customers_list = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_deleted = 0 ORDER BY full_name ASC");
$customer_options = ['' => 'All Customers'];
foreach ($customers_list as $c) {
    $customer_options[$c['id']] = $c['full_name'];
}

$sql = "
    SELECT 
        th.id as header_id,
        th.txn_date as invoice_date,
        th.txn_number as invoice_number,
        c.full_name as customer_name,
        ci.due_date,
        DATEDIFF(?, ci.due_date) as days_overdue,
        ci.total_amount,
        ci.amount_paid,
        ci.balance_due
    FROM customer_invoices ci
    JOIN transaction_headers th ON ci.header_id = th.id
    LEFT JOIN customers c ON ci.customer_id = c.id
    WHERE th.is_deleted = 0 
      AND th.txn_type = 'customer_invoice'
      AND th.status NOT IN ('void', 'voided', 'draft')
      AND ci.balance_due > 0.01
";
$params = [$as_of_date];

if ($customer_id !== '') {
    $sql .= " AND ci.customer_id = ?";
    $params[] = $customer_id;
}

if ($status === 'overdue') {
    $sql .= " AND ci.due_date < ?";
    $params[] = $as_of_date;
}

$sql .= " ORDER BY ci.due_date ASC, th.txn_number DESC";
$rows = $db->fetchAll($sql, $params);

$total_amount  = array_sum(array_column($rows, 'total_amount'));
$total_paid    = array_sum(array_column($rows, 'amount_paid'));
$total_balance = array_sum(array_column($rows, 'balance_due'));

$total_overdue = 0.0;
foreach ($rows as $r) {
    if ($r['days_overdue'] > 0) {
        $total_overdue += (float)$r['balance_due'];
    }
}
?>
<style>
.rpt-summary { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.rpt-summary-card { background: #fff; border: 1px solid #dde2e8; border-radius: 6px; padding: 14px 20px; flex: 1; min-width: 150px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.rpt-summary-card .val { font-size: 20px; font-weight: 800; color: var(--ns-primary); }
.rpt-summary-card .lbl { font-size: 11px; color: #888; margin-top: 4px; text-transform: uppercase; font-weight: 600; }
@media print { .ns-header, .ns-nav, .rpt-toolbar, form { display: none !important; } }
</style>

<?php rpt_filter_bar('Open Customer Invoices', [
    ['name'=>'as_of_date', 'label'=>'As of Date','type'=>'date','default'=>$today],
    ['name'=>'customer_id','label'=>'Customer',  'type'=>'select','default'=>'','options'=>$customer_options],
    ['name'=>'status',     'label'=>'Due Status','type'=>'select','default'=>'','options'=>[''=>'All Open Invoices','overdue'=>'Overdue Only']]
], 'tbl-open-invoices'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Open Invoices</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_amount) ?></div><div class="lbl">Total Invoice Amount</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_paid) ?></div><div class="lbl">Total Collected to Date</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#b7791f"><?= rpt_currency($total_balance) ?></div><div class="lbl">Outstanding Balance</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_overdue) ?></div><div class="lbl">Overdue Balance</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-open-invoices">
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Invoice Date</th>
          <th>Customer</th>
          <th>Due Date</th>
          <th style="text-align:center">Days Overdue</th>
          <th style="text-align:right">Total Amount</th>
          <th style="text-align:right">Collected to Date</th>
          <th style="text-align:right">Open Balance</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r):
        $overdue_class = $r['days_overdue'] > 0 ? 'color:#c00;font-weight:700' : 'color:#1a7f37';
      ?>
        <tr>
          <td style="font-weight:600"><a href="?page=transactions/view&id=<?= $r['header_id'] ?>"><?= htmlspecialchars($r['invoice_number']) ?></a></td>
          <td><?= rpt_date($r['invoice_date']) ?></td>
          <td><?= htmlspecialchars($r['customer_name'] ?? 'N/A') ?></td>
          <td><?= $r['due_date'] ? rpt_date($r['due_date']) : '-' ?></td>
          <td style="text-align:center;<?= $overdue_class ?>">
            <?= $r['days_overdue'] > 0 ? $r['days_overdue'] . ' days overdue' : ($r['days_overdue'] == 0 ? 'Due today' : abs($r['days_overdue']) . ' days left') ?>
          </td>
          <td style="text-align:right"><?= rpt_currency($r['total_amount']) ?></td>
          <td style="text-align:right;color:#1a7f37"><?= rpt_currency($r['amount_paid']) ?></td>
          <td style="text-align:right;color:<?= $r['balance_due']>0.01?'#c00':'#1a7f37' ?>;font-weight:600"><?= rpt_currency($r['balance_due']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:#f8f9fa">
          <td colspan="5">TOTALS</td>
          <td style="text-align:right"><?= rpt_currency($total_amount) ?></td>
          <td style="text-align:right;color:#1a7f37"><?= rpt_currency($total_paid) ?></td>
          <td style="text-align:right;color:#c00"><?= rpt_currency($total_balance) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='open_invoices.csv';a.click()}
</script>
