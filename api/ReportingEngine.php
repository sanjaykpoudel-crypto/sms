<?php
/**
 * ReportingEngine.php
 * ============================================================
 * CENTRAL SINGLE-SOURCE-OF-TRUTH REPORTING ENGINE
 * ============================================================
 * All financial reports, subledger reports, and dashboard KPI
 * tiles MUST derive their data from the functions defined here.
 *
 * ARCHITECTURE:
 *   Transaction → Business Document → AccountingEngine (GL posts)
 *   → journal_entries / journal_lines / transaction_headers
 *   → ReportingEngine (this file)
 *   → Reports / Dashboard
 *
 * CARRY-FORWARD RULES (standard double-entry):
 *   Carry forward  : asset, liability, equity
 *   Do NOT carry   : income, expense
 *
 * ACCOUNT CLASSIFICATION MATRIX:
 *   Type      | Trial Balance | Balance Sheet | P&L | Carry Fwd
 *   asset     |      Yes      |      Yes      |  No |    Yes
 *   liability |      Yes      |      Yes      |  No |    Yes
 *   equity    |      Yes      |      Yes      |  No |    Yes
 *   income    |      Yes      |      No       | Yes |    No
 *   expense   |      Yes      |      No       | Yes |    No
 */

// ─── CONSTANTS (defined at file scope, not inside a function/if) ─────────────
if (!defined('RE_CARRY_FORWARD_TYPES')) {
    define('RE_CARRY_FORWARD_TYPES', ['asset', 'liability', 'equity']);
    define('RE_INCOME_STMT_TYPES',   ['income', 'expense']);
    define('RE_COGS_SUBTYPES',       ['Cost of Goods Sold', 'cogs', 'COGS']);
    define('RE_EXCLUDED_STATUSES',   ['void', 'voided', 'draft']);
    define('RE_CLOSE_SOURCES',       ['Fiscal Year Closing', 'Fiscal Year Opening']);
}

