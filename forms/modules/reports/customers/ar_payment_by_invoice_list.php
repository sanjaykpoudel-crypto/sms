<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$fy         = rpt_get_current_fiscal_year_dates();
$today      = date('Y-m-d');
$date_from  = $_GET['date_from']   ?? $fy['start_date'];
$date_to    = $_GET['date_to']     ?? $today;
$customer_id = $_GET['customer_id'] ?? '';

// Fetch customers for filter dropdown
$customers_list = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_deleted = 0 ORDER BY full_name ASC");
$customer_options = ['' => 'All Customers'];
foreach ($customers_list as $c) {
    $customer_options[$c['id']] = $c['full_name'];
}

$where_cust = ($customer_id !== '') ? " AND (ci.customer_id = '$customer_id' OR p.customer_id = '$customer_id')" : "";

$loc_sql = rpt_location_sql('hp');
$sql = "
    SELECT 
        hp.txn_date as payment_date,
        hp.txn_number as payment_number,
        hp.id as payment_id,
        COALESCE(c_pay.full_name, c_hp.full_name, c.full_name, c_j.full_name) as customer_name,
        hb.txn_number as invoice_number,
        hb.id as invoice_id,
        hb.txn_date as invoice_date,
        COALESCE(
            ci.total_amount,
            (
                SELECT j.amount 
                FROM journal_entries j 
                WHERE j.id = SUBSTRING_INDEX(SUBSTRING_INDEX(tl.link_type, ':', 2), ':', -1)
            ),
            (
                SELECT SUM(j.amount) 
                FROM journal_entries j 
                WHERE j.header_id = hb.id 
                  AND j.entry_type = 'debit' 
                  AND (j.party_id = COALESCE(p.customer_id, hp.party_id) OR j.account_id = 'acc-1100')
            ),
            hb.net_amount,
            0.00
        ) as invoice_amount,
        CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(14, 2)) as paid_amount,
        GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods,
        GROUP_CONCAT(DISTINCT a.account_name SEPARATOR ', ') as paid_to
    FROM transaction_links tl
    JOIN transaction_headers hp ON tl.parent_id = hp.id
    JOIN transaction_headers hb ON tl.child_id = hb.id
    LEFT JOIN customer_invoices ci ON hb.id = ci.header_id
    LEFT JOIN customers c ON ci.customer_id = c.id
    LEFT JOIN customers c_j ON hb.party_id = c_j.id
    LEFT JOIN payments p ON hp.id = p.header_id
    LEFT JOIN customers c_pay ON p.customer_id = c_pay.id
    LEFT JOIN customers c_hp ON hp.party_id = c_hp.id
    LEFT JOIN accounts a ON p.bank_account_id = a.id
    WHERE hp.txn_type = 'customer_payment'
      AND hp.is_deleted = 0
      AND hp.status NOT IN ('voided', 'draft')
      AND hb.txn_type IN ('customer_invoice', 'Journal', 'journal_entry')
      AND hb.is_deleted = 0
      AND hb.status NOT IN ('voided', 'draft')
      AND hp.txn_date BETWEEN ? AND ? {$where_cust} {$loc_sql}
    GROUP BY tl.id 
    ORDER BY hp.txn_date DESC, hp.txn_number DESC
";
$params = [$date_from, $date_to];
$rows = $db->fetchAll($sql, $params);

// Group rows by payment_id
$grouped_payments = [];
$total_receipts_sum = 0;
foreach ($rows as $r) {
    $pid = $r['payment_id'];
    if (!isset($grouped_payments[$pid])) {
        $pay_total = $db->fetchOne("SELECT amount FROM payments WHERE header_id = ?", [$pid])['amount'] ?? null;
        $grouped_payments[$pid] = [
            'payment_id'     => $r['payment_id'],
            'payment_date'   => $r['payment_date'],
            'payment_number' => $r['payment_number'],
            'customer_name'  => $r['customer_name'] ?? 'N/A',
            'payment_methods'=> $r['payment_methods'],
            'paid_to'        => $r['paid_to'],
            'total_payment'  => $pay_total !== null ? (float)$pay_total : 0,
            'total_applied'  => 0,
            'invoices'       => []
        ];
    }
    $grouped_payments[$pid]['invoices'][] = $r;
    $grouped_payments[$pid]['total_applied'] += (float)$r['paid_amount'];
    if ($pay_total === null || (float)$pay_total == 0) {
        $grouped_payments[$pid]['total_payment'] += (float)$r['paid_amount'];
    }
}
foreach ($grouped_payments as $p) {
    $total_receipts_sum += $p['total_payment'];
}

