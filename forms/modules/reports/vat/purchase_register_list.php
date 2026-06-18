<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;

$bills = $db->fetchAll("
    SELECT vb.bill_date, vb.vendor_invoice_number AS bill_number, v.company_name AS vendor, v.pan_number,
           vb.subtotal, vb.tax_amount, vb.total_amount, vb.payment_status
    FROM vendor_bills vb
    JOIN vendors v ON vb.vendor_id = v.id
    JOIN transaction_headers th ON vb.header_id = th.id
    WHERE vb.bill_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status != 'void' AND vb.tax_amount > 0
    ORDER BY vb.bill_date DESC
", [$date_from, $date_to]);

$total_taxable = array_sum(array_column($bills, 'subtotal'));
$total_vat     = array_sum(array_column($bills, 'tax_amount'));
$total_amount  = array_sum(array_column($bills, 'total_amount'));
?>
<style>
.rpt-toolbar{background:#f4f5f7;border:1px solid #dde2e8;border-radius:6px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;flex-wrap:wrap;gap:12px}
.rpt-title{font-size:15px;font-weight:700;color:#333;flex:1}
.rpt-filter-form{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.rpt-filter-group{display:flex;align-items:center;gap:5px}
.rpt-filter-group label{font-size:12px;color:#555;white-space:nowrap}
.rpt-input{padding:5px 8px!important;font-size:12px!important;height:30px!important}
.rpt-summary{display:flex;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.rpt-summary-card{background:#fff;border:1px solid #dde2e8;border-radius:6px;padding:14px 20px;flex:1;min-width:150px;text-align:center}
.rpt-summary-card .val{font-size:20px;font-weight:800;color:#003087}
.rpt-summary-card .lbl{font-size:11px;color:#888;margin-top:4px}
</style>

<?php rpt_filter_bar('VAT Purchase Register', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'tbl-vat-pur'); ?>

<div class="rpt-summary">
  <div class="rpt-summary-card"><div class="val"><?= count($bills) ?></div><div class="lbl">Total Bills</div></div>
  <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_taxable) ?></div><div class="lbl">Taxable Amount</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#9a6700"><?= rpt_currency($total_vat) ?></div><div class="lbl">Input VAT</div></div>
  <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_amount) ?></div><div class="lbl">Total with VAT</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-report-table" id="tbl-purchase-register">
      <thead><tr>
        <th>Date</th><th>Bill #</th><th>Vendor</th><th>PAN</th>
        <th style="text-align:right">Taxable Amt</th>
        <th style="text-align:right">Input VAT</th>
        <th style="text-align:right">Total</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php if (empty($bills)): ?>
        <tr>
          <td>No data</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
      <?php else: foreach ($bills as $r):
        $sc = ['unpaid'=>'#c00','partial'=>'#9a6700','paid'=>'#1a7f37'][$r['payment_status']] ?? '#888';
      ?>
        <tr>
          <td><?= rpt_date($r['bill_date']) ?></td>
          <td style="font-weight:600"><?= htmlspecialchars($r['bill_number']) ?></td>
          <td><?= htmlspecialchars($r['vendor']) ?></td>
          <td style="font-size:11px"><?= htmlspecialchars($r['pan_number'] ?? '-') ?></td>
          <td style="text-align:right"><?= rpt_currency($r['subtotal']) ?></td>
          <td style="text-align:right;color:#9a6700;font-weight:600"><?= rpt_currency($r['tax_amount']) ?></td>
          <td style="text-align:right;font-weight:700"><?= rpt_currency($r['total_amount']) ?></td>
          <td><?= rpt_badge(strtoupper($r['payment_status']),$sc) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td>TOTAL</td>
        <td></td><td></td><td></td>
        <td style="text-align:right"><?= rpt_currency($total_taxable) ?></td>
        <td style="text-align:right;color:#9a6700"><?= rpt_currency($total_vat) ?></td>
        <td style="text-align:right"><?= rpt_currency($total_amount) ?></td>
        <td></td>
      </tr></tfoot>
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
        csv.push(row.join(','));
    });
    const b = new Blob([csv.join('\n')], {type: 'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(b);
    a.download = 'vat_purchase_register.csv';
    a.click();
}

$(document).ready(function() {
    $('#tbl-vat-pur').DataTable({
        "pageLength": 25,
        "language": {
            "search": "Quick Search:",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries"
        }
    });
});
</script>
