<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today       = date('Y-m-d');
$date_from   = $_GET['date_from']   ?? date('Y-m-01');
$date_to     = $_GET['date_to']     ?? $today;
$customer_id = $_GET['customer_id'] ?? '';

// Fetch customers for filter dropdown
$customers_list = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_deleted = 0 ORDER BY full_name ASC");
$customer_options = ['' => 'All Customers'];
foreach ($customers_list as $c) {
    $customer_options[$c['id']] = $c['full_name'];
}

$sql = "
    SELECT 
        hp.txn_date as payment_date,
        hp.txn_number as payment_number,
        hp.id as payment_id,
        c.full_name as customer_name,
        hb.txn_number as invoice_number,
        hb.id as invoice_id,
        hb.txn_date as invoice_date,
        ci.total_amount as invoice_amount,
        CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(14, 2)) as paid_amount,
        GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods,
        GROUP_CONCAT(DISTINCT a.account_name SEPARATOR ', ') as paid_to
    FROM transaction_links tl
    JOIN transaction_headers hp ON tl.parent_id = hp.id
    JOIN transaction_headers hb ON tl.child_id = hb.id
    JOIN customer_invoices ci ON hb.id = ci.header_id
    LEFT JOIN customers c ON ci.customer_id = c.id
    LEFT JOIN payments p ON hp.id = p.header_id AND p.customer_id = ci.customer_id
    LEFT JOIN accounts a ON p.bank_account_id = a.id
    WHERE hp.txn_type = 'customer_payment'
      AND hp.is_deleted = 0
      AND hp.status NOT IN ('voided', 'draft')
      AND hb.txn_type = 'customer_invoice'
      AND hb.is_deleted = 0
      AND hb.status NOT IN ('voided', 'draft')
      AND hp.txn_date BETWEEN ? AND ?
";
$params = [$date_from, $date_to];
if ($customer_id !== '') {
    $sql .= " AND ci.customer_id = ?";
    $params[] = $customer_id;
}
$sql .= " GROUP BY tl.id ORDER BY hp.txn_date DESC, hp.txn_number DESC";
$rows = $db->fetchAll($sql, $params);

// Calculate distinct payment count and amounts
$distinct_payments = count(array_unique(array_column($rows, 'payment_number')));
$total_applied = array_sum(array_column($rows, 'paid_amount'));

// Sum unique invoices to prevent double-counting multi-payment invoices
$unique_invoices = [];
foreach ($rows as $r) {
    if (!empty($r['invoice_id'])) {
        $unique_invoices[$r['invoice_id']] = (float)$r['invoice_amount'];
    }
}
$total_invoice_amount = array_sum($unique_invoices);
?>
<style>
.rpt-summary { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.rpt-summary-card { background: #fff; border: 1px solid #dde2e8; border-radius: 6px; padding: 14px 20px; flex: 1; min-width: 150px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.rpt-summary-card .val { font-size: 20px; font-weight: 800; color: var(--ns-primary); }
.rpt-summary-card .lbl { font-size: 11px; color: #888; margin-top: 4px; text-transform: uppercase; font-weight: 600; }
@media print { .ns-header, .ns-nav, .rpt-toolbar, form { display: none !important; } }
</style>

<?php rpt_filter_bar('Accounts Receivable (AR) Payment by Invoice', [
    ['name'=>'date_from',  'label'=>'From',    'type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',    'label'=>'To',      'type'=>'date','default'=>$today],
    ['name'=>'customer_id','label'=>'Customer','type'=>'select','default'=>'','options'=>$customer_options]
], 'tbl-ar-pay-inv'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= $distinct_payments ?></div><div class="lbl">Total Payments Received</div></div>
    <div class="rpt-summary-card"><div class="val"><?= count($unique_invoices) ?></div><div class="lbl">Invoices Paid / Applied</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_applied) ?></div><div class="lbl">Total Amount Applied</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-ar-pay-inv">
      <thead>
        <tr>
          <th>Payment Date</th>
          <th>Payment #</th>
          <th>Customer</th>
          <th>Invoice #</th>
          <th>Invoice Date</th>
          <th style="text-align:right">Invoice Amount</th>
          <th style="text-align:right">Applied Amount</th>
          <th>Payment Method</th>
          <th>Received Into Account</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r): ?>
        <tr>
          <td><?= rpt_date($r['payment_date']) ?></td>
          <td style="font-weight:600"><a href="?page=transactions/view&id=<?= $r['payment_id'] ?>"><?= htmlspecialchars($r['payment_number']) ?></a></td>
          <td><?= htmlspecialchars($r['customer_name'] ?? 'N/A') ?></td>
          <td style="font-weight:600"><a href="?page=transactions/view&id=<?= $r['invoice_id'] ?>"><?= htmlspecialchars($r['invoice_number']) ?></a></td>
          <td><?= rpt_date($r['invoice_date']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['invoice_amount']) ?></td>
          <td style="text-align:right;font-weight:600;color:#1a7f37"><?= rpt_currency($r['paid_amount']) ?></td>
          <td><?= rpt_badge(strtoupper(str_replace('_', ' ', $r['payment_methods'] ?? 'N/A')), '#3498db') ?></td>
          <td><small><?= htmlspecialchars($r['paid_to'] ?? 'N/A') ?></small></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:#f8f9fa">
          <td colspan="5">TOTALS</td>
          <td style="text-align:right"><?= rpt_currency($total_invoice_amount) ?></td>
          <td style="text-align:right;color:#1a7f37"><?= rpt_currency($total_applied) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='ar_payment_by_invoice.csv';a.click()}
</script>
