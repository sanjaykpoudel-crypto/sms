<?php
/**
 * Retained Earnings Statement
 * Statement of Changes in Retained Earnings and Profit Allocations
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;

$fy_start_date = get_report_start_date($date_from);
$loc_sql       = rpt_location_sql('h');

// Opening Retained Earnings as of date_from
$opening_retained = (float) ($db->fetchOne("
    SELECT -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE (a.id = 'acc-3200' OR a.account_name LIKE '%retained%')
      AND h.txn_date >= ? AND h.txn_date < ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$fy_start_date, $date_from])['bal'] ?? 0);

// Net Profit / (Loss) for the period
$revenue = -(float) ($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'income' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND (h.source IS NULL OR h.source NOT IN ('Fiscal Year Closing', 'Fiscal Year Opening')) {$loc_sql}
", [$date_from, $date_to])['v'] ?? 0);

$expenses = (float) ($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND (h.source IS NULL OR h.source NOT IN ('Fiscal Year Closing', 'Fiscal Year Opening')) {$loc_sql}
", [$date_from, $date_to])['v'] ?? 0);

$net_profit = $revenue - $expenses;

// Dividends & Other Allocations during the period
$dividends = (float) ($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as total
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE (a.account_name LIKE '%dividend%' OR a.account_name LIKE '%drawing%')
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

$ending_retained = $opening_retained + $net_profit - $dividends;
?>

<?php rpt_filter_bar('Statement of Retained Earnings', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], 'tbl-retained-earnings'); ?>

<div class="ns-portlet" style="max-width: 900px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <div style="margin-bottom:20px; padding:12px 18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; font-weight:700; color:#334155; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-coins"></i> Period Ending Retained Earnings Balance:</span>
            <span style="font-size:18px; font-weight:800; color:#003087;"><?= rpt_currency($ending_retained) ?></span>
        </div>

        <table class="ns-report-table-static" id="tbl-retained-earnings">
            <thead>
                <tr>
                    <th>Component / Description</th>
                    <th style="text-align:right">Amount (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background:#f8fafc; font-weight:700;">
                    <td>RETAINED EARNINGS — OPENING BALANCE</td>
                    <td style="text-align:right; color:#003087; font-weight:800;"><?= rpt_currency($opening_retained) ?></td>
                </tr>
                <tr>
                    <td style="padding-left:25px;">Net Income / (Loss) for the Period Transferred</td>
                    <td style="text-align:right; color:<?= $net_profit >= 0 ? '#059669' : '#dc2626' ?>; font-weight:600;">
                        <?= $net_profit >= 0 ? '+' : '' ?><?= rpt_currency($net_profit) ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding-left:25px;">Less: Dividends & Owner Distributions Declared</td>
                    <td style="text-align:right; color:#dc2626; font-weight:600;">-<?= rpt_currency($dividends) ?></td>
                </tr>
                <tr style="background:#e2e8f0; font-weight:800;">
                    <td>NET CHANGE IN RETAINED EARNINGS</td>
                    <td style="text-align:right; color:<?= ($net_profit - $dividends) >= 0 ? '#059669' : '#dc2626' ?>; font-size:13px;">
                        <?= ($net_profit - $dividends) >= 0 ? '+' : '' ?><?= rpt_currency($net_profit - $dividends) ?>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:14px">
                    <td style="padding:12px 16px">RETAINED EARNINGS — CLOSING BALANCE</td>
                    <td style="text-align:right; padding:12px 16px"><?= rpt_currency($ending_retained) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>
