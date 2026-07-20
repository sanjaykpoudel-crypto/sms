<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? $today;

// VAT on Sales (Invoices + POS — no double-counting of POS summaries)
$sales = $db->fetchAll("
    SELECT ci.invoice_date AS txn_date, ci.invoice_number AS txn_number, c.full_name AS party, c.pan_number,
           ci.subtotal, ci.tax_amount, ci.total_amount, ci.payment_status, 'Invoice' as txn_type
    FROM customer_invoices ci
    JOIN customers c ON ci.customer_id = c.id
    JOIN transaction_headers th ON ci.header_id = th.id
    WHERE ci.invoice_date BETWEEN ? AND ?
      AND th.is_deleted = 0
      AND th.status NOT IN ('void', 'voided', 'draft')
      AND ci.tax_amount > 0
      AND ci.invoice_number NOT LIKE 'POS-%'

    UNION ALL

    SELECT DATE(pe.date_time) AS txn_date, pe.invoice_no AS txn_number, COALESCE(c.full_name, 'Walk-in Customer') AS party, c.pan_number,
           pe.gross_amount - pe.discount_amount as subtotal, pe.tax_amount, pe.net_amount as total_amount, 'paid' as payment_status, 'POS' as txn_type
    FROM pos_entry pe
    LEFT JOIN customers c ON pe.customer_id = c.id
    WHERE DATE(pe.date_time) BETWEEN ? AND ?
      AND pe.is_deleted = 0
      AND (pe.invoice_no NOT LIKE 'POS-SUM-%' OR pe.invoice_no IN (SELECT txn_number FROM transaction_headers WHERE txn_type = 'customer_invoice' AND is_deleted = 0))
      AND pe.tax_amount > 0

    ORDER BY txn_date DESC
", [$date_from, $date_to, $date_from, $date_to]);

$total_taxable = array_sum(array_column($sales, 'subtotal'));
$total_vat = array_sum(array_column($sales, 'tax_amount'));
$total_sales = array_sum(array_column($sales, 'total_amount'));
?>


<?php rpt_filter_bar('VAT Sales Register', [
  ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => date('Y-m-01')],
  ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $today],
], 'tbl-vat-sales'); ?>

<div class="rpt-summary">
  <div class="rpt-summary-card">
    <div class="val"><?= count($sales) ?></div>
    <div class="lbl">Total Invoices</div>
  </div>
  <div class="rpt-summary-card">
    <div class="val"><?= rpt_currency($total_taxable) ?></div>
    <div class="lbl">Taxable Amount</div>
  </div>
  <div class="rpt-summary-card">
    <div class="val" style="color:#9a6700"><?= rpt_currency($total_vat) ?></div>
    <div class="lbl">VAT Collected</div>
  </div>
  <div class="rpt-summary-card">
    <div class="val"><?= rpt_currency($total_sales) ?></div>
    <div class="lbl">Total with VAT</div>
  </div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-report-table" id="tbl-sales-register">
      <thead>
        <tr>
          <th>Date</th>
          <th>Invoice #</th>
          <th>Customer</th>
          <th>PAN</th>
          <th style="text-align:right">Taxable Amt</th>
          <th style="text-align:right">VAT</th>
          <th style="text-align:right">Total</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sales)): ?>
          <tr>
            <td>No data</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
          </tr>
        <?php else:
          foreach ($sales as $r):
            $sc = ['unpaid' => '#c00', 'partial' => '#9a6700', 'paid' => '#1a7f37'][$r['payment_status']] ?? '#888';
            ?>
            <tr>
              <td><?= rpt_date($r['txn_date']) ?></td>
              <td>
                <div style="font-weight:600"><?= htmlspecialchars($r['txn_number']) ?></div>
                <div style="font-size:10px;color:#888"><?= $r['txn_type'] ?></div>
              </td>
              <td><?= htmlspecialchars($r['party']) ?></td>
              <td style="font-size:11px"><?= htmlspecialchars($r['pan_number'] ?? '-') ?></td>
              <td style="text-align:right"><?= rpt_currency($r['subtotal']) ?></td>
              <td style="text-align:right;color:#9a6700;font-weight:600"><?= rpt_currency($r['tax_amount']) ?></td>
              <td style="text-align:right;font-weight:700"><?= rpt_currency($r['total_amount']) ?></td>
              <td><?= rpt_badge(strtoupper($r['payment_status']), $sc) ?></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:#f8f9fa">
          <td>TOTAL</td>
          <td></td>
          <td></td>
          <td></td>
          <td style="text-align:right"><?= rpt_currency($total_taxable) ?></td>
          <td style="text-align:right;color:#9a6700"><?= rpt_currency($total_vat) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_sales) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id) { 
    const t = document.getElementById(id); 
    let csv = []; 
    t.querySelectorAll('tr').forEach(r => { 
        let row = []; 
        r.querySelectorAll('th,td').forEach(c => row.push('"' + c.innerText.replace(/"/g, '""') + '"')); 
        csv.push(row.join(',')) 
    }); 
    const b = new Blob([csv.join('\n')], { type: 'text/csv' }); 
    const a = document.createElement('a'); 
    a.href = URL.createObjectURL(b); 
    a.download = 'vat_sales_register.csv'; 
    a.click() 
}

$(document).ready(function() {
    $('#tbl-vat-sales').DataTable({
        "pageLength": 25,
        "language": {
            "search": "Quick Search:",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries"
        }
    });
});
</script>