<?php
/**
 * Sales Summary & Multi-Channel Analysis Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $fy['end_date'];

$loc_sql = rpt_location_sql('th');

// Invoices Sales
$inv_sales = (float) ($db->fetchOne("
    SELECT SUM(total_amount) as total
    FROM customer_invoices ci
    JOIN transaction_headers th ON ci.header_id = th.id
    WHERE th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

// POS Retail Sales
$pos_sales = (float) ($db->fetchOne("
    SELECT SUM(pe.net_amount) as total
    FROM pos_entry pe
    WHERE pe.is_deleted = 0 AND pe.status != 'voided' AND DATE(pe.date_time) BETWEEN ? AND ?
", [$date_from, $date_to])['total'] ?? 0);

// Credit Memos / Returns
$sales_returns = (float) ($db->fetchOne("
    SELECT SUM(total_amount) as total
    FROM credit_memos cm
    JOIN transaction_headers th ON cm.header_id = th.id
    WHERE th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

$gross_sales = $inv_sales + $pos_sales;
$net_sales   = $gross_sales - $sales_returns;
?>

<style>
    .sales-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px; }
    .sales-kpi-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-top: 3px solid #059669; }
    .sales-kpi-card .lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; }
    .sales-kpi-card .val { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 4px; }
</style>

<?php rpt_filter_bar('Sales Summary & Multi-Channel Analysis', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], 'tbl-sales-summary'); ?>

<div class="ns-portlet" style="max-width: 950px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <div class="sales-kpi-grid">
            <div class="sales-kpi-card">
                <div class="lbl">Customer Invoices</div>
                <div class="val"><?= rpt_currency($inv_sales) ?></div>
            </div>
            <div class="sales-kpi-card" style="border-top-color: #3b82f6;">
                <div class="lbl">POS Retail Counter</div>
                <div class="val" style="color:#2563eb;"><?= rpt_currency($pos_sales) ?></div>
            </div>
            <div class="sales-kpi-card" style="border-top-color: #ef4444;">
                <div class="lbl">Sales Returns</div>
                <div class="val" style="color:#dc2626;">-<?= rpt_currency($sales_returns) ?></div>
            </div>
            <div class="sales-kpi-card" style="border-top-color: #003087;">
                <div class="lbl">Net Sales Revenue</div>
                <div class="val" style="color:#003087;"><?= rpt_currency($net_sales) ?></div>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-sales-summary">
            <thead>
                <tr>
                    <th>Sales Channel / Transaction Type</th>
                    <th style="text-align:right">Gross Revenue (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Customer B2B & Credit Invoices (`customer_invoices`)</td>
                    <td style="text-align:right; font-weight:600;"><?= rpt_currency($inv_sales) ?></td>
                </tr>
                <tr>
                    <td>POS Retail Counter Sales (`pos_entries`)</td>
                    <td style="text-align:right; font-weight:600;"><?= rpt_currency($pos_sales) ?></td>
                </tr>
                <tr style="background:#f8fafc; font-weight:700;">
                    <td>TOTAL GROSS SALES REVENUE</td>
                    <td style="text-align:right; color:#059669; font-weight:800;"><?= rpt_currency($gross_sales) ?></td>
                </tr>
                <tr>
                    <td>Less: Credit Memos & Customer Returns</td>
                    <td style="text-align:right; color:#dc2626; font-weight:600;">-<?= rpt_currency($sales_returns) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:14px">
                    <td style="padding:12px 16px">NET SALES REVENUE</td>
                    <td style="text-align:right; padding:12px 16px"><?= rpt_currency($net_sales) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>
