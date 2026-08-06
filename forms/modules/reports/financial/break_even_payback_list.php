<?php
/**
 * Professional ERP Break-Even Analysis & Investment Tracking Report
 * Calculates minimum sales required to cover all operating costs, contribution margins, margins of safety,
 * and dynamically presents all active Chart of Accounts (COA) balances for financial position reference.
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

// 1. Date Range Handling & Presets (Current Fiscal Year default)
$today     = date('Y-m-d');
$first_txn = $db->fetchOne("SELECT MIN(txn_date) as min_date FROM transaction_headers WHERE is_deleted = 0 AND status NOT IN ('void','voided','draft')")['min_date'] ?? '2026-01-01';

// Active/Current Fiscal Year calculation
$active_fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE ? BETWEEN start_date AND end_date LIMIT 1", [$today]);
if (!$active_fy) {
    $active_fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE status IN ('open', 'reopened') ORDER BY start_date DESC LIMIT 1");
}
if (!$active_fy) {
    $active_fy = $db->fetchOne("SELECT * FROM fiscal_years ORDER BY start_date DESC LIMIT 1");
}

if ($active_fy) {
    $fy_start_date = $active_fy['start_date'];
    $fy_end_date   = $active_fy['end_date'];
    $fy_name       = $active_fy['name'];
} else {
    $m = (int)date('n'); $d = (int)date('j'); $y = (int)date('Y');
    if ($m > 7 || ($m == 7 && $d >= 16)) {
        $fy_start_date = "{$y}-07-16";
        $fy_end_date   = ($y+1) . "-07-15";
    } else {
        $fy_start_date = ($y-1) . "-07-16";
        $fy_end_date   = "{$y}-07-15";
    }
    $fy_name = "Current FY";
}

$preset       = $_GET['preset'] ?? 'fiscal_year';
$date_from_in = $_GET['from_date'] ?? '';
$date_to_in   = $_GET['to_date'] ?? '';

if (!empty($date_from_in) && !empty($date_to_in)) {
    $date_from = $date_from_in;
    $date_to   = $date_to_in;
    $preset    = 'custom';
} else {
    switch ($preset) {
        case 'today':
            $date_from = $today;
            $date_to   = $today;
            break;
        case 'yesterday':
            $date_from = date('Y-m-d', strtotime('-1 day'));
            $date_to   = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to   = $today;
            break;
        case 'this_month':
            $date_from = date('Y-m-01');
            $date_to   = $today;
            break;
        case 'quarter':
            $current_month = (int)date('n');
            $q_month       = (int)(floor(($current_month - 1) / 3) * 3 + 1);
            $date_from     = date('Y-' . sprintf('%02d', $q_month) . '-01');
            $date_to       = $today;
            break;
        case 'all_time':
            $date_from = $first_txn;
            $date_to   = $today;
            break;
        case 'fiscal_year':
        default:
            $date_from = $fy_start_date;
            $date_to   = $fy_end_date;
            break;
    }
}

$loc_sql_h  = rpt_location_sql('h');
$loc_sql_pe = rpt_location_sql('pe');

// 2. POS Sales & COGS for the period
$pos_data = $db->fetchOne("
    SELECT
        COALESCE(SUM(pi.net_amount - pi.tax), 0) as sales,
        COALESCE(SUM(pi.quantity * i.cost_price), 0) as cogs
    FROM pos_items pi
    JOIN items i ON pi.item_id = i.id AND i.is_deleted = 0
    JOIN pos_entry pe ON pi.pos_id = pe.id
    WHERE pe.is_deleted = 0 {$loc_sql_pe}
      AND DATE(pe.date_time) BETWEEN ? AND ?
", [$date_from, $date_to]);

// 3. Non-POS Customer Invoices Sales & COGS for the period
$invoice_data = $db->fetchOne("
    SELECT
        COALESCE(SUM(l.line_total), 0) as sales,
        COALESCE(SUM(l.cost_price * l.quantity), 0) as cogs
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_type = 'customer_invoice'
      AND h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_number NOT LIKE 'POS-%'
      AND h.txn_number NOT LIKE 'INV-POS-%'
      AND COALESCE(h.source, '') != 'pos_sync' {$loc_sql_h}
", [$date_from, $date_to]);

// 4. Sales Returns & Credit Memo COGS adjustment
$credit_memo_data = $db->fetchOne("
    SELECT 
        COALESCE(SUM(cm.total_amount), 0) as sales_return,
        COALESCE((
            SELECT SUM(l.cost_price * l.quantity)
            FROM transaction_lines l
            WHERE l.header_id = cm.header_id
        ), 0) as cogs_return
    FROM credit_memos cm
    JOIN transaction_headers h ON cm.header_id = h.id
    WHERE h.txn_type = 'credit_memo'
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ? {$loc_sql_h}
", [$date_from, $date_to]);

// 5. Additional Manual Journal Entries for Income & COGS
$journal_data = $db->fetchOne("
    SELECT 
        COALESCE(SUM(CASE WHEN a.account_type = 'income' THEN (CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) ELSE 0 END), 0) as j_income,
        COALESCE(SUM(CASE WHEN (a.id = 'acc-5100' OR a.account_subtype = 'Cost of Goods Sold' OR a.account_type = 'cost_of_goods_sold') THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END), 0) as j_cogs
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_type IN ('Journal', 'journal_entry')
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ? {$loc_sql_h}
", [$date_from, $date_to]);

$gross_sales   = (float)$pos_data['sales'] + (float)$invoice_data['sales'] + (float)$journal_data['j_income'];
$sales_returns = (float)$credit_memo_data['sales_return'];
$net_sales     = max(0, $gross_sales - $sales_returns);
$cogs          = max(0, (float)$pos_data['cogs'] + (float)$invoice_data['cogs'] + (float)$journal_data['j_cogs'] - (float)$credit_memo_data['cogs_return']);

// 6. Gross Profit & Contribution Margin
$gross_profit            = $net_sales - $cogs;
$gross_margin_pct        = $net_sales > 0 ? ($gross_profit / $net_sales) * 100 : 0;
$contribution_margin     = $gross_profit;
$contribution_margin_pct = $net_sales > 0 ? ($contribution_margin / $net_sales) * 100 : 0;

// 7. Operating Expenses & Fixed Costs
$operating_expenses_list = $db->fetchAll("
    SELECT a.id, a.account_name,
           COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as amount
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense'
      AND a.id != 'acc-5100'
      AND LOWER(a.account_name) NOT LIKE '%cost of goods%'
      AND LOWER(a.account_name) NOT LIKE '%purchase%'
      AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft') {$loc_sql_h}
    GROUP BY a.id, a.account_name
    HAVING amount > 0
    ORDER BY amount DESC
", [$date_from, $date_to]);

$direct_expenses = (float)($db->fetchOne("
    SELECT COALESCE(SUM(e.amount), 0) as total
    FROM expenses e
    JOIN transaction_headers h ON e.header_id = h.id
    WHERE h.txn_type = 'expense'
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ? {$loc_sql_h}
", [$date_from, $date_to])['total'] ?? 0);

$total_operating_expenses = 0;
foreach ($operating_expenses_list as $oe) {
    $total_operating_expenses += (float)$oe['amount'];
}
if ($direct_expenses > $total_operating_expenses) {
    $total_operating_expenses = $direct_expenses;
}

$fixed_costs = $total_operating_expenses;

if ($contribution_margin_pct > 0) {
    $break_even_sales = $fixed_costs / ($contribution_margin_pct / 100);
} else {
    $break_even_sales = $fixed_costs + $cogs;
}

$margin_of_safety     = $net_sales - $break_even_sales;
$margin_of_safety_pct = $net_sales > 0 ? ($margin_of_safety / $net_sales) * 100 : 0;
$net_profit           = $gross_profit - $total_operating_expenses;
$be_achievement_pct   = $break_even_sales > 0 ? min(200, max(0, ($net_sales / $break_even_sales) * 100)) : 0;

// 8. Status Zone Indicator
if ($net_sales < $break_even_sales || $net_profit < 0) {
    $status_zone   = 'loss';
    $status_label  = 'LOSS ZONE';
    $status_color  = '#ef4444';
    $status_bg     = '#fef2f2';
    $status_border = '#fca5a5';
    $deficit       = max($break_even_sales - $net_sales, abs($net_profit));
    $status_msg    = 'Net sales are ' . rpt_currency($deficit) . ' below the Break-Even point for this period.';
} elseif ($net_sales > 0 && abs($net_sales - $break_even_sales) < 1.0) {
    $status_zone   = 'breakeven';
    $status_label  = 'BREAK-EVEN ZONE';
    $status_color  = '#f59e0b';
    $status_bg     = '#fffbeb';
    $status_border = '#fde68a';
    $status_msg    = 'Net sales have covered all operating expenses exactly.';
} else {
    $status_zone   = 'profit';
    $status_label  = 'PROFIT ZONE';
    $status_color  = '#10b981';
    $status_bg     = '#ecfdf5';
    $status_border = '#a7f3d0';
    $status_msg    = 'Net sales have surpassed Break-Even by ' . rpt_currency($net_sales - $break_even_sales) . ' (' . number_format($margin_of_safety_pct, 1) . '% safety buffer).';
}

// 7. Dynamic COA Financial Position Reference Data (All Used Accounts from COA — Position as of selected period)
// A. Asset Accounts from COA
$asset_accounts_coa = $db->fetchAll("
    SELECT a.id, a.account_name, a.account_subtype,
           COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as balance
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'asset' AND a.is_active = 1 AND a.is_deleted = 0
      AND h.txn_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft') {$loc_sql_h}
    GROUP BY a.id, a.account_name, a.account_subtype
    HAVING balance != 0
    ORDER BY a.account_name ASC
", [$date_to]);

// B. Liability Accounts from COA
$liability_accounts_coa = $db->fetchAll("
    SELECT a.id, a.account_name, a.account_subtype,
           COALESCE(SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END), 0) as balance
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'liability' AND a.is_active = 1 AND a.is_deleted = 0
      AND h.txn_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft') {$loc_sql_h}
    GROUP BY a.id, a.account_name, a.account_subtype
    HAVING balance != 0
    ORDER BY a.account_name ASC
", [$date_to]);

// C. Equity Accounts from COA
$equity_accounts_coa = $db->fetchAll("
    SELECT a.id, a.account_name, a.account_subtype,
           COALESCE(SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END), 0) as balance
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity' AND a.is_active = 1 AND a.is_deleted = 0
      AND h.txn_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft') {$loc_sql_h}
    GROUP BY a.id, a.account_name, a.account_subtype
    HAVING balance != 0
    ORDER BY a.account_name ASC
", [$date_to]);

// 8. Monthly Trends for Chart.js (Calculated within selected period date range)
$monthly_rows = $db->fetchAll("
    SELECT DATE_FORMAT(h.txn_date, '%b %Y') as month_label,
           DATE_FORMAT(h.txn_date, '%Y-%m') as sort_key,
           SUM(CASE WHEN a.account_type = 'income' THEN (CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as sales,
           SUM(CASE WHEN a.id = 'acc-5100' THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as cogs,
           SUM(CASE WHEN a.account_type = 'expense' AND a.id != 'acc-5100' THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as opex
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
      AND h.txn_date BETWEEN ? AND ? {$loc_sql_h}
    GROUP BY DATE_FORMAT(h.txn_date, '%b %Y'), DATE_FORMAT(h.txn_date, '%Y-%m')
    ORDER BY sort_key ASC
", [$date_from, $date_to]);

$chart_months = [];
$chart_sales  = [];
$chart_profit = [];
$chart_be     = [];
foreach ($monthly_rows as $mr) {
    $m_sales  = (float)$mr['sales'];
    $m_cogs   = (float)$mr['cogs'];
    $m_opex   = (float)$mr['opex'];
    $m_gp     = $m_sales - $m_cogs;
    $m_np     = $m_gp - $m_opex;
    $m_cm_pct = $m_sales > 0 ? ($m_gp / $m_sales) : 0;
    $m_be     = $m_cm_pct > 0 ? ($m_opex / $m_cm_pct) : 0;

    $chart_months[] = $mr['month_label'];
    $chart_sales[]  = round($m_sales, 2);
    $chart_profit[] = round($m_np, 2);
    $chart_be[]     = round($m_be, 2);
}

// Render Header & Filters
rpt_header('ERP Break-Even & Financial Performance Analysis');
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .be-container { font-family: 'Inter', system-ui, -apple-system, sans-serif; color: #1e293b; }
    
    /* Preset Filter Bar */
    .be-preset-bar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; background: #fff; padding: 12px 18px; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); align-items: center; }
    .be-preset-label { font-weight: 700; font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 6px; }
    .be-preset-btn { background: #f8fafc; border: 1px solid #cbd5e1; color: #334155; font-size: 12px; font-weight: 600; padding: 6px 14px; border-radius: 6px; cursor: pointer; transition: all 0.15s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
    .be-preset-btn:hover { background: #003087; color: #fff; border-color: #003087; }
    .be-preset-btn.active { background: #003087; color: #fff; border-color: #003087; box-shadow: 0 2px 4px rgba(0,48,135,0.2); }
    
    /* Status Banner */
    .be-status-banner { display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; border-radius: 12px; border: 1.5px solid <?php echo $status_border; ?>; background: <?php echo $status_bg; ?>; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); }
    .be-status-left { display: flex; align-items: center; gap: 16px; }
    .be-status-pill { background: <?php echo $status_color; ?>; color: #fff; font-size: 12px; font-weight: 800; padding: 6px 16px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; }
    .be-status-msg { font-size: 14px; font-weight: 600; color: #1e293b; }
    .be-status-metric { font-size: 22px; font-weight: 800; color: <?php echo $status_color; ?>; text-align: right; }
    
    /* KPI Cards Grid */
    .be-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .be-kpi-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); position: relative; overflow: hidden; transition: transform 0.15s ease, box-shadow 0.15s ease; }
    .be-kpi-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); }
    .be-kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: #003087; }
    .be-kpi-card.cogs::before { background: #f59e0b; }
    .be-kpi-card.gp::before { background: #10b981; }
    .be-kpi-card.opex::before { background: #ef4444; }
    .be-kpi-card.be::before { background: #6366f1; }
    .be-kpi-card.mos::before { background: #06b6d4; }
    .be-kpi-card.np::before { background: #8b5cf6; }
    
    .be-kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .be-kpi-title { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
    .be-kpi-icon { font-size: 16px; color: #94a3b8; }
    .be-kpi-value { font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
    .be-kpi-sub { font-size: 11px; font-weight: 600; color: #64748b; }
    
    /* Section Block */
    .be-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .be-section-title { font-size: 15px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
    
    /* Financial Position Grid */
    .be-fin-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
    .be-fin-col { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; }
    .be-fin-head { font-size: 13px; font-weight: 800; color: #003087; text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; border-bottom: 1.5px solid #cbd5e1; padding-bottom: 6px; }
    .be-fin-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
    .be-fin-row:last-child { border-bottom: none; }
    .be-fin-label { color: #475569; font-weight: 600; text-decoration: none; }
    .be-fin-label:hover { color: #003087; text-decoration: underline; }
    .be-fin-val { font-weight: 700; color: #0f172a; text-decoration: none; }
    .be-fin-val:hover { color: #003087; }
    
    /* Visual Charts Grid */
    .be-charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 20px; margin-bottom: 24px; }
    .be-chart-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .be-chart-title { font-size: 14px; font-weight: 700; color: #1e293b; margin-bottom: 16px; text-transform: uppercase; letter-spacing: 0.4px; }
    
    /* Table Styling */
    .be-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
    .be-table th { background: #f1f5f9; color: #475569; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; padding: 10px 14px; border-bottom: 2px solid #cbd5e1; text-align: left; }
    .be-table td { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; color: #334155; }
    .be-table tr:hover { background: #f8fafc; }
    
    /* Progress Bar */
    .be-progress-bg { background: #e2e8f0; border-radius: 10px; height: 14px; width: 100%; overflow: hidden; margin-top: 6px; }
    .be-progress-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981); border-radius: 10px; transition: width 0.4s ease; }
</style>

<div class="be-container">

    <!-- Quick Preset Date Filter Bar -->
    <div class="be-preset-bar no-print">
        <span class="be-preset-label"><i class="fas fa-calendar-alt"></i> Date Preset:</span>
        <a href="?page=reports/financial/break_even_payback&preset=fiscal_year" class="be-preset-btn <?php echo $preset === 'fiscal_year' ? 'active' : ''; ?>"><i class="fas fa-university"></i> Current Fiscal Year (<?php echo htmlspecialchars($fy_name); ?>)</a>
        <a href="?page=reports/financial/break_even_payback&preset=today" class="be-preset-btn <?php echo $preset === 'today' ? 'active' : ''; ?>">Today</a>
        <a href="?page=reports/financial/break_even_payback&preset=yesterday" class="be-preset-btn <?php echo $preset === 'yesterday' ? 'active' : ''; ?>">Yesterday</a>
        <a href="?page=reports/financial/break_even_payback&preset=this_week" class="be-preset-btn <?php echo $preset === 'this_week' ? 'active' : ''; ?>">This Week</a>
        <a href="?page=reports/financial/break_even_payback&preset=this_month" class="be-preset-btn <?php echo $preset === 'this_month' ? 'active' : ''; ?>">This Month</a>
        <a href="?page=reports/financial/break_even_payback&preset=quarter" class="be-preset-btn <?php echo $preset === 'quarter' ? 'active' : ''; ?>">This Quarter</a>
        <a href="?page=reports/financial/break_even_payback&preset=all_time" class="be-preset-btn <?php echo $preset === 'all_time' ? 'active' : ''; ?>">All Time</a>
    </div>

    <!-- Filter Form -->
    <?php rpt_filter_bar('Break-Even & Operating Performance Report', [
        ['name' => 'from_date', 'label' => 'From Date', 'type' => 'date', 'default' => $date_from],
        ['name' => 'to_date',   'label' => 'To Date',   'type' => 'date', 'default' => $date_to],
    ], 'be-export-tbl'); ?>

    <!-- Status Banner -->
    <div class="be-status-banner">
        <div class="be-status-left">
            <span class="be-status-pill"><?php echo $status_label; ?></span>
            <div class="be-status-msg"><?php echo $status_msg; ?></div>
        </div>
        <div>
            <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Break-Even Target</div>
            <div class="be-status-metric"><?php echo rpt_currency($break_even_sales); ?></div>
        </div>
    </div>

    <!-- 9 Dashboard KPI Cards Grid -->
    <div class="be-kpi-grid">
        <div class="be-kpi-card">
            <div class="be-kpi-header">
                <span class="be-kpi-title">Total Net Sales</span>
                <i class="fas fa-shopping-cart be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value"><a href="?page=reports/financial/general_ledger&account_type=income" style="color:inherit; text-decoration:none;"><?php echo rpt_currency($net_sales); ?></a></div>
            <div class="be-kpi-sub">Gross: <?php echo rpt_currency($gross_sales); ?></div>
        </div>

        <div class="be-kpi-card cogs">
            <div class="be-kpi-header">
                <span class="be-kpi-title">Total COGS</span>
                <i class="fas fa-box-open be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value"><?php echo rpt_currency($cogs); ?></div>
            <div class="be-kpi-sub"><?php echo number_format($net_sales > 0 ? ($cogs / $net_sales) * 100 : 0, 1); ?>% of Sales</div>
        </div>

        <div class="be-kpi-card gp">
            <div class="be-kpi-header">
                <span class="be-kpi-title">Gross Profit</span>
                <i class="fas fa-chart-line be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value"><?php echo rpt_currency($gross_profit); ?></div>
            <div class="be-kpi-sub">Margin: <?php echo number_format($gross_margin_pct, 1); ?>%</div>
        </div>

        <div class="be-kpi-card opex">
            <div class="be-kpi-header">
                <span class="be-kpi-title">Fixed Operating Costs</span>
                <i class="fas fa-receipt be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value"><a href="?page=reports/financial/general_ledger&account_type=expense" style="color:inherit; text-decoration:none;"><?php echo rpt_currency($fixed_costs); ?></a></div>
            <div class="be-kpi-sub">Excludes COGS & Purchases</div>
        </div>

        <div class="be-kpi-card be">
            <div class="be-kpi-header">
                <span class="be-kpi-title">Contribution Margin</span>
                <i class="fas fa-percentage be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value"><?php echo rpt_currency($contribution_margin); ?></div>
            <div class="be-kpi-sub">CM Ratio: <?php echo number_format($contribution_margin_pct, 1); ?>%</div>
        </div>

        <div class="be-kpi-card be">
            <div class="be-kpi-header">
                <span class="be-kpi-title">Break-Even Sales</span>
                <i class="fas fa-bullseye be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value"><?php echo rpt_currency($break_even_sales); ?></div>
            <div class="be-kpi-sub">Required Min Revenue</div>
        </div>

        <div class="be-kpi-card mos">
            <div class="be-kpi-header">
                <span class="be-kpi-title">Margin of Safety</span>
                <i class="fas fa-shield-alt be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value"><?php echo rpt_currency($margin_of_safety); ?></div>
            <div class="be-kpi-sub">Buffer: <?php echo number_format($margin_of_safety_pct, 1); ?>%</div>
        </div>

        <div class="be-kpi-card np">
            <div class="be-kpi-header">
                <span class="be-kpi-title">Net Profit / Loss</span>
                <i class="fas fa-wallet be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value" style="color: <?php echo $net_profit >= 0 ? '#10b981' : '#ef4444'; ?>;">
                <?php echo rpt_currency($net_profit); ?>
            </div>
            <div class="be-kpi-sub">GP − Operating Expense</div>
        </div>

        <div class="be-kpi-card">
            <div class="be-kpi-header">
                <span class="be-kpi-title">BE Achievement</span>
                <i class="fas fa-flag-checkered be-kpi-icon"></i>
            </div>
            <div class="be-kpi-value"><?php echo number_format($be_achievement_pct, 1); ?>%</div>
            <div class="be-progress-bg">
                <div class="be-progress-fill" style="width: <?php echo min(100, max(0, $be_achievement_pct)); ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Interactive Visual Charts -->
    <div class="be-charts-grid">
        <div class="be-chart-card">
            <div class="be-chart-title"><i class="fas fa-line-chart"></i> Monthly Sales vs Break-Even Target</div>
            <canvas id="chartSalesVsBE" height="230"></canvas>
        </div>

        <div class="be-chart-card">
            <div class="be-chart-title"><i class="fas fa-chart-bar"></i> Monthly Sales vs Net Profit Trend</div>
            <canvas id="chartSalesVsProfit" height="230"></canvas>
        </div>
    </div>

    <!-- Operating Expense Breakdown & Income Statement Detail -->
    <div class="be-section">
        <div class="be-section-title">
            <span><i class="fas fa-list-alt"></i> Operating Expense Breakdown (Fixed Costs Used in Break-Even)</span>
            <a href="?page=reports/financial/general_ledger&account_type=expense" class="ns-btn no-print" style="font-size: 12px;"><i class="fas fa-external-link-alt"></i> View Expense Ledger</a>
        </div>
        
        <?php if (!empty($operating_expenses_list)): ?>
            <table class="be-table" id="be-export-tbl">
                <thead>
                    <tr>
                        <th>Operating Expense Category</th>
                        <th style="text-align: right;">Amount (NPR)</th>
                        <th style="text-align: right;">% of Total Fixed Opex</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operating_expenses_list as $oe): 
                        $oe_pct = $total_operating_expenses > 0 ? ($oe['amount'] / $total_operating_expenses) * 100 : 0;
                    ?>
                        <tr>
                            <td>
                                <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($oe['id']); ?>" style="color: inherit; font-weight: 600; text-decoration: none;">
                                    <?php echo htmlspecialchars($oe['account_name']); ?>
                                </a>
                            </td>
                            <td style="text-align: right; font-weight: 700;"><?php echo rpt_currency($oe['amount']); ?></td>
                            <td style="text-align: right; font-weight: 600; color: #64748b;"><?php echo number_format($oe_pct, 1); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f8fafc; font-weight: 800;">
                        <td style="text-transform: uppercase;">Total Fixed Operating Expenses</td>
                        <td style="text-align: right; color: #003087; font-size: 14px;"><?php echo rpt_currency($total_operating_expenses); ?></td>
                        <td style="text-align: right;">100.0%</td>
                    </tr>
                </tfoot>
            </table>
        <?php else: ?>
            <div style="padding: 20px; text-align: center; color: #64748b; font-style: italic;">No operating expenses recorded for the selected period.</div>
        <?php endif; ?>
    </div>

    <!-- Financial Position Reference Section (Dynamic Accounts from COA — Information Only) -->
    <div class="be-section" style="background: #fafafa; border-color: #cbd5e1;">
        <div class="be-section-title" style="border-bottom-color: #cbd5e1;">
            <span><i class="fas fa-balance-scale"></i> Financial Position Reference (Chart of Accounts — Display Only)</span>
            <span style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: none;"><i class="fas fa-info-circle"></i> Excluded from Break-Even math as per accounting standard</span>
        </div>

        <div class="be-fin-grid">
            <!-- Active Asset Accounts from COA -->
            <div class="be-fin-col">
                <div class="be-fin-head"><i class="fas fa-wallet"></i> Active Asset Accounts</div>
                <?php if (!empty($asset_accounts_coa)): ?>
                    <?php foreach ($asset_accounts_coa as $ac): ?>
                        <div class="be-fin-row">
                            <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($ac['id']); ?>" class="be-fin-label">
                                <?php echo htmlspecialchars($ac['account_name']); ?>
                            </a>
                            <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($ac['id']); ?>" class="be-fin-val">
                                <?php echo rpt_currency($ac['balance']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="font-size: 12px; color: #94a3b8; padding: 6px 0;">No active asset account transactions</div>
                <?php endif; ?>
            </div>

            <!-- Active Liability Accounts from COA -->
            <div class="be-fin-col">
                <div class="be-fin-head"><i class="fas fa-file-invoice-dollar"></i> Active Liability Accounts</div>
                <?php if (!empty($liability_accounts_coa)): ?>
                    <?php foreach ($liability_accounts_coa as $lc): ?>
                        <div class="be-fin-row">
                            <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($lc['id']); ?>" class="be-fin-label">
                                <?php echo htmlspecialchars($lc['account_name']); ?>
                            </a>
                            <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($lc['id']); ?>" class="be-fin-val" style="color: #dc2626;">
                                <?php echo rpt_currency($lc['balance']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="font-size: 12px; color: #94a3b8; padding: 6px 0;">No active liability account transactions</div>
                <?php endif; ?>
            </div>

            <!-- Active Equity Accounts from COA -->
            <div class="be-fin-col">
                <div class="be-fin-head"><i class="fas fa-building"></i> Owner Equity & Capital</div>
                <?php if (!empty($equity_accounts_coa)): ?>
                    <?php foreach ($equity_accounts_coa as $ec): ?>
                        <div class="be-fin-row">
                            <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($ec['id']); ?>" class="be-fin-label">
                                <?php echo htmlspecialchars($ec['account_name']); ?>
                            </a>
                            <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($ec['id']); ?>" class="be-fin-val">
                                <?php echo rpt_currency($ec['balance']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="be-fin-row" style="background: #fff; padding: 8px 10px; border-radius: 6px; margin-top: 8px;">
                    <span class="be-fin-label" style="font-weight: 700;">Current Net Income</span>
                    <span class="be-fin-val" style="color: <?php echo $net_profit >= 0 ? '#10b981' : '#dc2626'; ?>; font-size: 14px; font-weight: 800;">
                        <?php echo rpt_currency($net_profit); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const months  = <?php echo json_encode($chart_months); ?>;
    const sales   = <?php echo json_encode($chart_sales); ?>;
    const profit  = <?php echo json_encode($chart_profit); ?>;
    const beData  = <?php echo json_encode($chart_be); ?>;

    // 1. Chart: Monthly Sales vs Break-Even Target
    const ctxBE = document.getElementById('chartSalesVsBE').getContext('2d');
    new Chart(ctxBE, {
        type: 'line',
        data: {
            labels: months,
            datasets: [
                {
                    label: 'Actual Sales',
                    data: sales,
                    borderColor: '#003087',
                    backgroundColor: 'rgba(0, 48, 135, 0.08)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Break-Even Threshold',
                    data: beData,
                    borderColor: '#ef4444',
                    borderWidth: 2,
                    borderDash: [6, 4],
                    fill: false,
                    tension: 0
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // 2. Chart: Monthly Sales vs Monthly Profit
    const ctxProfit = document.getElementById('chartSalesVsProfit').getContext('2d');
    new Chart(ctxProfit, {
        type: 'bar',
        data: {
            labels: months,
            datasets: [
                {
                    label: 'Monthly Net Sales',
                    data: sales,
                    backgroundColor: '#3b82f6',
                    borderRadius: 6
                },
                {
                    label: 'Monthly Net Profit',
                    data: profit,
                    backgroundColor: '#10b981',
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true } }
        }
    });
});
</script>
