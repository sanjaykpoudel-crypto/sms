<?php
/**
 * Payment & Receipts Register Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;
$flow_type = $_GET['flow_type'] ?? '';

$loc_sql = rpt_location_sql('h');

$where_flow = "";
$params = [$date_from, $date_to];
if ($flow_type === 'inflow') {
    $where_flow = " AND jl.debit > 0 ";
} elseif ($flow_type === 'outflow') {
    $where_flow = " AND jl.credit > 0 ";
}

$payments = $db->fetchAll("
    SELECT jl.jl_id as entry_id, je.je_date as entry_date, jl.debit, jl.credit,
           h.txn_number, h.txn_type, COALESCE(je.memo, h.memo) as memo, a.id as account_id, a.account_name
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN accounts a ON jl.account_id = a.id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE a.account_type = 'asset'
      AND a.account_subtype IN ('Cash', 'Bank', 'cash', 'bank')
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$where_flow} {$loc_sql}
    ORDER BY je.je_date DESC, jl.jl_id DESC
", $params);

$tot_inflow  = 0.0;
$tot_outflow = 0.0;
foreach ($payments as $p) {
    $tot_inflow += (float)$p['debit'];
    $tot_outflow += (float)$p['credit'];
}
?>

<?php rpt_filter_bar('Receipts & Payments Register', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    ['name' => 'flow_type', 'label' => 'Flow', 'type' => 'select', 'options' => ['' => 'All Cash & Bank Flows', 'inflow' => 'Inflow (Customer Receipts)', 'outflow' => 'Outflow (Vendor Payments)'], 'default' => $flow_type],
    rpt_location_filter(),
], 'tbl-payment-register'); ?>

<div class="ns-portlet" style="max-width: 1050px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 15px;">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
            <div style="font-size:13px; font-weight:700; color:#334155;">
                <i class="fas fa-exchange-alt"></i> Payments Register Summary
            </div>
            <div style="font-size:12px; font-weight:600;">
                Total Collections (Inflow): <strong style="color:#059669;"><?= rpt_currency($tot_inflow) ?></strong> | 
                Total Payments (Outflow): <strong style="color:#dc2626;"><?= rpt_currency($tot_outflow) ?></strong> | 
                Net Cash Flow: <strong style="color:#003087;"><?= rpt_currency($tot_inflow - $tot_outflow) ?></strong>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-payment-register">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Txn #</th>
                    <th>Type</th>
                    <th>Cash / Bank Account</th>
                    <th>Memo / Description</th>
                    <th style="text-align:right">Receipt Inflow</th>
                    <th style="text-align:right">Payment Outflow</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">No payment transactions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= rpt_date($p['entry_date']) ?></td>
                            <td style="font-weight:600; color:#003087;"><?= htmlspecialchars($p['txn_number']) ?></td>
                            <td><span class="ns-badge" style="background:#f1f5f9; color:#334155; padding:2px 6px; border-radius:4px; font-size:10px; text-transform:uppercase; font-weight:700;"><?= htmlspecialchars($p['txn_type']) ?></span></td>
                            <td><?= htmlspecialchars($p['account_name']) ?></td>
                            <td><?= htmlspecialchars($p['memo'] ?: '—') ?></td>
                            <td style="text-align:right; color:<?= (float)$p['debit'] > 0 ? '#059669' : '#94a3b8' ?>; font-weight:600;">
                                <?= (float)$p['debit'] > 0 ? rpt_currency((float)$p['debit']) : '—' ?>
                            </td>
                            <td style="text-align:right; color:<?= (float)$p['credit'] > 0 ? '#dc2626' : '#94a3b8' ?>; font-weight:600;">
                                <?= (float)$p['credit'] > 0 ? rpt_currency((float)$p['credit']) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:13px">
                    <td colspan="5" style="padding:10px 14px">TOTAL CASH & BANK FLOWS</td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_inflow) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_outflow) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>