$distinct_payments = count($grouped_payments);
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
.payment-hdr-row { background: #f8fafc; border-top: 2px solid #e2e8f0; font-weight: 700; }
.payment-hdr-row td { padding: 10px 12px !important; }
.invoice-child-row td { padding: 8px 12px !important; }
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
    <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_receipts_sum) ?></div><div class="lbl">Total Payment Receipts Value</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_applied) ?></div><div class="lbl">Total Amount Applied</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-ar-pay-inv" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th>Payment # / Applied Invoices</th>
          <th>Date</th>
          <?php if ($customer_id === ''): ?>
            <th>Customer</th>
          <?php endif; ?>
          <th>Account</th>
          <th style="text-align:right">Invoice Amount</th>
          <th style="text-align:right">Applied Amount</th>
          <th style="text-align:right">Total Payment Amount</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!empty($grouped_payments)): foreach ($grouped_payments as $pid => $p): ?>
        <!-- Parent Payment Header Row -->
        <tr class="payment-hdr-row">
          <td>
            <a href="?page=transactions/view&id=<?= $p['payment_id'] ?>" style="font-weight:700; color:#0284c7; text-decoration:none;">
              <i class="fas fa-receipt" style="margin-right:4px; color:#64748b;"></i><?= htmlspecialchars($p['payment_number']) ?>
            </a>
          </td>
          <td><?= rpt_date($p['payment_date']) ?></td>
          <?php if ($customer_id === ''): ?>
            <td style="color:#1e293b;"><?= htmlspecialchars($p['customer_name']) ?></td>
          <?php endif; ?>
          <td style="font-weight:600; color:#334155;"><?= htmlspecialchars($p['paid_to'] ?? 'N/A') ?></td>
          <td style="text-align:right; color:#94a3b8;">—</td>
          <td style="text-align:right; font-weight:700; color:#1a7f37;"><?= rpt_currency($p['total_applied']) ?></td>
          <td style="text-align:right; font-weight:800; color:#003087; font-size:13px;"><?= rpt_currency($p['total_payment']) ?></td>
        </tr>

        <!-- Nested Child Invoice Rows -->
        <?php foreach ($p['invoices'] as $inv): ?>
        <tr class="invoice-child-row" style="background:#ffffff;">
          <td style="padding-left:26px !important;">
            <span style="color:#94a3b8; margin-right:6px; font-family:monospace;">└─</span>
            <a href="?page=transactions/view&id=<?= $inv['invoice_id'] ?>" style="font-weight:600; color:#334155; text-decoration:none;"><?= htmlspecialchars($inv['invoice_number']) ?></a>
          </td>
          <td style="color:#64748b; font-size:12px;"><?= rpt_date($inv['invoice_date']) ?></td>
          <?php if ($customer_id === ''): ?>
            <td></td>
          <?php endif; ?>
          <td></td>
          <td style="text-align:right; color:#475569;"><?= rpt_currency($inv['invoice_amount']) ?></td>
          <td style="text-align:right; font-weight:600; color:#1a7f37;"><?= rpt_currency($inv['paid_amount']) ?></td>
          <td style="text-align:right; color:#cbd5e1;">—</td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:800; background:#f1f5f9; border-top:2px solid #003087;">
          <td colspan="<?= ($customer_id === '') ? 4 : 3 ?>">TOTALS</td>
          <td style="text-align:right"><?= rpt_currency($total_invoice_amount) ?></td>
          <td style="text-align:right; color:#1a7f37;"><?= rpt_currency($total_applied) ?></td>
          <td style="text-align:right; color:#003087; font-size:14px;"><?= rpt_currency($total_receipts_sum) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='ar_payment_by_invoice.csv';a.click()}
</script>
