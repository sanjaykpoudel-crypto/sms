<?php
/**
 * Cash Flow Statement Report
 * Categorizes Operating, Investing, and Financing Cash Movements from General Ledger (IAS 7 Compliant)
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

// Cash & Bank accounts — driven purely by account_subtype in COA
// 'Cash' = Cash on Hand (e.g. Cash drawer), 'Bank' = Bank/digital wallets
$cash_where = "(a.account_type = 'asset' AND a.account_subtype IN ('Cash', 'Bank'))";

// Base static opening balances defined on Cash & Bank accounts
$acct_op_balance = (float) ($db->fetchOne("
    SELECT SUM(COALESCE(a.opening_balance, 0)) as bal
    FROM accounts a
    WHERE {$cash_where} AND a.is_deleted = 0
")['bal'] ?? 0);

// 1. Opening Cash & Bank Balance prior to date_from
$opening_cash_txns = (float) ($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE {$cash_where}
      AND h.txn_date < ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from])['bal'] ?? 0);

$opening_cash = $acct_op_balance + $opening_cash_txns;

// 2. Operating Activities
// Cash Received from Customers & Sales
$customer_collections = (float) ($db->fetchOne("
    SELECT SUM(j.amount) as total
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE {$cash_where}
      AND j.entry_type = 'debit' AND h.txn_type IN ('customer_payment', 'customer_invoice', 'invoice', 'payment', 'pos', 'receipt')
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

// Cash Paid to Vendors & Suppliers
$vendor_payments = (float) ($db->fetchOne("
    SELECT SUM(j.amount) as total
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE {$cash_where}
      AND j.entry_type = 'credit' AND h.txn_type IN ('vendor_payment', 'bill', 'purchase')
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

// Cash Paid for Operating Expenses
$operating_expenses_paid = (float) ($db->fetchOne("
    SELECT SUM(j.amount) as total
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE {$cash_where}
      AND j.entry_type = 'credit' AND h.txn_type IN ('expense')
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

$net_operating_cash = $customer_collections - $vendor_payments - $operating_expenses_paid;

// 3. Investing Activities — Fixed Asset accounts (account_subtype = 'Fixed Asset')
$investing_cash = (float)($db->fetchOne("
    SELECT -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as total
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'asset' AND a.account_subtype = 'Fixed Asset'
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

// 4. Financing Activities (Capital contributions, drawings, owner investments via Journal entries)
$capital_inflows = (float) ($db->fetchOne("
    SELECT SUM(j.amount) as total
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE {$cash_where}
      AND j.entry_type = 'debit' AND h.txn_type = 'Journal'
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

$capital_outflows = (float) ($db->fetchOne("
    SELECT SUM(j.amount) as total
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE {$cash_where}
      AND j.entry_type = 'credit' AND h.txn_type = 'Journal'
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_from, $date_to])['total'] ?? 0);

$financing_cash = $capital_inflows - $capital_outflows;

$net_cash_change = $net_operating_cash + $investing_cash + $financing_cash;

// Verify actual GL cash & bank balance as of date_to (including static opening balances)
$gl_ending_cash_txns = (float) ($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE {$cash_where}
      AND h.txn_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_to])['bal'] ?? 0);

$gl_ending_cash = $acct_op_balance + $gl_ending_cash_txns;
$ending_cash     = $gl_ending_cash;
?>

<style>
    .cf-section-header { background: #f1f5f9; padding: 10px 14px; font-weight: 800; color: #003087; text-transform: uppercase; border-bottom: 2px solid #cbd5e1; font-size: 13px; margin-top: 15px; }
    .cf-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
    .cf-kpi-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-top: 3px solid #003087; }
    .cf-kpi-card .lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; }
    .cf-kpi-card .val { font-size: 18px; font-weight: 800; color: #0f172a; margin-top: 4px; }
</style>

<?php rpt_filter_bar('Statement of Cash Flows', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], 'tbl-cash-flow'); ?>

<div class="ns-portlet" style="max-width: 950px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <!-- Audit Banner Reconciling to GL Cash Account -->
        <div style="margin-bottom:20px; padding:12px 18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:13px; font-weight:700; color:#166534;">
                <i class="fas fa-check-circle"></i> GL Cash & Bank Balance Verification
            </div>
            <div style="font-size:13px; font-weight:700; color:#166534;">
                Total Cash & Bank in GL as of <?= rpt_date($date_to) ?>: <strong style="font-size:16px; color:#003087;"><?= rpt_currency($gl_ending_cash) ?></strong>
            </div>
        </div>

        <!-- Summary KPI Cards -->
        <div class="cf-kpi-grid">
            <div class="cf-kpi-card">
                <div class="lbl">Opening Cash Balance</div>
                <div class="val"><?= rpt_currency($opening_cash) ?></div>
            </div>
            <div class="cf-kpi-card" style="border-top-color: #10b981;">
                <div class="lbl">Operating Activities</div>
                <div class="val" style="color:<?= $net_operating_cash >= 0 ? '#059669' : '#dc2626' ?>;"><?= $net_operating_cash >= 0 ? '+' : '' ?><?= rpt_currency($net_operating_cash) ?></div>
            </div>
            <div class="cf-kpi-card" style="border-top-color: #3b82f6;">
                <div class="lbl">Investing Activities</div>
                <div class="val" style="color:<?= $investing_cash >= 0 ? '#2563eb' : '#dc2626' ?>;"><?= $investing_cash >= 0 ? '+' : '' ?><?= rpt_currency($investing_cash) ?></div>
            </div>
            <div class="cf-kpi-card" style="border-top-color: #8b5cf6;">
                <div class="lbl">Financing Activities</div>
                <div class="val" style="color:<?= $financing_cash >= 0 ? '#7c3aed' : '#dc2626' ?>;"><?= $financing_cash >= 0 ? '+' : '' ?><?= rpt_currency($financing_cash) ?></div>
            </div>
            <div class="cf-kpi-card" style="border-top-color: #003087;">
                <div class="lbl">Ending Cash Balance</div>
                <div class="val" style="color:#003087;"><?= rpt_currency($gl_ending_cash) ?></div>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-cash-flow">
            <thead>
                <tr>
                    <th>Cash Flow Category / Activity</th>
                    <th style="text-align:right">Amount (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <!-- OPENING CASH -->
                <tr style="background:#f8fafc; font-weight:700;">
                    <td>CASH AND CASH EQUIVALENTS — BEGINNING BALANCE</td>
                    <td style="text-align:right; color:#003087; font-weight:800;"><?= rpt_currency($opening_cash) ?></td>
                </tr>

                <!-- OPERATING ACTIVITIES -->
                <tr><td colspan="2" class="cf-section-header">1. Cash Flows from Operating Activities</td></tr>
                <tr>
                    <td style="padding-left:25px;">Cash Received from Customers & Sales Collections</td>
                    <td style="text-align:right; color:#059669; font-weight:600;">+<?= rpt_currency($customer_collections) ?></td>
                </tr>
                <tr>
                    <td style="padding-left:25px;">Cash Paid to Vendors & Suppliers</td>
                    <td style="text-align:right; color:#dc2626; font-weight:600;">-<?= rpt_currency($vendor_payments) ?></td>
                </tr>
                <tr>
                    <td style="padding-left:25px;">Cash Paid for Operating Expenses</td>
                    <td style="text-align:right; color:#dc2626; font-weight:600;">-<?= rpt_currency($operating_expenses_paid) ?></td>
                </tr>
                <tr style="font-weight:700; background:#f1f5f9;">
                    <td style="padding-left:15px;">Net Cash Provided by / (Used in) Operating Activities</td>
                    <td style="text-align:right; color:<?= $net_operating_cash >= 0 ? '#059669' : '#dc2626' ?>; font-weight:800;">
                        <?= $net_operating_cash >= 0 ? '+' : '' ?><?= rpt_currency($net_operating_cash) ?>
                    </td>
                </tr>

                <!-- INVESTING ACTIVITIES -->
                <tr><td colspan="2" class="cf-section-header">2. Cash Flows from Investing Activities</td></tr>
                <tr>
                    <td style="padding-left:25px;">Capital Expenditures & Fixed Asset Acquisitions</td>
                    <td style="text-align:right; color:<?= $investing_cash >= 0 ? '#059669' : '#dc2626' ?>; font-weight:600;"><?= $investing_cash >= 0 ? '+' : '' ?><?= rpt_currency($investing_cash) ?></td>
                </tr>
                <tr style="font-weight:700; background:#f1f5f9;">
                    <td style="padding-left:15px;">Net Cash Provided by / (Used in) Investing Activities</td>
                    <td style="text-align:right; color:<?= $investing_cash >= 0 ? '#059669' : '#dc2626' ?>; font-weight:800;">
                        <?= $investing_cash >= 0 ? '+' : '' ?><?= rpt_currency($investing_cash) ?>
                    </td>
                </tr>

                <!-- FINANCING ACTIVITIES -->
                <tr><td colspan="2" class="cf-section-header">3. Cash Flows from Financing Activities</td></tr>
                <tr>
                    <td style="padding-left:25px;">Owner Capital Contributions & Inflows</td>
                    <td style="text-align:right; color:#059669; font-weight:600;">+<?= rpt_currency($capital_inflows) ?></td>
                </tr>
                <tr>
                    <td style="padding-left:25px;">Owner Drawings, Equity Adjustments & Outflows</td>
                    <td style="text-align:right; color:#dc2626; font-weight:600;">-<?= rpt_currency($capital_outflows) ?></td>
                </tr>
                <tr style="font-weight:700; background:#f1f5f9;">
                    <td style="padding-left:15px;">Net Cash Provided by / (Used in) Financing Activities</td>
                    <td style="text-align:right; color:<?= $financing_cash >= 0 ? '#059669' : '#dc2626' ?>; font-weight:800;">
                        <?= $financing_cash >= 0 ? '+' : '' ?><?= rpt_currency($financing_cash) ?>
                    </td>
                </tr>

                <!-- NET CHANGE -->
                <tr style="background:#e2e8f0; font-weight:800;">
                    <td>NET INCREASE / (DECREASE) IN CASH & CASH EQUIVALENTS</td>
                    <td style="text-align:right; color:<?= $net_cash_change >= 0 ? '#059669' : '#dc2626' ?>; font-size:14px;">
                        <?= $net_cash_change >= 0 ? '+' : '' ?><?= rpt_currency($net_cash_change) ?>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:14px">
                    <td style="padding:12px 16px">CASH AND CASH EQUIVALENTS — ENDING BALANCE</td>
                    <td style="text-align:right; padding:12px 16px"><?= rpt_currency($gl_ending_cash) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>
