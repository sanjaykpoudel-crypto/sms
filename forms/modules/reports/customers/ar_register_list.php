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

$where_cust = ($customer_id !== '') ? " AND ci.customer_id = '$customer_id'" : "";
$where_cust_j = ($customer_id !== '') ? " AND (j.party_id = '$customer_id' OR th.party_id = '$customer_id')" : "";

$sql = "
    SELECT 
        'customer_invoice' as doc_type,
        th.id as header_id,
        th.txn_date,
        th.txn_number,
        c.full_name as customer_name,
        ci.invoice_number,
        ci.invoice_date,
        ci.due_date,
        ci.sale_type,
        ci.subtotal,
        ci.discount_amount,
        ci.tax_amount,
        ci.total_amount,
        ci.amount_paid,
        ci.balance_due,
        ci.payment_status
    FROM customer_invoices ci
    JOIN transaction_headers th ON ci.header_id = th.id
    LEFT JOIN customers c ON ci.customer_id = c.id
    WHERE th.is_deleted = 0 
      AND th.status NOT IN ('void', 'voided', 'draft')
      AND th.txn_date BETWEEN ? AND ? {$where_cust}
    
    UNION ALL

    SELECT 
        'journal' as doc_type,
        th.id as header_id,
        th.txn_date,
        th.txn_number,
        c.full_name as customer_name,
        th.txn_number as invoice_number,
        th.txn_date as invoice_date,
        th.txn_date as due_date,
        'journal' as sale_type,
        0.00 as subtotal,
        0.00 as discount_amount,
        0.00 as tax_amount,
        SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as total_amount,
        COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0.00) as amount_paid,
        (SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0.00)) as balance_due,
        CASE WHEN (SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0.00)) <= 0.01 THEN 'paid' ELSE 'unpaid' END as payment_status
    FROM journal_entries j
    JOIN transaction_headers th ON j.header_id = th.id
    LEFT JOIN customers c ON COALESCE(j.party_id, th.party_id) = c.id
    LEFT JOIN transaction_links tl ON tl.child_id = th.id AND tl.link_type LIKE 'payment:%'
    WHERE (j.party_type = 'customer' OR j.party_type IS NULL)
      AND (j.party_id IS NOT NULL OR th.party_id IS NOT NULL)
      AND th.is_deleted = 0 
      AND th.status NOT IN ('void', 'voided', 'draft')
      AND th.txn_type IN ('Journal', 'journal_entry')
      AND th.txn_date BETWEEN ? AND ? {$where_cust_j}
    GROUP BY th.id, th.txn_date, th.txn_number, c.full_name
    ORDER BY txn_date DESC, txn_number DESC
";
$params = [$date_from, $date_to, $date_from, $date_to];
$rows = $db->fetchAll($sql, $params);

$total_subtotal = array_sum(array_column($rows, 'subtotal'));
$total_discount = array_sum(array_column($rows, 'discount_amount'));
$total_tax      = array_sum(array_column($rows, 'tax_amount'));
$total_amount   = array_sum(array_column($rows, 'total_amount'));
$total_paid     = array_sum(array_column($rows, 'amount_paid'));
$total_balance  = array_sum(array_column($rows, 'balance_due'));
?>
<style>
.rpt-summary { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.rpt-summary-card { background: #fff; border: 1px solid #dde2e8; border-radius: 6px; padding: 14px 20px; flex: 1; min-width: 150px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.rpt-summary-card .val { font-size: 20px; font-weight: 800; color: var(--ns-primary); }
.rpt-summary-card .lbl { font-size: 11px; color: #888; margin-top: 4px; text-transform: uppercase; font-weight: 600; }
@media print { .ns-header, .ns-nav, .rpt-toolbar, form { display: none !important; } }
</style>

<?php rpt_filter_bar('Accounts Receivable (AR) Register', [
    ['name'=>'date_from',  'label'=>'From',    'type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',    'label'=>'To',      'type'=>'date','default'=>$today],
    ['name'=>'customer_id','label'=>'Customer','type'=>'select','default'=>'','options'=>$customer_options]
], 'tbl-ar-reg'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Total Invoices</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_amount) ?></div><div class="lbl">Gross Sales</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_paid) ?></div><div class="lbl">Collected</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_balance) ?></div><div class="lbl">Pending Balance</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-ar-reg">
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Invoice Date</th>
          <th>Customer</th>
          <th>Type</th>
          <th>Due Date</th>
          <th style="text-align:right">Subtotal</th>
          <th style="text-align:right">Discount</th>
          <th style="text-align:right">VAT</th>
          <th style="text-align:right">Total</th>
          <th style="text-align:right">Paid</th>
          <th style="text-align:right">Balance</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r):
        $status_colors = ['unpaid'=>'#c00','partial'=>'#9a6700','paid'=>'#1a7f37'];
        $sc = $status_colors[strtolower($r['payment_status'])] ?? '#888';
      ?>
        <tr>
          <td style="font-weight:600"><a href="?page=transactions/view&id=<?= $r['header_id'] ?>"><?= htmlspecialchars($r['txn_number']) ?></a></td>
          <td><?= rpt_date($r['txn_date']) ?></td>
          <td><?= htmlspecialchars($r['customer_name'] ?? 'N/A') ?></td>
          <td><?= rpt_badge(ucfirst($r['sale_type']), $r['sale_type']==='cash' ? '#1a7f37' : '#003087') ?></td>
          <td><?= $r['due_date'] ? rpt_date($r['due_date']) : '-' ?></td>
          <td style="text-align:right"><?= rpt_currency($r['subtotal']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['discount_amount']) ?></td>
          <td style="text-align:right"><?= rpt_currency($r['tax_amount']) ?></td>
          <td style="text-align:right;font-weight:600"><?= rpt_currency($r['total_amount']) ?></td>
          <td style="text-align:right;color:#1a7f37"><?= rpt_currency($r['amount_paid']) ?></td>
          <td style="text-align:right;color:<?= $r['balance_due']>0.01?'#c00':'#1a7f37' ?>;font-weight:600"><?= rpt_currency($r['balance_due']) ?></td>
          <td><?= rpt_badge(strtoupper($r['payment_status'] ?: 'UNPAID'), $sc) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:#f8f9fa">
          <td colspan="5">TOTAL (<?= count($rows) ?> invoices)</td>
          <td style="text-align:right"><?= rpt_currency($total_subtotal) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_discount) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_tax) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_amount) ?></td>
          <td style="text-align:right;color:#1a7f37"><?= rpt_currency($total_paid) ?></td>
          <td style="text-align:right;color:#c00"><?= rpt_currency($total_balance) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='ar_register.csv';a.click()}
</script>