if (!function_exists('re_get_gl_balance')) {

// ─── CORE GL BALANCE ──────────────────────────────────────────────────────────

/**
 * Get the net GL balance for a single account up to a given date.
 *
 * For debit-normal accounts  (asset, expense): net = SUM(debit) - SUM(credit)
 * For credit-normal accounts (liability, equity, income): net = SUM(credit) - SUM(debit)
 * Returned value is always POSITIVE for the account's natural side.
 *
 * @param object      $db          DB connection object
 * @param int         $account_id  Account primary key
 * @param string|null $as_of       Inclusive end date (YYYY-MM-DD); defaults to today
 * @param string|null $from_date   Inclusive start date for period queries (null = all-time)
 * @param bool        $exclude_close_entries  Exclude fiscal-year-close journal entries
 * @return float
 */
function re_get_gl_balance($db, int $account_id, ?string $as_of = null, ?string $from_date = null, bool $exclude_close_entries = true): float
{
    if (!$as_of) $as_of = date('Y-m-d');

    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $date_filter = $from_date
        ? "AND je.je_date BETWEEN ? AND ?"
        : "AND je.je_date <= ?";

    $close_filter = $exclude_close_entries
        ? "AND (h.source IS NULL OR h.source NOT IN ('" . implode("','", RE_CLOSE_SOURCES) . "'))"
        : "";

    $params = $from_date
        ? [$account_id, $from_date, $as_of]
        : [$account_id, $as_of];

    $row = $db->fetchOne("
        SELECT
            a.normal_balance,
            SUM(jl.debit) AS total_dr,
            SUM(jl.credit) AS total_cr
        FROM journal_lines jl
        JOIN journal_entries je    ON jl.je_id = je.je_id
        JOIN accounts a            ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id  = h.id
        WHERE jl.account_id = ?
          {$date_filter}
          AND a.is_deleted = 0
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          {$close_filter}
    ", $params);

    if (!$row) return 0.0;

    $dr = (float)($row['total_dr'] ?? 0);
    $cr = (float)($row['total_cr'] ?? 0);

    // Debit-normal (asset, expense): positive = net debit
    // Credit-normal (liability, equity, income): positive = net credit
    if (($row['normal_balance'] ?? 'debit') === 'credit') {
        return round($cr - $dr, 2);
    }
    return round($dr - $cr, 2);
}

// ─── OPENING BALANCE ──────────────────────────────────────────────────────────

/**
 * Get the opening balance for an account at the START of a given date.
 * For balance-sheet accounts (asset/liability/equity): all-time GL up to (from_date - 1 day).
 * For income-statement accounts: always 0 (reset per fiscal year).
 *
 * @param object      $db
 * @param int         $account_id
 * @param string      $from_date   Period start date
 * @param string|null $fiscal_year_start  If set, income/expense opening = 0 if from_date == fy start
 * @return float
 */
function re_get_opening_balance($db, int $account_id, string $from_date, ?string $fiscal_year_start = null): float
{
    $acct = $db->fetchOne("SELECT account_type FROM accounts WHERE id = ? AND is_deleted = 0", [$account_id]);
    if (!$acct) return 0.0;

    $type = strtolower($acct['account_type'] ?? '');

    // Income statement accounts reset each year — opening is 0
    if (in_array($type, RE_INCOME_STMT_TYPES, true)) {
        return 0.0;
    }

    // Balance sheet accounts carry forward cumulative GL balance up to (from_date - 1 day)
    $prior_to = date('Y-m-d', strtotime("{$from_date} -1 day"));
    return re_get_gl_balance($db, $account_id, $prior_to, null, false);
}

// ─── PERIOD MOVEMENT ──────────────────────────────────────────────────────────

/**
 * Get total period debits and credits for an account within a date range.
 *
 * @param object $db
 * @param int    $account_id
 * @param string $from_date
 * @param string $to_date
 * @return array{debit: float, credit: float}
 */
function re_get_period_movement($db, int $account_id, string $from_date, string $to_date): array
{
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $close_src = implode("','", RE_CLOSE_SOURCES);

    $row = $db->fetchOne("
        SELECT
            COALESCE(SUM(jl.debit), 0) AS period_dr,
            COALESCE(SUM(jl.credit), 0) AS period_cr
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE jl.account_id = ?
          AND je.je_date BETWEEN ? AND ?
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
    ", [$account_id, $from_date, $to_date]);

    return [
        'debit'  => round((float)($row['period_dr'] ?? 0), 2),
        'credit' => round((float)($row['period_cr'] ?? 0), 2),
    ];
}

// ─── TRIAL BALANCE ────────────────────────────────────────────────────────────────

/**
 * Build a complete Trial Balance dataset.
 *
 * Closing Balance Rule:
 *   - Debit-normal accounts (asset, expense): closing is placed in Dr column
 *   - Credit-normal accounts (liability, equity, income): closing is placed in Cr column
 *   - When the GL is balanced, Sigma closing_dr == Sigma closing_cr
 *
 * @param object      $db
 * @param string      $from_date  Period start
 * @param string      $to_date    Period end
 * @param string|null $location_id
 * @return array{rows: array, totals: array, is_balanced: bool}
 */
function re_get_trial_balance($db, string $from_date, string $to_date, ?string $location_id = null): array
{
    $excluded  = implode("','", RE_EXCLUDED_STATUSES);
    $close_src = implode("','", RE_CLOSE_SOURCES);
    $loc_sql = '';
    if (!empty($location_id) && $location_id !== 'all') {
        $loc_sql = " AND h.location_id = " . $db->getConnection()->quote($location_id);
    }

    // Single batch query: cumulative Dr/Cr (all-time up to to_date) + period Dr/Cr
    $gl_rows = $db->fetchAll("
        SELECT
            a.id, a.account_name, a.account_type, a.account_subtype, a.normal_balance,
            COALESCE(SUM(CASE WHEN je.je_date <= '{$to_date}' THEN jl.debit ELSE 0 END), 0) AS cum_dr,
            COALESCE(SUM(CASE WHEN je.je_date <= '{$to_date}' THEN jl.credit ELSE 0 END), 0) AS cum_cr,
            COALESCE(SUM(CASE WHEN je.je_date BETWEEN '{$from_date}' AND '{$to_date}' THEN jl.debit ELSE 0 END), 0) AS period_dr,
            COALESCE(SUM(CASE WHEN je.je_date BETWEEN '{$from_date}' AND '{$to_date}' THEN jl.credit ELSE 0 END), 0) AS period_cr
        FROM accounts a
        JOIN journal_lines jl ON jl.account_id = a.id
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.is_deleted = 0
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
          {$loc_sql}
        GROUP BY a.id, a.account_name, a.account_type, a.account_subtype, a.normal_balance
        ORDER BY a.account_type, a.account_name
    ");

    $rows = [];
    $tot_close_dr  = 0; $tot_close_cr  = 0;
    $tot_period_dr = 0; $tot_period_cr = 0;
    $tot_open_dr   = 0; $tot_open_cr   = 0;

    foreach ($gl_rows as $row) {
        $nb     = strtolower($row['normal_balance'] ?? 'debit');
        $cum_dr = (float)$row['cum_dr'];
        $cum_cr = (float)$row['cum_cr'];
        $p_dr   = (float)$row['period_dr'];
        $p_cr   = (float)$row['period_cr'];

        // Income/expense opening = 0; Asset/liability/equity opening = cum - period
        $type = strtolower($row['account_type']);
        $is_inc_exp = in_array($type, RE_INCOME_STMT_TYPES, true);

        if ($is_inc_exp) {
            $open_balance = 0.0;
            $net_cum      = $p_dr - $p_cr;
        } else {
            $open_dr      = $cum_dr - $p_dr;
            $open_cr      = $cum_cr - $p_cr;
            $open_balance = ($nb === 'credit') ? ($open_cr - $open_dr) : ($open_dr - $open_cr);
            $net_cum      = $cum_dr - $cum_cr;
        }

        $open_dr_val = ($open_balance > 0 && $nb !== 'credit') || ($open_balance < 0 && $nb === 'credit') ? abs($open_balance) : 0;
        $open_cr_val = ($open_balance > 0 && $nb === 'credit') || ($open_balance < 0 && $nb !== 'credit') ? abs($open_balance) : 0;

        // Closing balance signed per natural balance
        $closing_val = ($nb === 'credit') ? -$net_cum : $net_cum;

        // Split into Dr / Cr columns for Trial Balance presentation
        $closing_dr = ($closing_val > 0 && $nb !== 'credit') || ($closing_val < 0 && $nb === 'credit') ? abs($closing_val) : 0;
        $closing_cr = ($closing_val > 0 && $nb === 'credit') || ($closing_val < 0 && $nb !== 'credit') ? abs($closing_val) : 0;

        // Skip rows with zero activity and zero closing
        if (abs($open_balance) < 0.001 && $p_dr == 0 && $p_cr == 0 && $closing_dr == 0 && $closing_cr == 0) {
            continue;
        }

        $rows[] = [
            'id'              => (int)$row['id'],
            'account_name'    => $row['account_name'],
            'account_type'    => $row['account_type'],
            'account_subtype' => $row['account_subtype'],
            'normal_balance'  => $nb,
            'opening_balance' => round($open_balance, 2),
            'opening_dr'      => round($open_dr_val, 2),
            'opening_cr'      => round($open_cr_val, 2),
            'period_debit'    => round($p_dr, 2),
            'period_credit'   => round($p_cr, 2),
            'period_dr'       => round($p_dr, 2),
            'period_cr'       => round($p_cr, 2),
            'closing_debit'   => round($closing_dr, 2),
            'closing_credit'  => round($closing_cr, 2),
            'closing_dr'      => round($closing_dr, 2),
            'closing_cr'      => round($closing_cr, 2),
            'net_balance'     => round($closing_val, 2),
        ];

        $tot_open_dr   += $open_dr_val;
        $tot_open_cr   += $open_cr_val;
        $tot_period_dr += $p_dr;
        $tot_period_cr += $p_cr;
        $tot_close_dr  += $closing_dr;
        $tot_close_cr  += $closing_cr;
    }

    $diff        = round(abs($tot_close_dr - $tot_close_cr), 2);
    $is_balanced = $diff < 0.05;

    return [
        'from_date'   => $from_date,
        'to_date'     => $to_date,
        'rows'        => $rows,
        'totals'      => [
            'period_debit'   => round($tot_period_dr, 2),
            'period_credit'  => round($tot_period_cr, 2),
            'closing_debit'  => round($tot_close_dr,  2),
            'closing_credit' => round($tot_close_cr,  2),
            'opening_debit'  => round($tot_open_dr,   2),
            'opening_credit' => round($tot_open_cr,   2),
            'period_dr'      => round($tot_period_dr, 2),
            'period_cr'      => round($tot_period_cr, 2),
            'closing_dr'     => round($tot_close_dr,  2),
            'closing_cr'     => round($tot_close_cr,  2),
            'opening_dr'     => round($tot_open_dr,   2),
            'opening_cr'     => round($tot_open_cr,   2),
            'difference'     => $diff,
        ],
        'is_balanced' => $is_balanced,
    ];
}

// ─── PROFIT AND LOSS ─────────────────────────────────────────────────────────

/**
 * Build a complete Income Statement (Profit & Loss) dataset.
 *
 * Formulas:
 *   Gross Profit = Total Revenue - Total COGS
 *   Net Profit   = Gross Profit - Total Operating Expenses
 *
 * @param object      $db
 * @param string      $from_date
 * @param string      $to_date
 * @param string|null $location_id
 * @return array
 */
function re_get_pnl($db, string $from_date, string $to_date, ?string $location_id = null): array
{
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $close_src = implode("','", RE_CLOSE_SOURCES);
    $loc_sql = '';
    if (!empty($location_id) && $location_id !== 'all') {
        $loc_sql = " AND h.location_id = " . $db->getConnection()->quote($location_id);
    }

    $base_where = "
        AND h.txn_date BETWEEN ? AND ?
        AND h.is_deleted = 0
        AND h.status NOT IN ('{$excluded}')
        AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
        AND a.is_deleted = 0
        {$loc_sql}
    ";
    $params = [$from_date, $to_date];

    // Revenue accounts
    $revenue_rows = $db->fetchAll("
        SELECT a.id, a.account_name, a.account_subtype,
               SUM(jl.credit - jl.debit) AS amount
        FROM journal_lines jl
        JOIN journal_entries je  ON jl.je_id = je.je_id
        JOIN accounts a          ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_type = 'income'
          {$base_where}
        GROUP BY a.id, a.account_name, a.account_subtype
        HAVING amount != 0
        ORDER BY a.account_name
    ", $params);

    // COGS accounts
    $cogs_subtypes_str = implode("','", RE_COGS_SUBTYPES);
    $cogs_rows = $db->fetchAll("
        SELECT a.id, a.account_name, a.account_subtype,
               SUM(jl.debit - jl.credit) AS amount
        FROM journal_lines jl
        JOIN journal_entries je  ON jl.je_id = je.je_id
        JOIN accounts a          ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_type = 'expense'
          AND a.account_subtype IN ('{$cogs_subtypes_str}')
          {$base_where}
        GROUP BY a.id, a.account_name, a.account_subtype
        HAVING amount != 0
        ORDER BY a.account_name
    ", $params);

    // Operating expense accounts (non-COGS)
    $expense_rows = $db->fetchAll("
        SELECT a.id, a.account_name, a.account_subtype,
               SUM(jl.debit - jl.credit) AS amount
        FROM journal_lines jl
        JOIN journal_entries je  ON jl.je_id = je.je_id
        JOIN accounts a          ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_type = 'expense'
          AND (a.account_subtype NOT IN ('{$cogs_subtypes_str}') OR a.account_subtype IS NULL)
          {$base_where}
        GROUP BY a.id, a.account_name, a.account_subtype
        HAVING amount != 0
        ORDER BY a.account_name
    ", $params);

    $total_revenue  = array_sum(array_column($revenue_rows,  'amount'));
    $total_cogs     = array_sum(array_column($cogs_rows,     'amount'));
    $total_expenses = array_sum(array_column($expense_rows,  'amount'));
    $gross_profit   = $total_revenue - $total_cogs;
    $net_profit     = $gross_profit  - $total_expenses;

    return [
        'from_date'      => $from_date,
        'to_date'        => $to_date,
        'revenue_rows'   => $revenue_rows,
        'cogs_rows'      => $cogs_rows,
        'expense_rows'   => $expense_rows,
        'total_revenue'  => round($total_revenue,  2),
        'total_cogs'     => round($total_cogs,     2),
        'gross_profit'   => round($gross_profit,   2),
        'total_expenses' => round($total_expenses, 2),
        'net_profit'     => round($net_profit,     2),
        'is_profit'      => $net_profit >= 0,
    ];
}

// ─── BALANCE SHEET ───────────────────────────────────────────────────────────

/**
 * Build a complete Balance Sheet dataset as of a given date.
 * Uses account_type from COA — no hardcoded names.
 *
 * @param object      $db
 * @param string      $as_of
 * @param string|null $location_id
 * @return array
 */
function re_get_balance_sheet($db, string $as_of, ?string $location_id = null): array
{
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $close_src = implode("','", RE_CLOSE_SOURCES);
    $loc_sql = '';
    $bs_loc_sql = '';
    if (!empty($location_id) && $location_id !== 'all') {
        $quoted_loc = $db->getConnection()->quote($location_id);
        $loc_sql = " AND h.location_id = " . $quoted_loc;
        $bs_loc_sql = " AND (h.location_id = " . $quoted_loc . " OR h.location_id IS NULL OR h.location_id = 0 OR h.source IN ('" . implode("','", RE_CLOSE_SOURCES) . "') OR h.txn_number LIKE 'JE-CLOSE-%')";
    }

    // Base WHERE clause for Asset, Liability, Equity balance sheet accounts (includes closing entries)
    $bs_where = "
        AND je.je_date <= ?
        AND h.is_deleted = 0
        AND h.status NOT IN ('{$excluded}')
        AND a.is_deleted = 0
        {$bs_loc_sql}
    ";

    // Base WHERE clause for Income/Expense P&L accounts (excludes closing entries)
    $pnl_where = "
        AND je.je_date <= ?
        AND h.is_deleted = 0
        AND h.status NOT IN ('{$excluded}')
        AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
        AND a.is_deleted = 0
        {$loc_sql}
    ";
    $p = [$as_of];

    // Fetch all balance-sheet account balances (including closing entries on BS accounts)
    $acct_rows = $db->fetchAll("
        SELECT
            a.id, a.account_name, a.account_type, a.account_subtype, a.normal_balance,
            SUM(jl.debit - jl.credit) AS net_dr
        FROM journal_lines jl
        JOIN journal_entries je  ON jl.je_id = je.je_id
        JOIN accounts a          ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_type IN ('asset','liability','equity')
          {$bs_where}
        GROUP BY a.id, a.account_name, a.account_type, a.account_subtype, a.normal_balance
        HAVING net_dr != 0
        ORDER BY a.account_type, a.id
    ", $p);

    // --- Calculate cumulative net income (P&L) for equity across unclosed periods up to $as_of ---
    $inc_row = $db->fetchOne("
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS rev
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN accounts a ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_type = 'income' AND je.je_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('{$excluded}')
          {$bs_loc_sql}
    ", [$as_of]);

    $exp_row = $db->fetchOne("
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS exp
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN accounts a ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_type = 'expense' AND je.je_date <= ? AND h.is_deleted = 0 AND h.status NOT IN ('{$excluded}')
          {$bs_loc_sql}
    ", [$as_of]);

    $net_income = round((float)($inc_row['rev'] ?? 0) - (float)($exp_row['exp'] ?? 0), 2);

    // Classify accounts
    $assets     = [];
    $liabilities = [];
    $equity     = [];

    foreach ($acct_rows as $row) {
        $type = strtolower($row['account_type']);
        $nb   = strtolower($row['normal_balance'] ?? 'debit');
        $net_dr = (float)$row['net_dr'];

        // Signed balance (positive = natural side)
        $balance = ($nb === 'credit') ? -$net_dr : $net_dr;

        $entry = [
            'id'          => (int)$row['id'],
            'name'        => $row['account_name'],
            'subtype'     => $row['account_subtype'],
            'balance'     => round($balance, 2),
        ];

        if ($type === 'asset')     $assets[]      = $entry;
        if ($type === 'liability') $liabilities[] = $entry;
        if ($type === 'equity')    $equity[]      = $entry;
    }

    $total_assets      = array_sum(array_column($assets,      'balance'));
    $total_liabilities = array_sum(array_column($liabilities, 'balance'));
    $total_equity_accts = array_sum(array_column($equity,     'balance'));
    $total_equity      = $total_equity_accts + $net_income;
    $total_liab_equity = $total_liabilities + $total_equity;
    $difference        = round(abs($total_assets - $total_liab_equity), 2);
    $is_balanced       = $difference < 0.05;

    return [
        'as_of'              => $as_of,
        'assets'             => $assets,
        'liabilities'        => $liabilities,
        'equity'             => $equity,
        'net_income'         => $net_income,
        'total_assets'       => round($total_assets, 2),
        'total_liabilities'  => round($total_liabilities, 2),
        'total_equity_accts' => round($total_equity_accts, 2),
        'total_equity'       => round($total_equity, 2),
        'total_liab_equity'  => round($total_liab_equity, 2),
        'difference'         => $difference,
        'is_balanced'        => $is_balanced,
    ];
}

// ─── AR CENTRAL ENGINE ───────────────────────────────────────────────────────

/**
 * Get the total Accounts Receivable balance from the central AR subledger.
 * Uses the same logic as get_total_receivables_balance() in reference_helper.php.
 *
 * @param object      $db
 * @param string|null $as_of
 * @param string|null $location_id
 * @return float
 */
function re_get_ar_balance($db, ?string $as_of = null, ?string $location_id = null): float
{
    if (!function_exists('get_total_receivables_balance')) {
        require_once __DIR__ . '/reference_helper.php';
    }
    return get_total_receivables_balance($db, $as_of, $location_id);
}

/**
 * Get the AR GL control account balance (for reconciliation).
 *
 * @param object      $db
 * @param string|null $as_of
 * @return float
 */
function re_get_ar_gl_balance($db, ?string $as_of = null, ?string $location_id = null): float
{
    if (!$as_of) $as_of = date('Y-m-d');
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $loc_sql = "";
    $params = [$as_of];
    if (!empty($location_id) && $location_id !== 'all') {
        $loc_sql = " AND h.location_id = ? ";
        $params[] = $location_id;
    }
    $row = $db->fetchOne("
        SELECT SUM(jl.debit - jl.credit) AS net_bal
        FROM journal_lines jl
        JOIN journal_entries je  ON jl.je_id = je.je_id
        JOIN accounts a          ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_subtype IN ('receivable','Accounts Receivable')
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          AND je.je_date <= ?
          {$loc_sql}
    ", $params);
    return round((float)($row['net_bal'] ?? 0), 2);
}

// ─── AP CENTRAL ENGINE ───────────────────────────────────────────────────────

/**
 * Get the total Accounts Payable balance from the central AP subledger.
 *
 * @param object      $db
 * @param string|null $as_of
 * @param string|null $location_id
 * @return float
 */
function re_get_ap_balance($db, ?string $as_of = null, ?string $location_id = null): float
{
    if (!function_exists('get_total_payables_balance')) {
        require_once __DIR__ . '/reference_helper.php';
    }
    return get_total_payables_balance($db, $as_of, $location_id);
}

/**
 * Get the AP GL control account balance (for reconciliation).
 *
 * @param object      $db
 * @param string|null $as_of
 * @param string|null $location_id
 * @return float
 */
function re_get_ap_gl_balance($db, ?string $as_of = null, ?string $location_id = null): float
{
    if (!$as_of) $as_of = date('Y-m-d');
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $loc_sql = "";
    $params = [$as_of];
    if (!empty($location_id) && $location_id !== 'all') {
        $loc_sql = " AND h.location_id = ? ";
        $params[] = $location_id;
    }
    $row = $db->fetchOne("
        SELECT SUM(jl.credit - jl.debit) AS net_bal
        FROM journal_lines jl
        JOIN journal_entries je  ON jl.je_id = je.je_id
        JOIN accounts a          ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_subtype IN ('payable','Accounts Payable')
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          AND je.je_date <= ?
          {$loc_sql}
    ", $params);
    return round((float)($row['net_bal'] ?? 0), 2);
}

// ─── INVENTORY CENTRAL ENGINE ────────────────────────────────────────────────

/**
 * Get the inventory valuation from the stock subledger (items × cost_price).
 *
 * @param object $db
 * @return float
 */
function re_get_inventory_subledger($db, ?string $as_of = null, ?string $location_id = null): float
{
    if (!$as_of) $as_of = date('Y-m-d');

    // If $as_of is today or future, query live stock balances
    if ($as_of >= date('Y-m-d')) {
        if (!empty($location_id) && $location_id !== 'all') {
            $row = $db->fetchOne("
                SELECT COALESCE(SUM(ib.quantity_on_hand * i.cost_price), 0) AS total_val
                FROM inventory_balances ib
                JOIN items i ON ib.item_id = i.id
                WHERE i.is_deleted = 0 AND ib.location_id = ?
            ", [$location_id]);
        } else {
            $row = $db->fetchOne("
                SELECT COALESCE(SUM(current_stock * cost_price), 0) AS total_val
                FROM items
                WHERE is_deleted = 0
            ");
        }
        return round((float)($row['total_val'] ?? 0), 2);
    }

    // For historical point-in-time statements prior to today, return point-in-time GL inventory balance
    return re_get_inventory_gl_balance($db, $as_of, $location_id);
}

/**
 * Get the Inventory GL control account balance.
 *
 * @param object      $db
 * @param string|null $as_of
 * @param string|null $location_id
 * @return float
 */
function re_get_inventory_gl_balance($db, ?string $as_of = null, ?string $location_id = null): float
{
    if (!$as_of) $as_of = date('Y-m-d');
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $loc_sql = "";
    $params = [$as_of];
    if (!empty($location_id)) {
        $loc_sql = " AND h.location_id = ? ";
        $params[] = $location_id;
    }
    $row = $db->fetchOne("
        SELECT SUM(jl.debit - jl.credit) AS net_bal
        FROM journal_lines jl
        JOIN journal_entries je  ON jl.je_id = je.je_id
        JOIN accounts a          ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_subtype IN ('inventory','Inventory Asset','Inventory')
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          AND je.je_date <= ?
          {$loc_sql}
    ", $params);
    return round((float)($row['net_bal'] ?? 0), 2);
}

/**
 * Reconcile Inventory GL balance with Subledger valuation by posting a variance adjustment journal entry.
 *
 * @param object      $db
 * @param int|string  $user_id
 * @param string|null $as_of
 * @param string|null $location_id
 * @return array
 */
function re_reconcile_inventory_gl($db, $user_id = 1, ?string $as_of = null, ?string $location_id = null): array
{
    if (!$as_of) $as_of = date('Y-m-d');
    $subledger = re_get_inventory_subledger($db, $as_of, $location_id);
    $gl        = re_get_inventory_gl_balance($db, $as_of, $location_id);
    $diff      = round($subledger - $gl, 2);
    $is_match  = abs($diff) < 0.05;

    return [
        'success'      => true,
        'is_reconciled' => $is_match,
        'subledger_val'=> $subledger,
        'gl_val'       => $gl,
        'difference'   => $diff,
        'message'      => $is_match
            ? 'Inventory GL and Subledger are fully reconciled.'
            : 'Inventory GL and Subledger differ by Rs ' . number_format(abs($diff), 2) . '. Read-only audit mode — no manual adjustment entries posted.'
    ];
}

/**
 * Detect any orphaned journal entries (entries not linked to a valid, active COA account).
 *
 * @param object $db
 * @return array
 */
function re_get_orphaned_transactions($db): array
{
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    return $db->fetchAll("
        SELECT jl.jl_id AS journal_entry_id, je.transaction_id AS header_id, jl.account_id, (CASE WHEN jl.debit > 0 THEN 'debit' ELSE 'credit' END) AS entry_type, (jl.debit + jl.credit) AS amount, je.je_date AS entry_date, h.txn_number, h.txn_type
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN transaction_headers h ON je.transaction_id = h.id
        LEFT JOIN accounts a ON jl.account_id = a.id
        WHERE (a.id IS NULL OR a.is_deleted = 1)
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
        ORDER BY je.je_date DESC
    ");
}

// ─── CASH / BANK BALANCES ────────────────────────────────────────────────────

/**
 * Get the GL balance for all accounts of a given subtype (e.g. 'Cash', 'Bank').
 *
 * @param object      $db
 * @param string|array $subtypes   Account subtype(s) to sum
 * @param string|null  $as_of
 * @param int[]|null   $exclude_ids  Account IDs to exclude
 * @return float
 */
function re_get_accounts_by_subtype($db, $subtypes, ?string $as_of = null, ?array $exclude_ids = null): float
{
    if (!$as_of) $as_of = date('Y-m-d');
    if (is_string($subtypes)) $subtypes = [$subtypes];
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $close_src = implode("','", RE_CLOSE_SOURCES);
    $subtype_str = implode("','", $subtypes);
    $excl_ids_sql = '';
    if (!empty($exclude_ids)) {
        $excl_ids_sql = " AND a.id NOT IN (" . implode(',', array_map('intval', $exclude_ids)) . ")";
    }
    $row = $db->fetchOne("
        SELECT SUM(jl.debit - jl.credit) AS net_bal
        FROM journal_lines jl
        JOIN journal_entries je  ON jl.je_id = je.je_id
        JOIN accounts a          ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_subtype IN ('{$subtype_str}')
          AND a.account_type = 'asset'
          AND je.je_date <= ?
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
          AND a.is_deleted = 0
          {$excl_ids_sql}
    ", [$as_of]);
    return round((float)($row['net_bal'] ?? 0), 2);
}

// ─── RETAINED EARNINGS ───────────────────────────────────────────────────────

/**
 * Calculate retained earnings dynamically:
 *   Prior Retained Earnings (equity accounts) + Cumulative Net Income to date.
 *
 * @param object      $db
 * @param string|null $as_of
 * @return float
 */
function re_get_retained_earnings($db, ?string $as_of = null): float
{
    if (!$as_of) $as_of = date('Y-m-d');
    $excluded = implode("','", RE_EXCLUDED_STATUSES);
    $close_src = implode("','", RE_CLOSE_SOURCES);

    // Net income to date (all income - all expense through this date)
    $revenue = (float)($db->fetchOne("
        SELECT SUM(jl.credit - jl.debit) AS v
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN accounts a ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_type = 'income'
          AND je.je_date <= ?
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
          AND a.is_deleted = 0
    ", [$as_of])['v'] ?? 0);

    $expenses = (float)($db->fetchOne("
        SELECT SUM(jl.debit - jl.credit) AS v
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN accounts a ON jl.account_id = a.id
        JOIN transaction_headers h ON je.transaction_id = h.id
        WHERE a.account_type = 'expense'
          AND je.je_date <= ?
          AND h.is_deleted = 0
          AND h.status NOT IN ('{$excluded}')
          AND (h.source IS NULL OR h.source NOT IN ('{$close_src}'))
          AND a.is_deleted = 0
    ", [$as_of])['v'] ?? 0);

    return round($revenue - $expenses, 2);
}

// ─── FULL RECONCILIATION CHECK ───────────────────────────────────────────────

/**
 * Run the full cross-report reconciliation suite and return results.
 *
 * Checks:
 *   1. Trial Balance: Σ Debits == Σ Credits
 *   2. Balance Sheet: Assets == Liabilities + Equity
 *   3. AR Subledger == AR GL
 *   4. AP Subledger == AP GL
 *   5. Inventory Subledger == Inventory GL
 *
 * @param object      $db
 * @param string      $from_date
 * @param string      $to_date
 * @return array
 */
function re_run_reconciliation($db, string $from_date, string $to_date): array
{
    $results = [];
    $all_ok  = true;
    $today   = date('Y-m-d');

    // 1. Trial Balance
    $tb = re_get_trial_balance($db, $from_date, $to_date);
    $tb_diff = abs($tb['totals']['closing_dr'] - $tb['totals']['closing_cr']);
    $results['trial_balance'] = [
        'closing_dr' => $tb['totals']['closing_dr'],
        'closing_cr' => $tb['totals']['closing_cr'],
        'difference' => round($tb_diff, 2),
        'status'     => $tb['is_balanced'] ? 'PASS' : 'FAIL — TRIAL BALANCE OUT OF BALANCE',
    ];
    if (!$tb['is_balanced']) $all_ok = false;

    // 2. Balance Sheet
    $bs = re_get_balance_sheet($db, $today);
    $results['balance_sheet'] = [
        'total_assets'      => $bs['total_assets'],
        'total_liab_equity' => $bs['total_liab_equity'],
        'difference'        => $bs['difference'],
        'status'            => $bs['is_balanced'] ? 'PASS' : 'FAIL — BALANCE SHEET OUT OF BALANCE',
    ];
    if (!$bs['is_balanced']) $all_ok = false;

    // 3. AR
    $ar_sub = re_get_ar_balance($db, $today);
    $ar_gl  = re_get_ar_gl_balance($db, $today);
    $ar_diff = abs($ar_sub - $ar_gl);
    $results['accounts_receivable'] = [
        'subledger' => $ar_sub,
        'gl'        => $ar_gl,
        'difference'=> round($ar_diff, 2),
        'status'    => $ar_diff < 0.05 ? 'PASS' : 'FAIL — AR RECONCILIATION ERROR',
    ];
    if ($ar_diff >= 0.05) $all_ok = false;

    // 4. AP
    $ap_sub = re_get_ap_balance($db, $today);
    $ap_gl  = re_get_ap_gl_balance($db, $today);
    $ap_diff = abs($ap_sub - $ap_gl);
    $results['accounts_payable'] = [
        'subledger' => $ap_sub,
        'gl'        => $ap_gl,
        'difference'=> round($ap_diff, 2),
        'status'    => $ap_diff < 0.05 ? 'PASS' : 'FAIL — AP RECONCILIATION ERROR',
    ];
    if ($ap_diff >= 0.05) $all_ok = false;

    // 5. Inventory
    $inv_sub = re_get_inventory_subledger($db, $today);
    $inv_gl  = re_get_inventory_gl_balance($db, $today);
    $inv_diff = abs($inv_sub - $inv_gl);
    $inv_pass = ($inv_diff <= 500.00) || ($inv_sub > 0 && ($inv_diff / $inv_sub) <= 0.002);
    $results['inventory'] = [
        'subledger' => $inv_sub,
        'gl'        => $inv_gl,
        'difference'=> round($inv_diff, 2),
        'status'    => $inv_pass ? 'PASS' : 'FAIL — INVENTORY RECONCILIATION ERROR',
    ];
    if (!$inv_pass) $all_ok = false;

    // 6. Orphaned Transactions Check
    $orphans = re_get_orphaned_transactions($db);
    $orphan_count = count($orphans);
    $results['orphaned_transactions'] = [
        'orphan_count' => $orphan_count,
        'status'       => $orphan_count === 0 ? 'PASS' : 'FAIL — UNLINKED ORPHANED TRANSACTIONS DETECTED (' . $orphan_count . ')',
    ];
    if ($orphan_count > 0) $all_ok = false;

    return [
        'all_pass'  => $all_ok,
        'timestamp' => date('Y-m-d H:i:s'),
        'checks'    => $results,
    ];
}

} // end if (!function_exists('re_get_gl_balance'))
