<?php
/**
 * Purchase Register Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;

$loc_sql = rpt_location_sql('th');

$purchases = $db->fetchAll("
    SELECT vb.id, th.txn_number as bill_number, vb.vendor_id, vb.subtotal, vb.tax_amount, vb.discount_amount, vb.total_amount,
           th.txn_date, v.company_name as vendor_name
    FROM vendor_bills vb
    JOIN transaction_headers th ON vb.header_id = th.id
    LEFT JOIN vendors v ON vb.vendor_id = v.id
    WHERE th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    ORDER BY th.txn_date DESC, vb.id DESC
", [$date_from, $date_to]);

$tot_subtotal = 0.0;
$tot_tax      = 0.0;
$tot_total    = 0.0;
foreach ($purchases as $p) {
    $tot_subtotal += (float)$p['subtotal'];
    $tot_tax      += (float)$p['tax_amount'];
    $tot_total    += (float)$p['total_amount'];
}
?>

<?php rpt_filter_bar('Purchase Register Report', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], 'tbl-purchase-register'); ?>

<div class="ns-portlet" style="max-width: 1050px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 15px;">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
            <div style="font-size:13px; font-weight:700; color:#334155;">
                <i class="fas fa-shopping-bag"></i> Purchase Register Summary
            </div>
            <div style="font-size:12px; font-weight:600;">
                Total Bills: <strong><?= count($purchases) ?></strong> | 
                Tax Amount: <strong style="color:#2563eb;"><?= rpt_currency($tot_tax) ?></strong> | 
                Total Purchases: <strong style="color:#003087;"><?= rpt_currency($tot_total) ?></strong>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-purchase-register">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Bill #</th>
                    <th>Vendor Name</th>
                    <th style="text-align:right">Subtotal</th>
                    <th style="text-align:right">Tax</th>
                    <th style="text-align:right">Total Amount (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">No purchase bills recorded in selected period.</td></tr>
                <?php else: ?>
                    <?php foreach ($purchases as $p): ?>
                        <tr>
                            <td><?= rpt_date($p['txn_date']) ?></td>
                            <td style="font-weight:600; color:#003087;"><?= htmlspecialchars($p['bill_number']) ?></td>
                            <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($p['vendor_name'] ?: '—') ?></td>
                            <td style="text-align:right;"><?= rpt_currency((float)$p['subtotal']) ?></td>
                            <td style="text-align:right; color:#2563eb;"><?= rpt_currency((float)$p['tax_amount']) ?></td>
                            <td style="text-align:right; font-weight:700; color:#003087;"><?= rpt_currency((float)$p['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:13px">
                    <td colspan="3" style="padding:10px 14px">TOTAL PURCHASES</td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_subtotal) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_tax) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_total) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>
