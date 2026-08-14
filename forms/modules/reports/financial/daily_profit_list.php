<?php
/**
 * Daily Profit & Loss Report
 * Features Daily Aggregates with Non-POS Transaction Breakdown (Invoices, Expenses, Journal Entries)
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$fy         = rpt_get_current_fiscal_year_dates();
$today      = date('Y-m-d');
$date_from  = $_GET['date_from'] ?? $fy['start_date'];
$date_to    = $_GET['date_to']   ?? $today;

$loc_sql = rpt_location_sql('h');
$loc_sql_th = rpt_location_sql('th');
$loc_sql_pe = rpt_location_sql('pe');

// ── 1. Daily Aggregates (Includes POS Sales + Invoices + Expenses + Journals) ──
$pos_sales_rows = $db->fetchAll("
    SELECT
        DATE(pe.date_time) as txn_date,
        SUM(pi.net_amount - pi.tax)                            as total_sales,
        SUM(COALESCE(NULLIF(pi.base_qty, 0), pi.quantity * COALESCE(pi.conversion_factor, 1)) * i.cost_price) as total_cogs,
        SUM((pi.net_amount - pi.tax) - (COALESCE(NULLIF(pi.base_qty, 0), pi.quantity * COALESCE(pi.conversion_factor, 1)) * i.cost_price)) as gross_profit
    FROM pos_items pi
    JOIN items i ON pi.item_id = i.id AND i.is_deleted = 0
    JOIN pos_entry pe ON pi.pos_id = pe.id
    WHERE pe.is_deleted = 0 {$loc_sql_pe}
      AND (pe.invoice_no NOT LIKE 'POS-SUM-%' OR pe.invoice_no IN (SELECT txn_number FROM transaction_headers th WHERE th.txn_type = 'customer_invoice' AND th.is_deleted = 0 {$loc_sql_th}))
      AND DATE(pe.date_time) BETWEEN ? AND ?
    GROUP BY DATE(pe.date_time)
", [$date_from, $date_to]);

$non_pos_sales_rows = $db->fetchAll("
    SELECT
        h.txn_date,
        SUM(l.line_total)             as total_sales,
        SUM(l.cost_price * COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))) as total_cogs,
        SUM(COALESCE(l.gross_profit, l.line_total - (l.cost_price * COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))))) as gross_profit
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_type = 'customer_invoice'
      AND h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_number NOT LIKE 'POS-%'
      AND h.txn_number NOT LIKE 'INV-POS-%' {$loc_sql}
    GROUP BY h.txn_date
", [$date_from, $date_to]);

$expense_rows = $db->fetchAll("
    SELECT h.txn_date, SUM(e.amount) as total_expenses
    FROM expenses e
    JOIN transaction_headers h ON e.header_id = h.id
    WHERE h.txn_type = 'expense' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ? {$loc_sql}
    GROUP BY h.txn_date
", [$date_from, $date_to]);

$journal_rows = $db->fetchAll("
    SELECT 
        h.txn_date,
        SUM(CASE WHEN a.account_type = 'income' THEN (CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as journal_income,
        SUM(CASE WHEN a.account_type = 'expense' AND a.account_subtype = 'Cost of Goods Sold' THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as journal_cogs,
        SUM(CASE WHEN a.account_type = 'expense' AND a.account_subtype != 'Cost of Goods Sold' THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as journal_expenses
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_type IN ('Journal', 'journal_entry')
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ?
      AND a.is_deleted = 0 {$loc_sql}
    GROUP BY h.txn_date
", [$date_from, $date_to]);

// Merge daily aggregates into a date-keyed map
$date_map = [];
foreach ($pos_sales_rows as $r) {
    $dt = $r['txn_date'];
    if (!isset($date_map[$dt])) $date_map[$dt] = ['total_sales'=>0,'total_cogs'=>0,'total_expenses'=>0];
    $date_map[$dt]['total_sales'] += (float)$r['total_sales'];
    $date_map[$dt]['total_cogs']  += (float)$r['total_cogs'];
}
foreach ($non_pos_sales_rows as $r) {
    $dt = $r['txn_date'];
    if (!isset($date_map[$dt])) $date_map[$dt] = ['total_sales'=>0,'total_cogs'=>0,'total_expenses'=>0];
    $date_map[$dt]['total_sales'] += (float)$r['total_sales'];
    $date_map[$dt]['total_cogs']  += (float)$r['total_cogs'];
}
foreach ($expense_rows as $r) {
    $dt = $r['txn_date'];
    if (!isset($date_map[$dt])) $date_map[$dt] = ['total_sales'=>0,'total_cogs'=>0,'total_expenses'=>0];
    $date_map[$dt]['total_expenses'] += (float)$r['total_expenses'];
}
foreach ($journal_rows as $r) {
    $dt = $r['txn_date'];
    if (!isset($date_map[$dt])) $date_map[$dt] = ['total_sales'=>0,'total_cogs'=>0,'total_expenses'=>0];
    $date_map[$dt]['total_sales']    += (float)$r['journal_income'];
    $date_map[$dt]['total_cogs']     += (float)$r['journal_cogs'];
    $date_map[$dt]['total_expenses'] += (float)$r['journal_expenses'];
}

krsort($date_map); // newest first
$daily_rows = [];
foreach ($date_map as $dt => $v) {
    $gross_profit = $v['total_sales'] - $v['total_cogs'];
    $daily_rows[] = [
        'date'           => $dt,
        'total_sales'    => $v['total_sales'],
        'total_cogs'     => $v['total_cogs'],
        'gross_profit'   => $gross_profit,
        'total_expenses' => $v['total_expenses'],
        'net_profit'     => $gross_profit - $v['total_expenses'],
    ];
}

// ── 2. Detailed Non-POS Transaction Breakdown Queries ──
// Customer Invoices (All Invoices including POS Invoices)
$dt_inv_txns = $db->fetchAll("
    SELECT
        h.txn_date,
        h.txn_number as ref_no,
        CASE WHEN h.txn_number LIKE 'INV-POS-%' OR h.txn_number LIKE 'POS-%' THEN 'POS Invoice' ELSE 'Invoice' END as type_label,
        COALESCE(NULLIF(TRIM(c.full_name), ''), NULLIF(TRIM(h.memo), ''), 'Customer Invoice') as party_name,
        COALESCE(NULLIF(SUM(l.line_total), 0), h.net_amount) as sales,
        COALESCE(SUM(l.cost_price * COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))), 0.00) as cogs,
        0.00 as expense,
        (COALESCE(NULLIF(SUM(l.line_total), 0), h.net_amount) - COALESCE(SUM(l.cost_price * COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))), 0.00)) as net_profit,
        NULL as pos_id,
        h.id as header_id
    FROM transaction_headers h
    LEFT JOIN transaction_lines l ON l.header_id = h.id
    LEFT JOIN customer_invoices ci ON h.id = ci.header_id
    LEFT JOIN customers c ON ci.customer_id = c.id
    WHERE h.txn_type = 'customer_invoice'
      AND h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    GROUP BY h.id, h.txn_date, h.txn_number, c.full_name, h.memo, h.net_amount
", [$date_from, $date_to]);

// Operating Expenses
$dt_exp_txns = $db->fetchAll("
    SELECT
        h.txn_date,
        h.txn_number as ref_no,
        'Expense' as type_label,
        COALESCE(v.company_name, NULLIF(e.description, ''), h.memo, 'Operating Expense') as party_name,
        0.00 as sales,
        0.00 as cogs,
        SUM(e.amount) as expense,
        -SUM(e.amount) as net_profit,
        NULL as pos_id,
        h.id as header_id
    FROM expenses e
    JOIN transaction_headers h ON e.header_id = h.id
    LEFT JOIN vendors v ON e.vendor_id = v.id
    WHERE h.txn_type = 'expense' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ? {$loc_sql}
    GROUP BY h.id, h.txn_date, h.txn_number, v.company_name, e.description, h.memo
", [$date_from, $date_to]);

// P&L Journal Entries (prioritizes line-level memo j.memo)
$dt_jour_txns = $db->fetchAll("
    SELECT
        h.txn_date,
        h.txn_number as ref_no,
        'Journal Entry' as type_label,
        COALESCE(NULLIF(TRIM(GROUP_CONCAT(DISTINCT CASE WHEN a.account_type IN ('income', 'expense') AND j.memo IS NOT NULL AND TRIM(j.memo) != '' THEN j.memo END SEPARATOR ', ')), ''), NULLIF(TRIM(h.memo), ''), NULLIF(TRIM(h.reference_number), ''), 'Journal Entry') as party_name,
        SUM(CASE WHEN a.account_type = 'income' THEN (CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as sales,
        SUM(CASE WHEN a.account_type = 'expense' AND a.account_subtype = 'Cost of Goods Sold' THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as cogs,
        SUM(CASE WHEN a.account_type = 'expense' AND a.account_subtype != 'Cost of Goods Sold' THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as expense,
        0.00 as net_profit,
        NULL as pos_id,
        h.id as header_id
    FROM journal_entries j
    JOIN accounts a ON j.account_id = a.id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_type IN ('Journal', 'journal_entry')
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_date BETWEEN ? AND ?
      AND a.is_deleted = 0 {$loc_sql}
    GROUP BY h.id, h.txn_date, h.txn_number, h.memo, h.reference_number
    HAVING (sales != 0 OR cogs != 0 OR expense != 0)
", [$date_from, $date_to]);

// Calculate net profit for journal transactions
foreach ($dt_jour_txns as &$jt) {
    $jt['net_profit'] = (float)$jt['sales'] - (float)$jt['cogs'] - (float)$jt['expense'];
}
unset($jt);

// Group non-POS transaction details by Date
$txns_by_date = [];
$all_txns = array_merge($dt_inv_txns, $dt_exp_txns, $dt_jour_txns);

foreach ($all_txns as $t) {
    $dt = $t['txn_date'];
    if (!isset($txns_by_date[$dt])) $txns_by_date[$dt] = [];
    $txns_by_date[$dt][] = $t;
}

// Sort transactions per date by ref_no
foreach ($txns_by_date as $dt => &$arr) {
    usort($arr, function($a, $b) {
        return strcmp($a['ref_no'], $b['ref_no']);
    });
}
unset($arr);

// Summary Totals
$sum_sales    = 0;
$sum_cogs     = 0;
$sum_gross    = 0;
$sum_expense  = 0;
$sum_net      = 0;

foreach ($daily_rows as $r) {
    $sum_sales   += (float)$r['total_sales'];
    $sum_cogs    += (float)$r['total_cogs'];
    $sum_gross   += (float)$r['gross_profit'];
    $sum_expense += (float)$r['total_expenses'];
    $sum_net     += (float)$r['net_profit'];
}
?>

<style>
    .btn-expand-dt {
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 600;
        color: #003087;
        cursor: pointer;
        transition: all 0.2s;
        margin-left: 8px;
    }
    .btn-expand-dt:hover {
        background: #003087;
        color: #fff;
        border-color: #003087;
    }
    .dt-breakdown-row {
        display: none;
        background: #f8fafc;
    }
    .dt-breakdown-row.active {
        display: table-row;
    }
    .inner-txns-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        margin: 10px 0;
        box-shadow: inset 0 2px 6px rgba(0,0,0,0.03);
        border: 1px solid #cbd5e1;
        border-radius: 6px;
    }
    .inner-txns-table th {
        background: #e2e8f0;
        color: #334155;
        font-size: 11px;
        text-transform: uppercase;
        padding: 6px 10px;
        border-bottom: 1px solid #cbd5e1;
    }
    .inner-txns-table td {
        padding: 6px 10px;
        border-bottom: 1px solid #e2e8f0;
    }
    .inner-txns-table tr:hover {
        background: #f1f5f9;
    }
    @media print {
        .btn-expand-dt, button, .no-print-toolbar, .no-print { display: none !important; }
        .print-title-date { display: inline !important; }
        .screen-title-date { display: none !important; }
        .dt-breakdown-row.active { display: table-row !important; }
        .inner-txns-table { width: 100% !important; border-collapse: collapse !important; font-size: 11px !important; margin: 6px 0 !important; }
        .inner-txns-table th { background: #f1f5f9 !important; color: #003087 !important; font-weight: 700 !important; border: 1px solid #cbd5e1 !important; border-bottom: 2px solid #003087 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .inner-txns-table td { border: 1px solid #cbd5e1 !important; padding: 5px 8px !important; }
    }
</style>

<?php rpt_filter_bar('Daily Profit & Loss Report', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>date('Y-m-01')],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
    rpt_location_filter(),
], 'tbl-daily-profit'); ?>

<div class="rpt-summary">
    <div class="rpt-summary-card">
        <div class="val"><?= rpt_currency($sum_sales) ?></div>
        <div class="lbl">Total Sales</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#16a085"><?= rpt_currency($sum_gross) ?></div>
        <div class="lbl">Gross Profit</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:#c0392b"><?= rpt_currency($sum_expense) ?></div>
        <div class="lbl">Operating Expenses</div>
    </div>
    <div class="rpt-summary-card">
        <div class="val" style="color:<?= $sum_net >= 0 ? '#27ae60' : '#d35400' ?>"><?= rpt_currency($sum_net) ?></div>
        <div class="lbl">Net Profit</div>
    </div>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">

        <div class="no-print-toolbar" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
            <div style="font-size: 13px; font-weight: 700; color: #334155;">
                <i class="fas fa-list-alt"></i> Daily Profit Summary & Breakdown
            </div>
            <button class="ns-btn" style="padding: 4px 12px; font-size: 11px;" onclick="toggleAllBreakdowns()"><i class="fas fa-layer-group"></i> Toggle Expand All</button>
        </div>

        <table class="ns-report-table-static" id="tbl-daily-profit">
            <thead>
                <tr>
                    <th><span class="screen-title-date">Date & Actions</span><span class="print-title-date" style="display:none;">Date</span></th>
                    <th style="text-align:right">Sales (Revenue)</th>
                    <th style="text-align:right">Cost of Sales (COGS)</th>
                    <th style="text-align:right">Gross Profit</th>
                    <th style="text-align:right">Operating Expenses</th>
                    <th style="text-align:right">Net Profit</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($daily_rows)): foreach ($daily_rows as $r):
                $dt = $r['date'];
                $day_txns = $txns_by_date[$dt] ?? [];
                $txn_cnt = count($day_txns);
            ?>
                <tr id="main-row-<?= $dt ?>">
                    <td style="font-weight:600">
                        <?= rpt_date($dt) ?>
                        <?php if ($txn_cnt > 0): ?>
                            <button class="btn-expand-dt no-print" onclick="toggleDayBreakdown('<?= $dt ?>')">
                                <i class="fas fa-chevron-down" id="icon-<?= $dt ?>"></i> Breakdown (<?= $txn_cnt ?>)
                            </button>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right"><?= rpt_currency((float)$r['total_sales']) ?></td>
                    <td style="text-align:right;color:#7f8c8d"><?= rpt_currency((float)$r['total_cogs']) ?></td>
                    <td style="text-align:right;font-weight:600;color:#16a085"><?= rpt_currency((float)$r['gross_profit']) ?></td>
                    <td style="text-align:right;color:#c0392b"><?= rpt_currency((float)$r['total_expenses']) ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= (float)$r['net_profit'] >= 0 ? '#27ae60' : '#d35400' ?>">
                        <?= rpt_currency((float)$r['net_profit']) ?>
                    </td>
                </tr>
                
                <!-- NESTED NON-POS TRANSACTION BREAKDOWN ROW -->
                <?php if ($txn_cnt > 0): ?>
                <tr id="breakdown-row-<?= $dt ?>" class="dt-breakdown-row">
                    <td colspan="6" style="padding: 10px 20px; background: #f8fafc;">
                        <div style="font-weight: 700; font-size: 11px; color: #003087; text-transform: uppercase; margin-bottom: 6px;">
                            <i class="fas fa-receipt"></i> Transaction Breakdown for <?= rpt_date($dt) ?> (<?= $txn_cnt ?> Non-POS Transactions)
                        </div>
                        <table class="inner-txns-table">
                            <thead>
                                <tr>
                                    <th>Txn Type</th>
                                    <th>Ref / Doc No.</th>
                                    <th>Party / Description</th>
                                    <th style="text-align:right">Sales (Revenue)</th>
                                    <th style="text-align:right">COGS</th>
                                    <th style="text-align:right">Expense</th>
                                    <th style="text-align:right">Net Profit Contribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($day_txns as $t): 
                                    $s = (float)$t['sales'];
                                    $c = (float)$t['cogs'];
                                    $e = (float)$t['expense'];
                                    $p = (float)$t['net_profit'];
                                    $badge_class = ($t['type_label'] === 'Invoice' ? 'ns-badge-success' : ($t['type_label'] === 'Expense' ? 'ns-badge-danger' : 'ns-badge-warning'));
                                ?>
                                <tr>
                                    <td><span class="ns-badge <?= $badge_class ?>"><?= htmlspecialchars($t['type_label']) ?></span></td>
                                    <td style="font-family: monospace; font-weight: 600;">
                                        <?php if (!empty($t['header_id'])): ?>
                                            <a href="?page=transactions/view&id=<?= urlencode($t['header_id']) ?>" target="_blank" title="View Transaction"><?= htmlspecialchars($t['ref_no']) ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($t['ref_no']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($t['party_name'] ?: 'N/A') ?></td>
                                    <td style="text-align:right; font-weight: 600;"><?= $s > 0 ? rpt_currency($s) : '-' ?></td>
                                    <td style="text-align:right; color: #7f8c8d;"><?= $c > 0 ? rpt_currency($c) : '-' ?></td>
                                    <td style="text-align:right; color: #c0392b;"><?= $e > 0 ? rpt_currency($e) : '-' ?></td>
                                    <td style="text-align:right; font-weight: 700; color: <?= $p >= 0 ? '#27ae60' : '#d35400' ?>"><?= rpt_currency($p) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>

            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($daily_rows)): ?>
            <tfoot>
                <tr style="background:#f8f9fa;font-weight:800;border-top:2px solid #ccc">
                    <td>TOTALS</td>
                    <td style="text-align:right"><?= rpt_currency($sum_sales) ?></td>
                    <td style="text-align:right"><?= rpt_currency($sum_cogs) ?></td>
                    <td style="text-align:right;color:#16a085"><?= rpt_currency($sum_gross) ?></td>
                    <td style="text-align:right;color:#c0392b"><?= rpt_currency($sum_expense) ?></td>
                    <td style="text-align:right;color:<?= $sum_net >= 0 ? '#27ae60' : '#d35400' ?>"><?= rpt_currency($sum_net) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

    </div>
</div>

<script>
function toggleDayBreakdown(dt) {
    const row = document.getElementById('breakdown-row-' + dt);
    const icon = document.getElementById('icon-' + dt);
    if (!row) return;
    const isVisible = row.classList.contains('active');
    if (isVisible) {
        row.classList.remove('active');
        if (icon) icon.className = 'fas fa-chevron-down';
    } else {
        row.classList.add('active');
        if (icon) icon.className = 'fas fa-chevron-up';
    }
}

function toggleAllBreakdowns() {
    const rows = document.querySelectorAll('.dt-breakdown-row');
    if (rows.length === 0) return;
    const firstActive = rows[0].classList.contains('active');
    rows.forEach(r => {
        if (firstActive) r.classList.remove('active');
        else r.classList.add('active');
    });
    document.querySelectorAll('.btn-expand-dt i').forEach(ic => {
        ic.className = firstActive ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
    });
}

function exportTableToCSV(id) {
    const t = document.getElementById(id);
    let csv = [];
    t.querySelectorAll('tr').forEach(r => {
        let row = [];
        r.querySelectorAll('th,td').forEach(c => row.push('"' + c.innerText.replace(/"/g, '""') + '"'));
        csv.push(row.join(','));
    });
    const b = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(b);
    a.download = 'daily_profit_report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
