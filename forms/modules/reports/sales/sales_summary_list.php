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
$channel   = $_GET['channel']   ?? 'all';

$loc_id = $_GET['location_id'] ?? '';
$loc_sql = rpt_location_sql('th');
if (!empty($loc_id) && $loc_id !== 'all') {
    $quoted_loc = $db->getConnection()->quote($loc_id);
    $loc_sql_pe = " AND (pe.location_id = {$quoted_loc} OR pe.location_id IS NULL OR pe.location_id = '') ";
} else {
    $loc_sql_pe = "";
}

// Invoices Sales
$inv_sales = 0.0;
if ($channel === 'all' || $channel === 'invoices') {
    $inv_sales = (float) ($db->fetchOne("
        SELECT SUM(total_amount) as total
        FROM customer_invoices ci
        JOIN transaction_headers th ON ci.header_id = th.id
        WHERE th.txn_date BETWEEN ? AND ? 
          AND th.is_deleted = 0 
          AND th.status NOT IN ('void', 'voided', 'draft')
          AND COALESCE(th.source, '') != 'pos_sync'
          AND ci.invoice_number NOT LIKE 'INV-POS-%'
          AND ci.invoice_number NOT LIKE 'POS-%' {$loc_sql}
    ", [$date_from, $date_to])['total'] ?? 0);
}

// POS Retail Sales
$pos_sales = 0.0;
if ($channel === 'all' || $channel === 'pos') {
    $pos_sales = (float) ($db->fetchOne("
        SELECT SUM(pe.net_amount) as total
        FROM pos_entry pe
        WHERE pe.is_deleted = 0 AND pe.status != 'voided' AND DATE(pe.date_time) BETWEEN ? AND ? {$loc_sql_pe}
    ", [$date_from, $date_to])['total'] ?? 0);
}

// Journal Entries Income / Direct Sales
$journal_sales = 0.0;
if ($channel === 'all' || $channel === 'journals') {
    $journal_sales = (float) ($db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) as total
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE th.txn_type IN ('Journal', 'journal_entry')
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
          AND th.txn_date BETWEEN ? AND ?
          AND a.account_type = 'income'
          AND a.is_deleted = 0 {$loc_sql}
    ", [$date_from, $date_to])['total'] ?? 0);
}

// Credit Memos / Returns
$sales_returns = 0.0;
if ($channel === 'all' || $channel === 'invoices') {
    $sales_returns += (float) ($db->fetchOne("
        SELECT SUM(total_amount) as total
        FROM credit_memos cm
        JOIN transaction_headers th ON cm.header_id = th.id
        WHERE th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    ", [$date_from, $date_to])['total'] ?? 0);
}
if ($channel === 'all' || $channel === 'pos') {
    try {
        $sales_returns += (float) ($db->fetchOne("
            SELECT SUM(total_return_amount) as total
            FROM pos_returns
            WHERE status != 'voided' AND DATE(return_date) BETWEEN ? AND ?
        ", [$date_from, $date_to])['total'] ?? 0);
    } catch (\Throwable $e) {
        // Table pos_returns might not exist or be empty
    }
}

$gross_sales = $inv_sales + $pos_sales + $journal_sales;
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
    [
        'name'    => 'channel',
        'label'   => 'Sales Channel',
        'type'    => 'select',
        'default' => $channel,
        'options' => [
            'all'      => 'All Channels & Adjustments',
            'pos'      => 'POS Retail Counter Only',
            'invoices' => 'Customer Invoices Only',
            'journals' => 'Direct Journal Entries Only',
        ]
    ],
    rpt_location_filter(),
], 'tbl-sales-summary'); ?>

<div class="ns-portlet" style="max-width: 950px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <div class="sales-kpi-grid">
            <?php if ($channel === 'all' || $channel === 'invoices'): ?>
            <div class="sales-kpi-card">
                <div class="lbl">Customer Invoices</div>
                <div class="val"><?= rpt_currency($inv_sales) ?></div>
            </div>
            <?php endif; ?>

            <?php if ($channel === 'all' || $channel === 'pos'): ?>
            <div class="sales-kpi-card" style="border-top-color: #3b82f6;">
                <div class="lbl">POS Retail Counter</div>
                <div class="val" style="color:#2563eb;"><?= rpt_currency($pos_sales) ?></div>
            </div>
            <?php endif; ?>

            <?php if ($channel === 'all' || $channel === 'journals'): ?>
            <div class="sales-kpi-card" style="border-top-color: #8b5cf6;">
                <div class="lbl">Journal Sales / Income</div>
                <div class="val" style="color:#7c3aed;"><?= rpt_currency($journal_sales) ?></div>
            </div>
            <?php endif; ?>

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
                <?php if ($channel === 'all' || $channel === 'invoices'): ?>
                <tr>
                    <td>Customer B2B & Credit Invoices (`customer_invoices`)</td>
                    <td style="text-align:right; font-weight:600;"><?= rpt_currency($inv_sales) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($channel === 'all' || $channel === 'pos'): ?>
                <tr>
                    <td>POS Retail Counter Sales (`pos_entries`)</td>
                    <td style="text-align:right; font-weight:600;"><?= rpt_currency($pos_sales) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($channel === 'all' || $channel === 'journals'): ?>
                <tr>
                    <td>Direct Sales & Income Journal Entries (`journal_entries`)</td>
                    <td style="text-align:right; font-weight:600;"><?= rpt_currency($journal_sales) ?></td>
                </tr>
                <?php endif; ?>
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

