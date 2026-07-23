<?php
/**
 * Investment Payback & Break-Even Tracking Report
 * Tracks initial startup investment capital, loans, net profit recovery, and remaining target to reach Rs 0 (Break-Even).
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$today     = date('Y-m-d');
$fy_info   = calculate_fiscal_info($today);
$date_from = $_GET['date_from'] ?? '2026-07-17'; // FY 83/84 start date
$date_to   = $_GET['date_to']   ?? $today;

// 1. Fetch Loans & Initial Investment Accounts
$loans = $db->fetchAll("
    SELECT a.account_code, a.account_name,
           SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) as balance
    FROM accounts a
    LEFT JOIN journal_entries j ON a.id = j.account_id
    LEFT JOIN transaction_headers h ON j.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
    WHERE a.account_code LIKE '25%' OR a.account_code = '3100'
    GROUP BY a.id, a.account_code, a.account_name
    HAVING balance != 0
");

$total_loans = 0;
foreach ($loans as $l) {
    if ($l['account_code'] != '3100') {
        $total_loans += (float)$l['balance'];
    }
}
if ($total_loans <= 0) $total_loans = 1070000.00; // Default fallback to initial loan capital

// 2. Fetch Initial Setup Expenses & Assets
$shutter_cost = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as total
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_code = '1510' AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
")['total'] ?? 290000.00);

$initial_setup_expenses = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as total
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_number = 'JV-20260717-001' AND j.account_id = 'acc-6000' AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
")['total'] ?? 250000.00);

// Total Initial Outlay Target
$total_investment_target = $total_loans;

// 3. Calculate Period Operating Profit / Loss
$revenue = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END), 0) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'income' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
", [$date_from, $date_to])['v'] ?? 0);

$cogs = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_code = '5100' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
", [$date_from, $date_to])['v'] ?? 0);

$operating_expenses = (float)($db->fetchOne("
    SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND a.account_code != '5100' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND (h.txn_number IS NULL OR h.txn_number != 'JV-20260717-001')
", [$date_from, $date_to])['v'] ?? 0);

$gross_profit   = $revenue - $cogs;
$net_op_profit  = $gross_profit - $operating_expenses;

// Payback Math
$unrecovered_investment = max(0, $total_investment_target - $net_op_profit);
$payback_pct            = $total_investment_target > 0 ? min(100, max(0, ($net_op_profit / $total_investment_target) * 100)) : 0;

// Daily / Monthly Run-Rate Projections
$days_diff = max(1, (int)((strtotime($date_to) - strtotime($date_from)) / 86400) + 1);
$avg_daily_profit = $net_op_profit > 0 ? ($net_op_profit / $days_diff) : 0;
$avg_monthly_profit = $avg_daily_profit * 30;

$est_days_to_be = ($avg_daily_profit > 0 && $unrecovered_investment > 0) ? ceil($unrecovered_investment / $avg_daily_profit) : null;
$est_months_to_be = $est_days_to_be !== null ? round($est_days_to_be / 30, 1) : null;
?>

<style>
.be-container { max-width: 1000px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.be-header-card { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(15,23,42,0.3); }
.be-header-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; display: flex; align-items: center; gap: 10px; }
.be-header-sub { font-size: 13px; color: #94a3b8; margin-top: 4px; }

.be-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
.be-kpi-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.be-kpi-lbl { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; }
.be-kpi-val { font-size: 22px; font-weight: 800; color: #0f172a; margin-top: 6px; }
.be-kpi-sub { font-size: 12px; color: #64748b; margin-top: 4px; }

.be-progress-wrap { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 20px; margin-bottom: 24px; }
.be-progress-bar-bg { height: 16px; background: #e2e8f0; border-radius: 8px; overflow: hidden; margin-top: 10px; position: relative; }
.be-progress-bar-fill { height: 100%; background: linear-gradient(90deg, #10b981 0%, #059669 100%); transition: width 0.6s ease; }

.be-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.be-card-header { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-size: 14px; color: #1e293b; display: flex; align-items: center; justify-content: space-between; }
.be-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.be-table th { background: #f1f5f9; padding: 10px 18px; text-align: left; font-weight: 700; color: #475569; border-bottom: 1px solid #e2e8f0; }
.be-table td { padding: 12px 18px; border-bottom: 1px solid #f1f5f9; color: #334155; }
.be-table tr:last-child td { border-bottom: none; }
.be-table .row-highlight { background: #f8fafc; font-weight: 700; }
.be-table .row-total { background: #eff6ff; font-weight: 800; color: #1e40af; font-size: 14px; }

.be-suggestions { background: #fffbe6; border: 1px solid #ffe58f; border-left: 5px solid #faad14; border-radius: 10px; padding: 20px; margin-bottom: 24px; }
.be-sug-title { font-weight: 800; font-size: 15px; color: #d46b08; display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
.be-sug-list { display: flex; flex-direction: column; gap: 12px; }
.be-sug-item { display: flex; align-items: flex-start; gap: 12px; background: #ffffff; padding: 12px 16px; border-radius: 8px; border: 1px solid #ffe7ba; font-size: 13px; color: #434343; }
.be-sug-icon { width: 28px; height: 28px; border-radius: 6px; background: #fffbe6; color: #fa8c16; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
</style>

<div class="be-container">

    <!-- Filter Bar -->
    <?php rpt_filter_bar('Investment Payback & Break-Even Tracker', [
        ['name'=>'date_from', 'label'=>'From Date', 'type'=>'date', 'default'=>$date_from],
        ['name'=>'date_to',   'label'=>'To Date',   'type'=>'date', 'default'=>$date_to],
    ], 'tbl-break-even'); ?>

    <!-- Header Card -->
    <div class="be-header-card">
        <div class="be-header-title">
            <i class="fas fa-bullseye" style="color: #38bdf8;"></i>
            INVESTMENT PAYBACK & BREAK-EVEN REPORT
        </div>
        <div class="be-header-sub">
            Tracking initial capital recovery, sales profit payback, and remaining target to reach <strong>Rs 0.00 (Break-Even)</strong>.
        </div>
    </div>

    <!-- Top Summary KPI Grid -->
    <div class="be-kpi-grid">
        <div class="be-kpi-card">
            <div class="be-kpi-lbl">Total Startup Capital / Loans</div>
            <div class="be-kpi-val" style="color: #0284c7;"><?= rpt_currency($total_investment_target) ?></div>
            <div class="be-kpi-sub">Initial Capital Outlay & Loans</div>
        </div>
        <div class="be-kpi-card">
            <div class="be-kpi-lbl">Net Sales Profit Recovered</div>
            <div class="be-kpi-val" style="color: #16a34a;"><?= rpt_currency($net_op_profit) ?></div>
            <div class="be-kpi-sub">Cumulative Net Operating Profit</div>
        </div>
        <div class="be-kpi-card">
            <div class="be-kpi-lbl">Unrecovered Capital Target</div>
            <div class="be-kpi-val" style="color: #e11d48;"><?= rpt_currency($unrecovered_investment) ?></div>
            <div class="be-kpi-sub">Target to reach Rs 0 (Break-Even)</div>
        </div>
        <div class="be-kpi-card">
            <div class="be-kpi-lbl">Payback Progress</div>
            <div class="be-kpi-val" style="color: #8b5cf6;"><?= number_format($payback_pct, 2) ?>%</div>
            <div class="be-kpi-sub"><?= $est_months_to_be !== null ? 'Est. ' . $est_months_to_be . ' months to Break-Even' : 'Generating Sales Profits' ?></div>
        </div>
    </div>

    <!-- Progress Bar Section -->
    <div class="be-progress-wrap">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-weight: 700; font-size: 14px; color: #1e293b;"><i class="fas fa-tasks" style="color: #10b981; margin-right: 6px;"></i> Break-Even Recovery Progress</span>
            <span style="font-weight: 800; font-size: 15px; color: #059669;"><?= number_format($payback_pct, 2) ?>% RECOVERED</span>
        </div>
        <div class="be-progress-bar-bg">
            <div class="be-progress-bar-fill" style="width: <?= max(2, $payback_pct) ?>%;"></div>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 12px; color: #64748b; margin-top: 8px;">
            <span>Initial Investment: <?= rpt_currency($total_investment_target) ?></span>
            <span>Remaining Unrecovered: <strong style="color: #e11d48;"><?= rpt_currency($unrecovered_investment) ?></strong></span>
            <span>Target: <strong>Rs 0.00 (Break-Even)</strong></span>
        </div>
    </div>

    <!-- Breakdown Table -->
    <div class="be-card">
        <div class="be-card-header">
            <span><i class="fas fa-list-ol" style="margin-right: 8px; color: #0284c7;"></i> Investment Payback Ledger Breakdown</span>
            <span style="font-size: 12px; font-weight: 500; color: #64748b;">Period: <?= rpt_date($date_from) ?> to <?= rpt_date($date_to) ?></span>
        </div>
        <table class="be-table" id="tbl-break-even">
            <thead>
                <tr>
                    <th width="120">Category</th>
                    <th>Account / Item Description</th>
                    <th style="text-align: right;" width="160">Capital Outlay</th>
                    <th style="text-align: right;" width="160">Profit Recovered</th>
                    <th style="text-align: right;" width="180">Net Remaining Target</th>
                </tr>
            </thead>
            <tbody>
                <!-- 1. Loans & Capital Section -->
                <tr class="row-highlight">
                    <td colspan="5">1. INITIAL STARTUP CAPITAL & LOAN LIABILITIES (INVESTMENT TARGET)</td>
                </tr>
                <?php foreach ($loans as $l): if ($l['account_code'] != '3100'): ?>
                <tr>
                    <td style="color: #64748b; font-weight: 600;"><?= $l['account_code'] ?></td>
                    <td><?= htmlspecialchars($l['account_name']) ?></td>
                    <td style="text-align: right; font-weight: 600; color: #0284c7;"><?= rpt_currency($l['balance']) ?></td>
                    <td style="text-align: right; color: #94a3b8;">-</td>
                    <td style="text-align: right; font-weight: 600; color: #0284c7;"><?= rpt_currency($l['balance']) ?></td>
                </tr>
                <?php endif; endforeach; ?>
                <tr>
                    <td style="color: #64748b; font-weight: 600;">1510 / 6000</td>
                    <td>Shutter Setup & Initial Operational Outlays</td>
                    <td style="text-align: right; font-weight: 600; color: #0284c7;"><?= rpt_currency($shutter_cost + $initial_setup_expenses) ?></td>
                    <td style="text-align: right; color: #94a3b8;">-</td>
                    <td style="text-align: right; font-weight: 600; color: #0284c7;"><?= rpt_currency($shutter_cost + $initial_setup_expenses) ?></td>
                </tr>
                <tr style="border-bottom: 2px solid #cbd5e1; font-weight: 700;">
                    <td colspan="2" style="text-align: right;">SUBTOTAL STARTUP INVESTMENT TARGET:</td>
                    <td style="text-align: right; color: #0284c7;"><?= rpt_currency($total_investment_target) ?></td>
                    <td style="text-align: right;">-</td>
                    <td style="text-align: right; color: #0284c7;"><?= rpt_currency($total_investment_target) ?></td>
                </tr>

                <!-- 2. Operating Sales Profits -->
                <tr class="row-highlight">
                    <td colspan="5">2. OPERATING SALES PROFITS GENERATED TO DATE (RECOVERY)</td>
                </tr>
                <tr>
                    <td>REVENUE</td>
                    <td>Gross Sales Revenues Earned</td>
                    <td style="text-align: right; color: #94a3b8;">-</td>
                    <td style="text-align: right; font-weight: 600; color: #16a34a;"><?= rpt_currency($revenue) ?></td>
                    <td style="text-align: right; color: #94a3b8;">-</td>
                </tr>
                <tr>
                    <td>COGS</td>
                    <td>Cost of Goods Sold (Product Purchases Cost)</td>
                    <td style="text-align: right; color: #94a3b8;">-</td>
                    <td style="text-align: right; font-weight: 600; color: #dc2626;">-<?= rpt_currency($cogs) ?></td>
                    <td style="text-align: right; color: #94a3b8;">-</td>
                </tr>
                <tr>
                    <td>EXPENSES</td>
                    <td>Regular Operating & Shop Expenses</td>
                    <td style="text-align: right; color: #94a3b8;">-</td>
                    <td style="text-align: right; font-weight: 600; color: #dc2626;">-<?= rpt_currency($operating_expenses) ?></td>
                    <td style="text-align: right; color: #94a3b8;">-</td>
                </tr>
                <tr style="border-bottom: 2px solid #cbd5e1; font-weight: 700;">
                    <td colspan="2" style="text-align: right;">NET OPERATING PROFIT RECOVERED:</td>
                    <td style="text-align: right;">-</td>
                    <td style="text-align: right; color: #16a34a; font-size: 14px;"><?= rpt_currency($net_op_profit) ?></td>
                    <td style="text-align: right;">-</td>
                </tr>

                <!-- 3. Final Payback Summary -->
                <tr class="row-total">
                    <td colspan="2">TOTAL UNRECOVERED CAPITAL REMAINING (TARGET = RS 0.00)</td>
                    <td style="text-align: right;"><?= rpt_currency($total_investment_target) ?></td>
                    <td style="text-align: right; color: #16a34a;"><?= rpt_currency($net_op_profit) ?></td>
                    <td style="text-align: right; color: #e11d48; font-size: 16px;"><?= rpt_currency($unrecovered_investment) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Run Rate & Projections Card -->
    <div class="be-card" style="padding: 20px;">
        <div style="font-size: 15px; font-weight: 800; color: #1e293b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-chart-line" style="color: #0284c7;"></i> Run-Rate & Break-Even Projection
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; font-size: 13px; color: #475569;">
            <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Average Daily Profit</div>
                <div style="font-size: 18px; font-weight: 800; color: #0284c7; margin-top: 4px;"><?= rpt_currency($avg_daily_profit) ?> / day</div>
            </div>
            <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Monthly Profit Run-Rate</div>
                <div style="font-size: 18px; font-weight: 800; color: #16a34a; margin-top: 4px;"><?= rpt_currency($avg_monthly_profit) ?> / month</div>
            </div>
            <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Estimated Time to Break-Even</div>
                <div style="font-size: 18px; font-weight: 800; color: #8b5cf6; margin-top: 4px;">
                    <?= $est_months_to_be !== null ? $est_months_to_be . ' Months (' . $est_days_to_be . ' Days)' : 'N/A' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Strategic Business Suggestions -->
    <div class="be-suggestions">
        <div class="be-sug-title">
            <i class="fas fa-lightbulb" style="font-size: 18px; color: #fa8c16;"></i>
            Strategic Recommendations & Actionable Suggestions to Reach Break-Even Faster
        </div>
        <div class="be-sug-list">
            <div class="be-sug-item">
                <div class="be-sug-icon"><i class="fas fa-percent"></i></div>
                <div>
                    <strong>1. Optimize Product Profit Margins</strong><br>
                    Focus on driving sales of high-margin items (such as premium spirits and wines with 15–22% margins) rather than relying solely on low-margin fast movers. Increasing overall gross margin by just 3-5% significantly accelerates your payback timeline.
                </div>
            </div>
            <div class="be-sug-item">
                <div class="be-sug-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div>
                    <strong>2. Structured Loan Repayment Priority</strong><br>
                    Allocate a fixed percentage (e.g. 60%) of weekly cash profits directly into paying down loan balances (Sahakari, Mamu, Sharmila, Sanjay). Paying off principal consistently lowers financial burden and speeds up your path to zero unrecovered capital.
                </div>
            </div>
            <div class="be-sug-item">
                <div class="be-sug-icon"><i class="fas fa-shield-alt"></i></div>
                <div>
                    <strong>3. Strict Credit Limit Enforcement</strong><br>
                    Utilize the newly enabled Customer Credit Limit warnings to ensure credit sales do not tie up working capital in uncollected receivables. Prompt cash collection ensures immediate liquidity to service debts.
                </div>
            </div>
            <div class="be-sug-item">
                <div class="be-sug-icon"><i class="fas fa-chart-bar"></i></div>
                <div>
                    <strong>4. Monitor Daily Operating Expense Ratios</strong><br>
                    Keep recurring shop operating expenses below 15% of daily gross profit. Every rupee saved in daily overhead goes 100% towards shortening your break-even period.
                </div>
            </div>
        </div>
    </div>

</div>
