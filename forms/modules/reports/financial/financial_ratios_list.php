<?php
/**
 * Financial Ratios & Performance Indicators Report
 * Fully COA-driven — no hardcoded account IDs.
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';
require_once 'api/ReportingEngine.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;

$loc_sql = rpt_location_sql('h');

// ── Income Statement — via central engine (same as P&L report) ───────────────
$pnl = re_get_pnl($db, $date_from, $date_to, $_GET['location_id'] ?? null);
$revenue      = $pnl['total_revenue'];
$cogs         = $pnl['total_cogs'];
$expenses     = $pnl['total_expenses'];
$gross_profit = $pnl['gross_profit'];
$net_profit   = $pnl['net_profit'];

// ── Balance Sheet Balances — COA-driven via account_type / account_subtype ───

// Cash & Bank: asset accounts with subtype 'Cash' or 'Bank'
$cash_bank = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as v
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'asset'
      AND a.account_subtype IN ('Cash', 'Bank')
      AND j.entry_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_to])['v'] ?? 0);

// AR: accounts with account_subtype = 'Accounts Receivable'
$receivables = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as v
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'asset' AND a.account_subtype = 'Accounts Receivable'
      AND j.entry_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_to])['v'] ?? 0);

// Inventory: accounts with account_subtype = 'Inventory Asset'
$inventory_asset = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as v
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'asset' AND a.account_subtype = 'Inventory Asset'
      AND j.entry_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_to])['v'] ?? 0);

$current_assets = $cash_bank + $receivables + $inventory_asset;

// Liabilities: all accounts with account_type = 'liability'
$current_liabilities = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END), 0) as v
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'liability'
      AND j.entry_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_to])['v'] ?? 0);

// Equity: accounts with account_type = 'equity' (credit-normal, so flip sign)
$equity_accts = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END), 0) as v
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity'
      AND j.entry_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_to])['v'] ?? 0);

$total_equity = $equity_accts + $net_profit; // equity + current period net income

// ── Ratios ────────────────────────────────────────────────────────────────────
$current_ratio  = $current_liabilities > 0 ? ($current_assets / $current_liabilities) : 0;
$quick_ratio    = $current_liabilities > 0 ? (($cash_bank + $receivables) / $current_liabilities) : 0;
$debt_to_equity = $total_equity > 0 ? ($current_liabilities / $total_equity) : 0;
$gross_margin   = $revenue > 0 ? (($gross_profit / $revenue) * 100) : 0;
$net_margin     = $revenue > 0 ? (($net_profit / $revenue) * 100) : 0;
$roe            = $total_equity > 0 ? (($net_profit / $total_equity) * 100) : 0;
?>

<style>
    .ratio-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 25px; }
    .ratio-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; box-shadow: 0 2px 5px rgba(0,0,0,0.04); border-top: 4px solid #003087; }
    .ratio-card .title { font-size: 13px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
    .ratio-card .value { font-size: 26px; font-weight: 800; color: #003087; margin: 8px 0 4px 0; }
    .ratio-card .benchmark { font-size: 11px; color: #64748b; font-weight: 500; }
</style>

<?php rpt_filter_bar('Financial Ratios & Key Indicators', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], 'tbl-ratios'); ?>

<div class="ns-portlet" style="max-width: 1050px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <div class="ratio-grid">
            <div class="ratio-card">
                <div class="title">Current Ratio (Liquidity)</div>
                <div class="value"><?= number_format($current_ratio, 2) ?> : 1</div>
                <div class="benchmark">Benchmark: &ge; 1.50 | Current Assets / Current Liabilities</div>
            </div>
            <div class="ratio-card" style="border-top-color: #10b981;">
                <div class="title">Quick Ratio (Acid-Test)</div>
                <div class="value" style="color:#059669;"><?= number_format($quick_ratio, 2) ?> : 1</div>
                <div class="benchmark">Benchmark: &ge; 1.00 | (Cash + AR) / Current Liabilities</div>
            </div>
            <div class="ratio-card" style="border-top-color: #f59e0b;">
                <div class="title">Debt to Equity Ratio</div>
                <div class="value" style="color:#d97706;"><?= number_format($debt_to_equity, 2) ?> : 1</div>
                <div class="benchmark">Benchmark: &le; 2.00 | Total Liabilities / Total Equity</div>
            </div>
            <div class="ratio-card" style="border-top-color: #3b82f6;">
                <div class="title">Gross Profit Margin</div>
                <div class="value" style="color:#2563eb;"><?= number_format($gross_margin, 2) ?>%</div>
                <div class="benchmark">Gross Profit / Total Revenue</div>
            </div>
            <div class="ratio-card" style="border-top-color: <?= $net_margin >= 0 ? '#10b981' : '#ef4444' ?>;">
                <div class="title">Net Profit Margin</div>
                <div class="value" style="color:<?= $net_margin >= 0 ? '#059669' : '#dc2626' ?>;"><?= number_format($net_margin, 2) ?>%</div>
                <div class="benchmark">Net Profit / Total Revenue</div>
            </div>
            <div class="ratio-card" style="border-top-color: #8b5cf6;">
                <div class="title">Return on Equity (ROE)</div>
                <div class="value" style="color:#7c3aed;"><?= number_format($roe, 2) ?>%</div>
                <div class="benchmark">Net Profit / Total Equity</div>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-ratios">
            <thead>
                <tr>
                    <th>Financial Indicator / Parameter</th>
                    <th style="text-align:right">Base Value (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Total Revenue / Net Sales</td><td style="text-align:right; font-weight:700; color:#059669;"><?= rpt_currency($revenue) ?></td></tr>
                <tr><td>Cost of Goods Sold (COGS)</td><td style="text-align:right; font-weight:600; color:#dc2626;"><?= rpt_currency($cogs) ?></td></tr>
                <tr><td>Gross Profit</td><td style="text-align:right; font-weight:700; color:#003087;"><?= rpt_currency($gross_profit) ?></td></tr>
                <tr><td>Operating Expenses</td><td style="text-align:right; font-weight:600; color:#dc2626;"><?= rpt_currency($expenses) ?></td></tr>
                <tr style="background:#f8fafc; font-weight:800;"><td>Net Profit / (Loss)</td><td style="text-align:right; color:<?= $net_profit >= 0 ? '#059669' : '#dc2626' ?>; font-size:14px;"><?= rpt_currency($net_profit) ?></td></tr>
                <tr><td>Cash &amp; Bank Balances</td><td style="text-align:right; font-weight:600;"><?= rpt_currency($cash_bank) ?></td></tr>
                <tr><td>Accounts Receivable</td><td style="text-align:right; font-weight:600;"><?= rpt_currency($receivables) ?></td></tr>
                <tr><td>Inventory Asset Value</td><td style="text-align:right; font-weight:600;"><?= rpt_currency($inventory_asset) ?></td></tr>
                <tr><td>Total Current Liabilities</td><td style="text-align:right; font-weight:700; color:#dc2626;"><?= rpt_currency($current_liabilities) ?></td></tr>
                <tr style="background:#003087; color:#fff; font-weight:900;">
                    <td style="padding:10px 14px">TOTAL OWNER'S EQUITY (incl. Net Income)</td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($total_equity) ?></td>
                </tr>
            </tbody>
        </table>

    </div>
</div>
