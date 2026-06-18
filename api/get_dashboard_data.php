<?php
/**
 * DASHBOARD — Consolidated Enterprise ERP Dashboard API
 * Single source of truth, merging v1 + v3 + v4.
 * Features: batch queries, KPI caching, cash summary, reconciliation,
 * profit/loss items, adjustments, role-based access, audit logging.
 * Use ?nocache=1 to bypass the 30-second cache.
 *
 * Replaces: get_dashboard_data.php (v1), get_dashboard_v3.php, get_dashboard_v4.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
require_once '../database/DBConnection.php';

$db = db();
$user_id = $_SESSION['user_id'];
$role = strtolower($_SESSION['role'] ?? 'cashier');

// ─── Timing ──────────────────────────────────────────────────────
$timer_start = microtime(true);

// ─── Date Helpers ────────────────────────────────────────────────
$today       = date('Y-m-d');
$today_start = $today . ' 00:00:00';
$today_end   = $today . ' 23:59:59';
$yesterday   = date('Y-m-d', strtotime('-1 day'));
$yest_start  = $yesterday . ' 00:00:00';
$yest_end    = $yesterday . ' 23:59:59';
$week_ago    = date('Y-m-d', strtotime('-6 days'));
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$last_m_start= date('Y-m-01', strtotime('first day of last month'));
$last_m_end  = date('Y-m-t', strtotime('last day of last month'));
$six_m_ago   = date('Y-m-d', strtotime('-5 months first day of this month'));
$year_start  = date('Y-01-01');
$now         = date('Y-m-d H:i:s');

// Fiscal Year (Nepali: July 16 to July 15)
$m  = (int)date('n'); $d  = (int)date('j'); $y  = (int)date('Y');
if ($m > 7 || ($m == 7 && $d >= 16)) {
    $fy_start = "{$y}-07-16";
    $fy_end   = ($y+1) . "-07-15";
    $fy_label = "FY " . substr($y,2) . "/" . substr($y+1,2);
} else {
    $fy_start = ($y-1) . "-07-16";
    $fy_end   = "{$y}-07-15";
    $fy_label = "FY " . substr($y-1,2) . "/" . substr($y,2);
}

// Get default cash account from system_info
$default_cash = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_cash_account'");
$default_cash_acct = $default_cash ? $default_cash['meta_value'] : 'acc-1010';

// ─── Helpers ─────────────────────────────────────────────────────
function make_kpi($val, $prev) {
    $val  = (float)($val ?? 0);
    $prev = (float)($prev ?? 0);
    $diff = $val - $prev;
    $chg  = $prev != 0 ? ($diff / $prev) * 100 : ($val > 0 ? 100 : 0);
    return [
        'value'      => $val,
        'previous'   => $prev,
        'change_pct' => round($chg, 1),
        'trend'      => $chg > 0 ? 'up' : ($chg < 0 ? 'down' : 'neutral'),
        'formatted'  => fmt_np($val)
    ];
}
function fmt_np($n) {
    $n = (float)$n;
    return 'Rs ' . number_format($n, 2);
}
function fmt_num($n) { return number_format((float)$n, 2); }
function cache_get($key) {
    global $db;
    try {
        $row = $db->fetchOne(
            "SELECT cache_value FROM dashboard_kpi_cache WHERE cache_key = ? AND expires_at > NOW()",
            [$key]
        );
        return $row ? json_decode($row['cache_value'], true) : null;
    } catch (Exception $e) {
        return null; // Table may not exist yet
    }
}
function cache_set($key, $value, $ttl_sec = 60) {
    global $db;
    try {
        $data = json_encode($value);
        $expires = date('Y-m-d H:i:s', time() + $ttl_sec);
        $db->execute(
            "REPLACE INTO dashboard_kpi_cache (cache_key, cache_value, expires_at) VALUES (?, ?, ?)",
            [$key, $data, $expires]
        );
    } catch (Exception $e) {
        // Silently fail if table doesn't exist
    }
}

// ─── Try Cache ───────────────────────────────────────────────────
$cache_key = "dash_v4_{$role}_{$user_id}";
$cached = cache_get($cache_key);
if ($cached && !isset($_GET['nocache'])) {
    $cached['cached'] = true;
    $cached['query_time_ms'] = round((microtime(true) - $timer_start) * 1000, 1);
    echo json_encode($cached);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// 1. KPI TILES — Batch Optimized Queries
// ══════════════════════════════════════════════════════════════════

// ── 1a. Today's Sales (customer_invoices only — matches Sales Register) ──
$sales_today = (float)($db->fetchOne("
    SELECT COALESCE(SUM(ci.total_amount), 0) as total FROM customer_invoices ci
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$today])['total'] ?? 0);
$sales_yest = (float)($db->fetchOne("
    SELECT COALESCE(SUM(ci.total_amount), 0) as total FROM customer_invoices ci
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$yesterday])['total'] ?? 0);

// ── 1b. Today's Gross Profit (POS items + non-POS invoice lines, no double-count) ──
$profit_today = (float)($db->fetchOne("
    SELECT COALESCE(SUM(profit),0) as profit FROM (
        SELECT SUM(pi.net_amount - (pi.quantity * i.cost_price)) as profit
        FROM pos_items pi JOIN items i ON pi.item_id = i.id
        JOIN pos_entry p ON pi.pos_id = p.id
        WHERE DATE(p.date_time) = ? AND p.is_deleted = 0
        UNION ALL
        SELECT SUM(l.gross_profit) FROM transaction_lines l
        JOIN transaction_headers h ON l.header_id = h.id
        WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
          AND h.txn_type = 'customer_invoice'
          AND h.txn_number NOT LIKE 'POS-SUM-%'
    ) t", [$today, $today])['profit'] ?? 0);
$profit_yest = (float)($db->fetchOne("
    SELECT COALESCE(SUM(profit),0) as profit FROM (
        SELECT SUM(pi.net_amount - (pi.quantity * i.cost_price)) as profit
        FROM pos_items pi JOIN items i ON pi.item_id = i.id
        JOIN pos_entry p ON pi.pos_id = p.id
        WHERE DATE(p.date_time) = ? AND p.is_deleted = 0
        UNION ALL
        SELECT SUM(l.gross_profit) FROM transaction_lines l
        JOIN transaction_headers h ON l.header_id = h.id
        WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
          AND h.txn_type = 'customer_invoice'
          AND h.txn_number NOT LIKE 'POS-SUM-%'
    ) t", [$yesterday, $yesterday])['profit'] ?? 0);

// ── 1c. Cash / Bank / AR / AP — Single batch query ──
function get_balances($db, $as_of) {
    $rows = $db->fetchAll("
        SELECT 
            CASE 
                WHEN a.account_subtype = 'cash' OR a.account_code = '1010' THEN 'cash'
                WHEN a.account_subtype = 'bank' THEN 'bank'
                WHEN a.account_subtype = 'receivable' THEN 'ar'
                WHEN a.account_subtype = 'payable' THEN 'ap'
            END as bt,
            COALESCE(SUM(CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE -je.amount END), 0) as bal
        FROM accounts a
        LEFT JOIN journal_entries je ON je.account_id = a.id AND je.entry_date <= ?
        LEFT JOIN transaction_headers h ON je.header_id = h.id AND h.is_deleted = 0 AND h.status != 'voided'
        WHERE (a.account_subtype IN ('cash','bank','receivable','payable') OR a.account_code='1010')
          AND a.is_active = 1 AND a.is_deleted = 0
        GROUP BY bt HAVING bt IS NOT NULL
    ", [$as_of]);
    $r = ['cash'=>0,'bank'=>0,'ar'=>0,'ap'=>0];
    foreach ($rows as $row) { $r[$row['bt']] = (float)$row['bal']; }
    $r['ap'] = abs($r['ap']);
    return $r;
}
$bal_today = get_balances($db, $today);
$bal_yest  = get_balances($db, $yesterday);

// ── 1d. Inventory Value + Counts ──
$inv_stats = $db->fetchOne("
    SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_items,
        SUM(CASE WHEN is_active = 1 AND current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN is_active = 1 AND reorder_level IS NOT NULL AND current_stock <= reorder_level THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN is_active = 1 AND current_stock < 0 THEN 1 ELSE 0 END) as negative_stock,
        SUM(CASE WHEN is_active = 1 AND reorder_level IS NOT NULL AND current_stock > (reorder_level * 3) THEN 1 ELSE 0 END) as overstock,
        COALESCE(SUM(current_stock * cost_price), 0) as inventory_value
    FROM items WHERE is_deleted = 0
");

// ══════════════════════════════════════════════════════════════════
// CASH SUMMARY TILE — Opening, Cash In, Cash Out, Closing
// ══════════════════════════════════════════════════════════════════

// Opening Cash from cash_denominations (morning shift = opening type)
$opening_cash = (float)($db->fetchOne("
    SELECT COALESCE(total_cash, 0) as total_cash FROM cash_denominations cd
    JOIN transaction_headers h ON cd.header_id = h.id
    WHERE cd.denomination_date = ? AND cd.denomination_type = 'opening' AND h.is_deleted = 0
    ORDER BY cd.id ASC LIMIT 1
", [$today])['total_cash'] ?? 0);

// Closing Cash from cash_denominations (evening shift = closing type, latest)
$closing_cash = (float)($db->fetchOne("
    SELECT COALESCE(total_cash, 0) as total_cash FROM cash_denominations cd
    JOIN transaction_headers h ON cd.header_id = h.id
    WHERE cd.denomination_date = ? AND cd.denomination_type = 'closing' AND h.is_deleted = 0
    ORDER BY cd.id DESC LIMIT 1
", [$today])['total_cash'] ?? 0);

// If no closing found, try the last entry of any type for today
if ($closing_cash <= 0) {
    $closing_cash = (float)($db->fetchOne("
        SELECT COALESCE(total_cash, 0) as total_cash FROM cash_denominations cd
        JOIN transaction_headers h ON cd.header_id = h.id
        WHERE cd.denomination_date = ? AND h.is_deleted = 0
        ORDER BY cd.id DESC LIMIT 1
    ", [$today])['total_cash'] ?? 0);
}

// Total Cash In (debits to cash account today from journal entries)
// Note: POS cash payments are already included in journal entries, so we don't double-count
$cash_in = (float)($db->fetchOne("
    SELECT COALESCE(SUM(je.amount), 0) as amount FROM journal_entries je
    JOIN transaction_headers h ON je.header_id = h.id
    WHERE je.account_id = ? AND je.entry_type = 'debit'
      AND je.entry_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$default_cash_acct, $today])['amount'] ?? 0);

// Total Cash Out (credits to cash account today from journal entries)
$cash_out = (float)($db->fetchOne("
    SELECT COALESCE(SUM(je.amount), 0) as amount FROM journal_entries je
    JOIN transaction_headers h ON je.header_id = h.id
    WHERE je.account_id = ? AND je.entry_type = 'credit'
      AND je.entry_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$default_cash_acct, $today])['amount'] ?? 0);

// Cash difference (closing - opening - in + out = surplus/shortage)
$expected_closing = $opening_cash + $cash_in - $cash_out;
$cash_diff = $closing_cash - $expected_closing;

// --- New calculations for user requested comparisons ---
// 1. Daily Expenses (Today vs Yesterday)
$expenses_today = (float)($db->fetchOne("
    SELECT COALESCE(SUM(e.amount), 0) as total 
    FROM expenses e JOIN transaction_headers h ON e.header_id = h.id
    WHERE e.expense_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$today])['total'] ?? 0);

$expenses_yest = (float)($db->fetchOne("
    SELECT COALESCE(SUM(e.amount), 0) as total 
    FROM expenses e JOIN transaction_headers h ON e.header_id = h.id
    WHERE e.expense_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$yesterday])['total'] ?? 0);

// 2. Bank Flow Today (Net inflow/outflow today vs yesterday)
$bank_inflow_today = (float)($db->fetchOne("
    SELECT COALESCE(SUM(je.amount), 0) as amount 
    FROM journal_entries je JOIN accounts a ON je.account_id = a.id
    JOIN transaction_headers h ON je.header_id = h.id
    WHERE a.account_subtype = 'bank' AND je.entry_type = 'debit'
      AND je.entry_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$today])['amount'] ?? 0);

$bank_outflow_today = (float)($db->fetchOne("
    SELECT COALESCE(SUM(je.amount), 0) as amount 
    FROM journal_entries je JOIN accounts a ON je.account_id = a.id
    JOIN transaction_headers h ON je.header_id = h.id
    WHERE a.account_subtype = 'bank' AND je.entry_type = 'credit'
      AND je.entry_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$today])['amount'] ?? 0);

$bank_flow_today = $bank_inflow_today - $bank_outflow_today;

$bank_inflow_yest = (float)($db->fetchOne("
    SELECT COALESCE(SUM(je.amount), 0) as amount 
    FROM journal_entries je JOIN accounts a ON je.account_id = a.id
    JOIN transaction_headers h ON je.header_id = h.id
    WHERE a.account_subtype = 'bank' AND je.entry_type = 'debit'
      AND je.entry_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$yesterday])['amount'] ?? 0);

$bank_outflow_yest = (float)($db->fetchOne("
    SELECT COALESCE(SUM(je.amount), 0) as amount 
    FROM journal_entries je JOIN accounts a ON je.account_id = a.id
    JOIN transaction_headers h ON je.header_id = h.id
    WHERE a.account_subtype = 'bank' AND je.entry_type = 'credit'
      AND je.entry_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$yesterday])['amount'] ?? 0);

$bank_flow_yest = $bank_inflow_yest - $bank_outflow_yest;

// ══════════════════════════════════════════════════════════════════
// 2. SALES ANALYTICS
// ══════════════════════════════════════════════════════════════════

// ── 2a. Sales Trend (7-day default) ──
$sales_range = $_GET['sales_range'] ?? '7days';
$trend_data = ['labels' => [], 'values' => []];

switch ($sales_range) {
    case 'today': // Hourly (invoices only)
        $inv_hourly = $db->fetchAll("
            SELECT HOUR(h.created_at) as hr, COALESCE(SUM(ci.total_amount), 0) as total
            FROM customer_invoices ci JOIN transaction_headers h ON ci.header_id = h.id
            WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
            GROUP BY HOUR(h.created_at) ORDER BY hr
        ", [$today]);
        $hr_map = [];
        foreach ($inv_hourly as $h) {
            $hr = (int)$h['hr'];
            $hr_map[$hr] = (float)$h['total'];
        }
        for ($i = 0; $i <= 23; $i++) {
            $label = $i == 0 ? '12AM' : ($i < 12 ? $i . 'AM' : ($i == 12 ? '12PM' : ($i - 12) . 'PM'));
            $trend_data['labels'][] = $label;
            $trend_data['values'][] = $hr_map[$i] ?? 0;
        }
        break;

    case 'thismonth':
        $from = $month_start; $to = $today;
        $daily = get_daily_sales($db, $from, $to);
        $num_days = (int)date('j');
        for ($i = $num_days - 1; $i >= 0; $i--) {
            $dt = date('Y-m-d', strtotime("-$i days"));
            $trend_data['labels'][] = date('d M', strtotime($dt));
            $trend_data['values'][] = $daily[$dt] ?? 0;
        }
        break;

    case '30days':
        $from = date('Y-m-d', strtotime('-29 days')); $to = $today;
        $daily = get_daily_sales($db, $from, $to);
        for ($i = 29; $i >= 0; $i--) {
            $dt = date('Y-m-d', strtotime("-$i days"));
            $trend_data['labels'][] = date('d M', strtotime($dt));
            $trend_data['values'][] = $daily[$dt] ?? 0;
        }
        break;

    case 'thisyear':
        $from = $year_start; $to = $today;
        $daily = get_daily_sales($db, $from, $to);
        $monthly = [];
        foreach ($daily as $dt => $val) {
            $ym = substr($dt, 0, 7);
            $monthly[$ym] = ($monthly[$ym] ?? 0) + $val;
        }
        $current_m = (int)date('m');
        for ($i = 1; $i <= $current_m; $i++) {
            $ym = date('Y-m', strtotime(date('Y') . '-' . str_pad($i, 2, '0', STR_PAD_LEFT) . '-01'));
            $trend_data['labels'][] = date('M Y', strtotime($ym . '-01'));
            $trend_data['values'][] = $monthly[$ym] ?? 0;
        }
        break;

    default: // 7days
        $from = $week_ago; $to = $today;
        $daily = get_daily_sales($db, $from, $to);
        for ($i = 6; $i >= 0; $i--) {
            $dt = date('Y-m-d', strtotime("-$i days"));
            $trend_data['labels'][] = date('D, d M', strtotime($dt));
            $trend_data['values'][] = $daily[$dt] ?? 0;
        }
}

function get_daily_sales($db, $from, $to) {
    $rows = $db->fetchAll("
        SELECT h.txn_date as dt, SUM(ci.total_amount) as total
        FROM customer_invoices ci JOIN transaction_headers h ON ci.header_id = h.id
        WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
        GROUP BY h.txn_date ORDER BY h.txn_date
    ", [$from, $to]);
    $map = [];
    foreach ($rows as $r) $map[$r['dt']] = (float)$r['total'];
    return $map;
}

// ── 2b. Sales by Payment Method ──
$pay_methods = ['Cash' => 0, 'Card' => 0, 'eSewa' => 0, 'Khalti' => 0, 'Bank Transfer' => 0, 'Credit' => 0];

// POS payments today
$pp = $db->fetchAll("
    SELECT pp.payment_mode FROM pos_payments pp
    JOIN pos_entry pe ON pp.pos_id = pe.id
    WHERE DATE(pe.date_time) = ? AND pe.is_deleted = 0
", [$today]);
$pp_total = $db->fetchAll("
    SELECT pp.payment_mode, a.account_name, SUM(pp.amount) as total
    FROM pos_payments pp JOIN accounts a ON pp.account_id = a.id
    JOIN pos_entry pe ON pp.pos_id = pe.id
    WHERE DATE(pe.date_time) = ? AND pe.is_deleted = 0
    GROUP BY pp.payment_mode, a.account_name
", [$today]);
foreach ($pp_total as $p) {
    $amt = (float)$p['total'];
    $mode = strtolower($p['payment_mode']);
    $name = strtolower($p['account_name'] ?? '');
    if ($mode === 'cash') $pay_methods['Cash'] += $amt;
    elseif ($mode === 'card') $pay_methods['Card'] += $amt;
    elseif ($mode === 'qr' && strpos($name, 'esewa') !== false) $pay_methods['eSewa'] += $amt;
    elseif ($mode === 'qr' && strpos($name, 'khalti') !== false) $pay_methods['Khalti'] += $amt;
    elseif ($mode === 'bank') $pay_methods['Bank Transfer'] += $amt;
    elseif ($mode === 'qr') $pay_methods['eSewa'] += $amt; // default QR
    else $pay_methods['Card'] += $amt;
}
// Invoice payments today
$ip = $db->fetchAll("
    SELECT p.payment_method, SUM(p.amount) as total
    FROM payments p JOIN transaction_headers h ON p.header_id = h.id
    WHERE p.payment_date = ? AND p.payment_type = 'customer_payment' 
      AND h.is_deleted = 0 AND h.status != 'voided'
    GROUP BY p.payment_method
", [$today]);
foreach ($ip as $p) {
    $amt = (float)$p['total'];
    switch ($p['payment_method']) {
        case 'cash': $pay_methods['Cash'] += $amt; break;
        case 'card': $pay_methods['Card'] += $amt; break;
        case 'esewa': $pay_methods['eSewa'] += $amt; break;
        case 'khalti': $pay_methods['Khalti'] += $amt; break;
        case 'bank_transfer': case 'cheque': $pay_methods['Bank Transfer'] += $amt; break;
    }
}
// Credit sales today
$credit_today = (float)($db->fetchOne("
    SELECT COALESCE(SUM(ci.balance_due), 0) as balance_due FROM customer_invoices ci
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE h.txn_date = ? AND ci.sale_type = 'credit' AND h.is_deleted = 0 AND h.status != 'voided'
", [$today])['balance_due'] ?? 0);
$pay_methods['Credit'] = $credit_today;

// ── 2c. Hourly Sales (now part of trend) ──
// We already have the hourly in trend for 'today' range

// ══════════════════════════════════════════════════════════════════
// 3. INVENTORY DASHBOARD
// ══════════════════════════════════════════════════════════════════

// ── 3a. Top Selling Items (this month) ──
$top_selling = $db->fetchAll("
    SELECT i.sku, i.item_name, i.current_stock,
           SUM(s.qty) as total_qty, SUM(s.total_sales) as total_amount
    FROM (
        SELECT pi.item_id, SUM(pi.quantity) as qty, SUM(pi.net_amount) as total_sales
        FROM pos_items pi JOIN pos_entry pe ON pi.pos_id = pe.id
        WHERE DATE(pe.date_time) BETWEEN ? AND ? AND pe.is_deleted = 0
        GROUP BY pi.item_id
        UNION ALL
        SELECT tl.item_id, SUM(tl.quantity) as qty, SUM(tl.line_total) as total_sales
        FROM transaction_lines tl JOIN transaction_headers h ON tl.header_id = h.id
        WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
          AND h.txn_type = 'customer_invoice'
        GROUP BY tl.item_id
    ) s JOIN items i ON s.item_id = i.id
    GROUP BY s.item_id ORDER BY total_amount DESC LIMIT 10
", [$month_start, $month_end, $month_start, $month_end]);

// ── 3b. Slow Moving Items ──
$slow_30 = $db->fetchAll("
    SELECT i.id, i.sku, i.item_name, i.current_stock, i.cost_price,
           DATEDIFF(?, COALESCE(ls.last_sale, '2000-01-01')) as days_inactive
    FROM items i LEFT JOIN (
        SELECT item_id, MAX(sale_date) as last_sale FROM (
            SELECT pi.item_id, MAX(DATE(pe.date_time)) as sale_date
            FROM pos_items pi JOIN pos_entry pe ON pi.pos_id = pe.id WHERE pe.is_deleted = 0 GROUP BY pi.item_id
            UNION ALL
            SELECT tl.item_id, MAX(h.txn_date) FROM transaction_lines tl
            JOIN transaction_headers h ON tl.header_id = h.id
            WHERE h.is_deleted = 0 AND h.status != 'voided' AND h.txn_type = 'customer_invoice' GROUP BY tl.item_id
        ) c GROUP BY item_id
    ) ls ON i.id = ls.item_id
    WHERE i.is_deleted = 0 AND i.is_active = 1
    HAVING days_inactive >= 30 ORDER BY days_inactive DESC LIMIT 15
", [$today]);

// ── 3c. Stock Alerts ──
$reorder_items = $db->fetchAll("
    SELECT id, sku, item_name, current_stock, reorder_level
    FROM items WHERE is_deleted = 0 AND is_active = 1 AND reorder_level IS NOT NULL AND current_stock <= reorder_level
    ORDER BY current_stock ASC LIMIT 5
");
$neg_stock_items = $db->fetchAll("
    SELECT id, sku, item_name, current_stock
    FROM items WHERE is_deleted = 0 AND is_active = 1 AND current_stock < 0
    ORDER BY current_stock ASC LIMIT 5
");

// ══════════════════════════════════════════════════════════════════
// 4. FINANCIAL OVERVIEW
// ══════════════════════════════════════════════════════════════════

// ── 4a. Gross Profit Trend (6 months) ──
$monthly_gp = $db->fetchAll("
    SELECT h.fiscal_period as period, SUM(l.gross_profit) as profit
    FROM transaction_lines l JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_date >= ? AND h.is_deleted = 0 AND h.status != 'voided' AND h.txn_type = 'customer_invoice'
    GROUP BY h.fiscal_period ORDER BY period
", [$six_m_ago]);
$gp_map = []; foreach ($monthly_gp as $g) { $gp_map[$g['period']] = (float)$g['profit']; }
$gp_labels = []; $gp_values = [];
for ($i = 5; $i >= 0; $i--) {
    $p = date('Y-m', strtotime("-$i months"));
    $gp_labels[] = date('M Y', strtotime("-$i months"));
    $gp_values[] = $gp_map[$p] ?? 0;
}

// ── 4b. Expenses Breakdown ──
$expenses = $db->fetchAll("
    SELECT e.expense_category, SUM(e.amount) as total
    FROM expenses e JOIN transaction_headers h ON e.header_id = h.id
    WHERE e.expense_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
    GROUP BY e.expense_category
", [$month_start, $month_end]);
$exp_labels = []; $exp_values = [];
foreach ($expenses as $e) {
    $exp_labels[] = ucfirst($e['expense_category']);
    $exp_values[] = (float)$e['total'];
}

// ── 4c. VAT Summary ──
$vat_sales_row = $db->fetchOne("
    SELECT COALESCE(SUM(ci.subtotal), 0) as taxable, COALESCE(SUM(ci.tax_amount), 0) as collected
    FROM customer_invoices ci JOIN transaction_headers h ON ci.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$month_start, $month_end]);
$vat_purch_row = $db->fetchOne("
    SELECT COALESCE(SUM(vb.tax_amount), 0) as paid
    FROM vendor_bills vb JOIN transaction_headers h ON vb.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$month_start, $month_end]);

// ── 4d. Monthly Comparatives ──
$m_sales = (float)($db->fetchOne("
    SELECT COALESCE(SUM(ci.total_amount), 0) as total FROM customer_invoices ci
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$month_start, $month_end])['total'] ?? 0);
$m_sales_last = (float)($db->fetchOne("
    SELECT COALESCE(SUM(ci.total_amount), 0) as total FROM customer_invoices ci
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$last_m_start, $last_m_end])['total'] ?? 0);
$m_purch = (float)($db->fetchOne("
    SELECT COALESCE(SUM(vb.total_amount), 0) as total FROM vendor_bills vb
    JOIN transaction_headers h ON vb.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$month_start, $month_end])['total'] ?? 0);
$m_purch_last = (float)($db->fetchOne("
    SELECT COALESCE(SUM(vb.total_amount), 0) as total FROM vendor_bills vb
    JOIN transaction_headers h ON vb.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$last_m_start, $last_m_end])['total'] ?? 0);

$m_expenses = (float)($db->fetchOne("
    SELECT COALESCE(SUM(e.amount), 0) as total FROM expenses e
    JOIN transaction_headers h ON e.header_id = h.id
    WHERE e.expense_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$month_start, $month_end])['total'] ?? 0);
$m_expenses_last = (float)($db->fetchOne("
    SELECT COALESCE(SUM(e.amount), 0) as total FROM expenses e
    JOIN transaction_headers h ON e.header_id = h.id
    WHERE e.expense_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$last_m_start, $last_m_end])['total'] ?? 0);

$m_profit = (float)($db->fetchOne("
    SELECT COALESCE(SUM(l.gross_profit), 0) as profit FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided' AND h.txn_type = 'customer_invoice'
", [$month_start, $month_end])['profit'] ?? 0);
$m_profit_last = (float)($db->fetchOne("
    SELECT COALESCE(SUM(l.gross_profit), 0) as profit FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided' AND h.txn_type = 'customer_invoice'
", [$last_m_start, $last_m_end])['profit'] ?? 0);

$fy_sales = (float)($db->fetchOne("
    SELECT COALESCE(SUM(ci.total_amount), 0) as total FROM customer_invoices ci
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$fy_start, $today])['total'] ?? 0);
$fy_profit = (float)($db->fetchOne("
    SELECT COALESCE(SUM(l.gross_profit), 0) as profit FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided' AND h.txn_type = 'customer_invoice'
", [$fy_start, $today])['profit'] ?? 0);

// ══════════════════════════════════════════════════════════════════
// 5. CUSTOMER & SUPPLIER INSIGHTS
// ══════════════════════════════════════════════════════════════════

$cust_total   = (int)($db->fetchOne("SELECT COUNT(*) FROM customers WHERE is_deleted = 0 AND is_active = 1")['count'] ?? 0);
$cust_new     = (int)($db->fetchOne("SELECT COUNT(*) FROM customers WHERE is_deleted = 0 AND is_active = 1 AND created_at >= ?", [$month_start])['count'] ?? 0);
$supp_total   = (int)($db->fetchOne("SELECT COUNT(*) FROM vendors WHERE is_deleted = 0 AND is_active = 1")['count'] ?? 0);

$top_cust = $db->fetchAll("
    SELECT c.full_name, SUM(ci.total_amount) as total_sales
    FROM customer_invoices ci JOIN customers c ON ci.customer_id = c.id
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'voided'
    GROUP BY ci.customer_id ORDER BY total_sales DESC LIMIT 5
", [$month_start, $month_end]);

$out_ar = $db->fetchAll("
    SELECT c.full_name, c.phone, c.customer_type,
           COALESCE(SUM(CASE WHEN je.entry_type='debit' THEN je.amount ELSE -je.amount END), 0) as balance,
           MAX(je.entry_date) as last_txn
    FROM customers c JOIN journal_entries je ON je.account_id = c.receivable_account_id
    JOIN transaction_headers h ON je.header_id = h.id
    WHERE c.is_deleted = 0 AND c.is_active = 1 AND h.is_deleted = 0 AND h.status != 'voided'
    GROUP BY c.id HAVING balance > 0 ORDER BY balance DESC LIMIT 10
");

$out_ap = $db->fetchAll("
    SELECT v.company_name, v.phone,
           COALESCE(SUM(CASE WHEN je.entry_type='credit' THEN je.amount ELSE -je.amount END), 0) as balance,
           MAX(je.entry_date) as last_txn
    FROM vendors v JOIN journal_entries je ON je.account_id = v.payable_account_id
    JOIN transaction_headers h ON je.header_id = h.id
    WHERE v.is_deleted = 0 AND v.is_active = 1 AND h.is_deleted = 0 AND h.status != 'voided'
    GROUP BY v.id HAVING balance > 0 ORDER BY balance DESC LIMIT 10
");

// Bills due this week
$bills_due = $db->fetchAll("
    SELECT vb.id, vb.vendor_invoice_number, v.company_name, vb.balance_due, vb.due_date
    FROM vendor_bills vb JOIN vendors v ON vb.vendor_id = v.id
    JOIN transaction_headers h ON vb.header_id = h.id
    WHERE vb.balance_due > 0 AND vb.due_date BETWEEN ? AND ? AND h.is_deleted = 0
    ORDER BY vb.due_date ASC LIMIT 5
", [$today, date('Y-m-d', strtotime('+7 days'))]);

// Recent purchases
$recent_purch = $db->fetchAll("
    SELECT h.txn_number, v.company_name, vb.total_amount, h.txn_date
    FROM transaction_headers h JOIN vendor_bills vb ON h.id = vb.header_id
    JOIN vendors v ON vb.vendor_id = v.id
    WHERE h.is_deleted = 0 AND h.status != 'voided'
    ORDER BY h.txn_date DESC LIMIT 5
");

// ══════════════════════════════════════════════════════════════════
// 6. OPERATIONAL ALERTS
// ══════════════════════════════════════════════════════════════════

$alerts = [];

// Low Stock
foreach ($reorder_items as $i) {
    $alerts[] = [
        'type' => 'low_stock', 'severity' => 'warning',
        'icon' => 'fa-boxes', 'icon_bg' => '#f59e0b',
        'title' => "Low Stock: {$i['item_name']}",
        'desc' => "SKU: {$i['sku']} — Current: " . (float)$i['current_stock'] . ", Reorder: {$i['reorder_level']}",
        'link' => '?page=master/item'
    ];
}

// Negative Stock
foreach ($neg_stock_items as $i) {
    $alerts[] = [
        'type' => 'negative_stock', 'severity' => 'critical',
        'icon' => 'fa-exclamation-circle', 'icon_bg' => '#ef4444',
        'title' => "Negative Stock: {$i['item_name']}",
        'desc' => "SKU: {$i['sku']} — Stock: " . (float)$i['current_stock'],
        'link' => '?page=master/item'
    ];
}

// Overdue Receivables
$overdue_ar = $db->fetchAll("
    SELECT ci.id, ci.invoice_number, ci.balance_due, c.full_name, ci.due_date
    FROM customer_invoices ci JOIN customers c ON ci.customer_id = c.id
    JOIN transaction_headers h ON ci.header_id = h.id
    WHERE ci.balance_due > 0 AND ci.due_date < ? AND h.is_deleted = 0
    ORDER BY ci.due_date ASC LIMIT 5
", [$today]);
foreach ($overdue_ar as $a) {
    $days = (int)((time() - strtotime($a['due_date'])) / 86400);
    $alerts[] = [
        'type' => 'overdue_receivable', 'severity' => 'danger',
        'icon' => 'fa-hand-holding-usd', 'icon_bg' => '#dc2626',
        'title' => "Overdue: {$a['full_name']}",
        'desc' => "Invoice {$a['invoice_number']} — Rs " . number_format($a['balance_due'], 2) . " ({$days}d overdue)",
        'link' => '?page=transactions/invoice&id=' . $a['id']
    ];
}

// Overdue Payables
$overdue_ap = $db->fetchAll("
    SELECT vb.id, vb.vendor_invoice_number, vb.balance_due, v.company_name, vb.due_date
    FROM vendor_bills vb JOIN vendors v ON vb.vendor_id = v.id
    JOIN transaction_headers h ON vb.header_id = h.id
    WHERE vb.balance_due > 0 AND vb.due_date < ? AND h.is_deleted = 0
    ORDER BY vb.due_date ASC LIMIT 5
", [$today]);
foreach ($overdue_ap as $a) {
    $days = (int)((time() - strtotime($a['due_date'])) / 86400);
    $alerts[] = [
        'type' => 'overdue_payable', 'severity' => 'danger',
        'icon' => 'fa-file-invoice', 'icon_bg' => '#dc2626',
        'title' => "Overdue Bill: {$a['company_name']}",
        'desc' => "Bill #{$a['vendor_invoice_number']} — Rs " . number_format($a['balance_due'], 2) . " ({$days}d overdue)",
        'link' => '?page=transactions/bill&id=' . $a['id']
    ];
}

// Pending Approvals
$pending = $db->fetchAll("
    SELECT id, txn_number, txn_type, status, created_at
    FROM transaction_headers WHERE status IN ('draft','approved') AND is_deleted = 0
    ORDER BY created_at DESC LIMIT 5
");
foreach ($pending as $p) {
    $alerts[] = [
        'type' => 'pending_approval', 'severity' => 'info',
        'icon' => 'fa-clock', 'icon_bg' => '#3b82f6',
        'title' => "Pending: {$p['txn_number']}",
        'desc' => ucwords(str_replace('_', ' ', $p['txn_type'])) . " — Status: {$p['status']}",
        'link' => '?page=transactions/' . $p['txn_type']
    ];
}

// Backup Warning
$bk_files = glob(dirname(__DIR__) . '/database/*.sql');
$bk_alert = true;
if (!empty($bk_files)) {
    usort($bk_files, fn($a, $b) => filemtime($b) - filemtime($a));
    $bk_alert = (time() - filemtime($bk_files[0]) > 86400 * 2);
}
if ($bk_alert) {
    $alerts[] = [
        'type' => 'backup', 'severity' => 'warning',
        'icon' => 'fa-database', 'icon_bg' => '#f59e0b',
        'title' => 'Backup Warning',
        'desc' => 'No database backup in the last 48 hours.',
        'link' => '?page=system/backup/manage'
    ];
}

// Cash Mismatch Today
$mis = $db->fetchAll("
    SELECT cd.id, h.txn_number, cd.difference
    FROM cash_denominations cd JOIN transaction_headers h ON cd.header_id = h.id
    WHERE h.txn_date = ? AND cd.difference != 0 AND h.is_deleted = 0
", [$today]);
foreach ($mis as $m) {
    $prefix = $m['difference'] > 0 ? 'Excess' : 'Shortage';
    $alerts[] = [
        'type' => 'cash_mismatch', 'severity' => 'warning',
        'icon' => 'fa-calculator', 'icon_bg' => '#f59e0b',
        'title' => "Cash Mismatch: {$m['txn_number']}",
        'desc' => "Rs " . number_format(abs($m['difference']), 2) . " {$prefix}",
        'link' => '?page=transactions/cash_denom'
    ];
}

// Cash Surplus/Shortage Alert
if (abs($cash_diff) > 0.01) {
    $prefix = $cash_diff > 0 ? 'Surplus' : 'Shortage';
    $alerts[] = [
        'type' => 'cash_balance', 'severity' => 'warning',
        'icon' => 'fa-coins', 'icon_bg' => '#f59e0b',
        'title' => "Cash {$prefix}",
        'desc' => "Expected closing: Rs " . number_format($expected_closing, 2) . ", Actual: Rs " . number_format($closing_cash, 2) . " ({$prefix}: Rs " . number_format(abs($cash_diff), 2) . ")",
        'link' => '?page=transactions/cash_denom'
    ];
}

// ══════════════════════════════════════════════════════════════════
// 7. RECENT ACTIVITIES (Unified Timeline)
// ══════════════════════════════════════════════════════════════════

$activities = [];

// POS Today
$ap = $db->fetchAll("
    SELECT pe.id, pe.invoice_no, pe.date_time, c.full_name as party, pe.net_amount
    FROM pos_entry pe LEFT JOIN customers c ON pe.customer_id = c.id
    WHERE DATE(pe.date_time) = ? AND pe.is_deleted = 0
", [$today]);
foreach ($ap as $r) {
    $activities[] = [
        'type' => 'POS Sale', 'ref' => $r['invoice_no'], 'id' => $r['id'],
        'time' => date('H:i', strtotime($r['date_time'])),
        'party' => $r['party'] ?? 'Walk-in', 'amount' => (float)$r['net_amount'],
        'status' => 'completed', 'link' => '?page=transactions/pos/view&id=' . $r['id']
    ];
}

// Invoices Today
$ai = $db->fetchAll("
    SELECT h.id, h.txn_number, h.created_at, c.full_name as party, ci.total_amount, h.status
    FROM transaction_headers h JOIN customer_invoices ci ON h.id = ci.header_id
    LEFT JOIN customers c ON ci.customer_id = c.id
    WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$today]);
foreach ($ai as $r) {
    $activities[] = [
        'type' => 'Invoice', 'ref' => $r['txn_number'], 'id' => $r['id'],
        'time' => date('H:i', strtotime($r['created_at'])),
        'party' => $r['party'] ?? 'Customer', 'amount' => (float)$r['total_amount'],
        'status' => $r['status'], 'link' => '?page=transactions/invoice/view&id=' . $r['id']
    ];
}

// Bills Today
$ab = $db->fetchAll("
    SELECT h.id, h.txn_number, h.created_at, v.company_name as party, vb.total_amount, h.status
    FROM transaction_headers h JOIN vendor_bills vb ON h.id = vb.header_id
    LEFT JOIN vendors v ON vb.vendor_id = v.id
    WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
", [$today]);
foreach ($ab as $r) {
    $activities[] = [
        'type' => 'Purchase', 'ref' => $r['txn_number'], 'id' => $r['id'],
        'time' => date('H:i', strtotime($r['created_at'])),
        'party' => $r['party'] ?? 'Vendor', 'amount' => (float)$r['total_amount'],
        'status' => $r['status'], 'link' => '?page=transactions/bill/view&id=' . $r['id']
    ];
}

// Payments Today
$apay = $db->fetchAll("
    SELECT h.id, h.txn_number, h.created_at, 
           COALESCE(c.full_name, v.company_name) as party,
           SUM(p.amount) as total, p.payment_type, h.status
    FROM transaction_headers h JOIN payments p ON h.id = p.header_id
    LEFT JOIN customers c ON p.customer_id = c.id
    LEFT JOIN vendors v ON p.vendor_id = v.id
    WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
    GROUP BY h.id
", [$today]);
foreach ($apay as $r) {
    $activities[] = [
        'type' => $r['payment_type'] === 'customer_payment' ? 'Payment In' : 'Payment Out',
        'ref' => $r['txn_number'], 'id' => $r['id'],
        'time' => date('H:i', strtotime($r['created_at'])),
        'party' => $r['party'] ?? 'Party', 'amount' => (float)$r['total'],
        'status' => $r['status'], 'link' => '?page=transactions/payment/view&id=' . $r['id']
    ];
}

// Journal Today
$aj = $db->fetchAll("
    SELECT h.id, h.txn_number, h.created_at, h.memo, h.net_amount, h.status
    FROM transaction_headers h
    WHERE h.txn_date = ? AND h.is_deleted = 0 AND h.txn_type = 'journal_entry' AND h.status != 'voided'
", [$today]);
foreach ($aj as $r) {
    $net = (float)($r['net_amount'] ?? 0);
    $activities[] = [
        'type' => 'Journal', 'ref' => $r['txn_number'], 'id' => $r['id'],
        'time' => date('H:i', strtotime($r['created_at'])),
        'party' => $r['memo'] ?? 'Journal Entry', 'amount' => $net,
        'status' => $r['status'], 'link' => '?page=transactions/journal/view&id=' . $r['id']
    ];
}

// Sort by time (most recent first)
usort($activities, fn($a, $b) => strtotime($b['time'] ? date('Y-m-d ' . $b['time']) : 'now') <=> strtotime($a['time'] ? date('Y-m-d ' . $a['time']) : 'now'));
$recent = array_slice($activities, 0, 20);

// ══════════════════════════════════════════════════════════════════
// USER PREFERENCES
// ══════════════════════════════════════════════════════════════════

$prefs = $db->fetchOne(
    "SELECT layout_data, filters_data FROM user_dashboard_preferences WHERE user_id = ?",
    [$user_id]
);

// ══════════════════════════════════════════════════════════════════
// BANK ACCOUNT DETAILS (for individual bank account tile)
// ══════════════════════════════════════════════════════════════════
$bank_accounts_list = $db->fetchAll("
    SELECT a.id, a.account_code, a.account_name, a.account_subtype
    FROM accounts a
    WHERE a.account_subtype IN ('bank', 'cash') AND a.is_active = 1 AND a.is_deleted = 0
    ORDER BY a.account_subtype ASC, a.account_name ASC
");

$bank_account_details = [];
foreach ($bank_accounts_list as $ba) {
    // Money In (debits to this bank account, all time)
    $money_in = (float)($db->fetchOne("
        SELECT COALESCE(SUM(je.amount), 0) as total
        FROM journal_entries je
        JOIN transaction_headers h ON je.header_id = h.id
        WHERE je.account_id = ? AND je.entry_type = 'debit'
          AND h.is_deleted = 0 AND h.status != 'voided'
    ", [$ba['id']])['total'] ?? 0);

    // Money Out (credits to this bank account, all time)
    $money_out = (float)($db->fetchOne("
        SELECT COALESCE(SUM(je.amount), 0) as total
        FROM journal_entries je
        JOIN transaction_headers h ON je.header_id = h.id
        WHERE je.account_id = ? AND je.entry_type = 'credit'
          AND h.is_deleted = 0 AND h.status != 'voided'
    ", [$ba['id']])['total'] ?? 0);

    // Current Balance
    $balance = (float)($db->fetchOne("
        SELECT COALESCE(SUM(CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE -je.amount END), 0) as bal
        FROM journal_entries je
        JOIN transaction_headers h ON je.header_id = h.id
        WHERE je.account_id = ? AND h.is_deleted = 0 AND h.status != 'voided'
    ", [$ba['id']])['bal'] ?? 0);

    // Today's transactions
    $today_in = (float)($db->fetchOne("
        SELECT COALESCE(SUM(je.amount), 0) as total
        FROM journal_entries je
        JOIN transaction_headers h ON je.header_id = h.id
        WHERE je.account_id = ? AND je.entry_type = 'debit'
          AND je.entry_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
    ", [$ba['id'], $today])['total'] ?? 0);

    $today_out = (float)($db->fetchOne("
        SELECT COALESCE(SUM(je.amount), 0) as total
        FROM journal_entries je
        JOIN transaction_headers h ON je.header_id = h.id
        WHERE je.account_id = ? AND je.entry_type = 'credit'
          AND je.entry_date = ? AND h.is_deleted = 0 AND h.status != 'voided'
    ", [$ba['id'], $today])['total'] ?? 0);

    $bank_account_details[] = [
        'id'           => $ba['id'],
        'account_code' => $ba['account_code'],
        'account_name' => $ba['account_name'],
        'money_in'     => $money_in,
        'money_out'    => $money_out,
        'balance'      => $balance,
        'today_in'     => $today_in,
        'today_out'    => $today_out,
    ];
}

// ══════════════════════════════════════════════════════════════════
// REMINDERS
// ══════════════════════════════════════════════════════════════════
$rem_bills = (int)($db->fetchOne("SELECT COUNT(*) FROM vendor_bills vb JOIN transaction_headers h ON vb.header_id = h.id WHERE vb.balance_due > 0 AND h.is_deleted = 0")['count'] ?? 0);
$rem_invoices = (int)($db->fetchOne("SELECT COUNT(*) FROM customer_invoices ci JOIN transaction_headers h ON ci.header_id = h.id WHERE ci.balance_due > 0 AND h.is_deleted = 0")['count'] ?? 0);

// ══════════════════════════════════════════════════════════════════
// BUILD RESPONSE
// ══════════════════════════════════════════════════════════════════

$response = [
    'status'    => 'success',
    'query_time_ms' => round((microtime(true) - $timer_start) * 1000, 1),
    'generated_at'  => $now,
    'role'      => $role,

    // Row 1: KPI Tiles
    'kpi' => [
        'today_sales'     => make_kpi($sales_today, $sales_yest),
        'today_expenses'  => make_kpi($expenses_today, $expenses_yest),
        'today_profit'    => make_kpi($profit_today, $profit_yest),
        'bank_flow'       => make_kpi($bank_flow_today, $bank_flow_yest),
        'cash_on_hand'    => make_kpi($bal_today['cash'], $bal_yest['cash']),
        'bank_balance'    => make_kpi($bal_today['bank'], $bal_yest['bank']),
        'ar'              => make_kpi($bal_today['ar'], $bal_yest['ar']),
        'ap'              => make_kpi($bal_today['ap'], $bal_yest['ap']),
        'inventory_value' => make_kpi((float)$inv_stats['inventory_value'], 0),
        'low_stock'       => make_kpi((float)$inv_stats['low_stock'], 0),
    ],

    // New: Cash Summary Tile Data
    'cash_summary' => [
        'opening'  => $opening_cash,
        'cash_in'  => $cash_in,
        'cash_out' => $cash_out,
        'closing'  => $closing_cash,
        'expected' => $expected_closing,
        'diff'     => $cash_diff,
        'counted_by' => count($mis) > 0 ? 'Verified' : ($closing_cash > 0 ? 'Counted' : 'Not Counted')
    ],

    // Row 2: Sales Analytics
    'sales_trend'   => $trend_data,
    'sales_payment' => [
        'labels' => array_keys($pay_methods),
        'values' => array_values($pay_methods)
    ],
    'sales_hourly'  => $trend_data, // same as trend when range='today'

    // Row 3: Inventory
    'inventory' => [
        'total_items'    => (int)$inv_stats['total_items'],
        'active_items'   => (int)$inv_stats['active_items'],
        'out_of_stock'   => (int)$inv_stats['out_of_stock'],
        'low_stock'      => (int)$inv_stats['low_stock'],
        'negative_stock' => (int)$inv_stats['negative_stock'],
        'overstock'      => (int)$inv_stats['overstock'],
        'value'          => (float)$inv_stats['inventory_value'],
        'top_selling'    => $top_selling,
        'slow_moving'    => $slow_30,
        'reorder_items'  => $reorder_items,
        'neg_stock_items' => $neg_stock_items,
    ],

    // Row 4: Financial
    'gp_trend'  => ['labels' => $gp_labels, 'values' => $gp_values],
    'expenses'  => ['labels' => $exp_labels, 'values' => $exp_values],
    'vat' => [
        'taxable'    => (float)$vat_sales_row['taxable'],
        'collected'  => (float)$vat_sales_row['collected'],
        'paid'       => (float)$vat_purch_row['paid'],
        'liability'  => (float)$vat_sales_row['collected'] - (float)$vat_purch_row['paid'],
    ],
    'monthly' => [
        'sales'          => $m_sales,
        'sales_last'     => $m_sales_last,
        'purchases'      => $m_purch,
        'purchases_last' => $m_purch_last,
        'expenses'       => $m_expenses,
        'expenses_last'  => $m_expenses_last,
        'profit'         => $m_profit,
        'profit_last'    => $m_profit_last,
        'fy_sales'       => $fy_sales,
        'fy_profit'      => $fy_profit,
        'fy_label'       => $fy_label,
    ],

    // Row 5: Customers & Suppliers
    'customers' => [
        'total'            => $cust_total,
        'new'              => $cust_new,
        'outstanding_ar'   => $bal_today['ar'],
        'top'              => $top_cust,
        'out_receivables'  => $out_ar,
    ],
    'suppliers' => [
        'total'          => $supp_total,
        'outstanding_ap' => $bal_today['ap'],
        'out_payables'   => $out_ap,
        'bills_due'      => $bills_due,
        'recent_purch'   => $recent_purch,
    ],

    // Row 6: Alerts
    'alerts'       => $alerts,
    'alerts_count' => count($alerts),

    // Row 7: Recent Activities
    'activities' => $recent,

    // Reminders
    'reminders' => [
        'bills_to_pay'  => $rem_bills,
        'open_invoices' => $rem_invoices,
        'low_stock'     => (int)$inv_stats['low_stock'],
    ],

    // Bank Account Details (individual accounts)
    'bank_accounts' => $bank_account_details,

    // Preferences
    'preferences' => $prefs ? [
        'layout'     => json_decode($prefs['layout_data'], true),
        'filters'    => json_decode($prefs['filters_data'], true),
    ] : null,
];

// ══════════════════════════════════════════════════════════════════
// ROLE-BASED ACCESS CONTROL
// ══════════════════════════════════════════════════════════════════

if ($role === 'cashier') {
    $keep = ['today_sales','today_profit','low_stock'];
    foreach ($response['kpi'] as $k => $v) if (!in_array($k, $keep)) unset($response['kpi'][$k]);
    $response['sales_trend'] = null;
    $response['sales_payment'] = null;
    $response['gp_trend'] = null;
    $response['expenses'] = null;
    $response['customers'] = null;
    $response['suppliers'] = null;
    $response['alerts'] = array_values(array_filter($alerts, fn($a) => $a['type'] === 'low_stock'));
    $response['activities'] = array_values(array_filter($activities, fn($a) => in_array($a['type'], ['POS Sale','Invoice'])));
}
if ($role === 'inventory') {
    $keep = ['inventory_value','low_stock'];
    foreach ($response['kpi'] as $k => $v) if (!in_array($k, $keep)) unset($response['kpi'][$k]);
    $response['sales_trend'] = null;
    $response['sales_payment'] = null;
    $response['gp_trend'] = null;
    $response['expenses'] = null;
    $response['customers'] = null;
    $response['suppliers']['out_payables'] = [];
    $response['alerts'] = array_values(array_filter($alerts, fn($a) => in_array($a['type'], ['low_stock','negative_stock','pending_approval'])));
}

// ─── Audit Log ──────────────────────────────────────────────
try {
    $db->execute(
        "INSERT INTO dashboard_audit (user_id, action, metadata) VALUES (?, 'view', ?)",
        [$user_id, json_encode(['role' => $role, 'time' => $now])]
    );
} catch (Exception $e) {
    // Silently skip if audit table doesn't exist yet
}

// ─── Cache Response ─────────────────────────────────────────
cache_set($cache_key, $response, 30);

echo json_encode($response);
exit;