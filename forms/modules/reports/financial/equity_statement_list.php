<?php
/**
 * Owner Equity Summary & Transaction Details Report
 * General Ledger Driven Owner Equity Analysis & Reconciliation
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;

// Resolve boundary start date to prevent double-counting closed years
$fy_start_date = get_report_start_date($date_from);
$loc_sql       = rpt_location_sql('h');

// 1. Fetch beginning balances of all equity accounts prior to date_from
$beginning_balances = $db->fetchAll("
    SELECT a.id, a.account_name,
           SUM(jl.credit - jl.debit) as bal
    FROM accounts a
    JOIN journal_lines jl ON a.id = jl.account_id
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_date >= ? AND h.txn_date < ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND (h.source IS NULL OR h.source NOT IN ('Fiscal Year Closing', 'Fiscal Year Opening')) {$loc_sql}
    GROUP BY a.id, a.account_name
", [$fy_start_date, $date_from]);

// 2. Fetch period changes breakdown (debits, credits, net change)
$period_changes = $db->fetchAll("
    SELECT a.id, a.account_name,
           SUM(jl.debit) as total_debit,
           SUM(jl.credit) as total_credit,
           SUM(jl.credit - jl.debit) as net_bal
    FROM accounts a
    JOIN journal_lines jl ON a.id = jl.account_id
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND (h.source IS NULL OR h.source NOT IN ('Fiscal Year Closing', 'Fiscal Year Opening')) {$loc_sql}
    GROUP BY a.id, a.account_name
", [$date_from, $date_to]);

// 3. Fetch Net Profit for the period (Revenues minus Expenses)
$revenue = (float) ($db->fetchOne("
    SELECT SUM(jl.credit - jl.debit) AS v 
    FROM journal_lines jl 
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN accounts a ON jl.account_id = a.id 
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE a.account_type = 'income' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND (h.source IS NULL OR h.source NOT IN ('Fiscal Year Closing', 'Fiscal Year Opening')) {$loc_sql}
", [$date_from, $date_to])['v'] ?? 0);

$expenses = (float) ($db->fetchOne("
    SELECT SUM(jl.debit - jl.credit) AS v 
    FROM journal_lines jl 
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN accounts a ON jl.account_id = a.id 
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE a.account_type = 'expense' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND (h.source IS NULL OR h.source NOT IN ('Fiscal Year Closing', 'Fiscal Year Opening')) {$loc_sql}
", [$date_from, $date_to])['v'] ?? 0);

$net_profit = $revenue - $expenses;

// Organize equity accounts map
$equity_map = [];
$all_eq = $db->fetchAll("SELECT id, account_name FROM accounts WHERE account_type = 'equity' AND is_active=1 AND is_deleted=0 ORDER BY id ASC");
foreach ($all_eq as $ae) {
    $equity_map[$ae['id']] = [
        'id'        => $ae['id'],
        'name'      => $ae['account_name'],
        'beginning' => 0.0,
        'debits'    => 0.0,
        'credits'   => 0.0,
        'changes'   => 0.0,
        'ending'    => 0.0
    ];
}

foreach ($beginning_balances as $bb) {
    if (isset($equity_map[$bb['id']])) {
        $equity_map[$bb['id']]['beginning'] = (float) $bb['bal'];
    }
}

foreach ($period_changes as $pc) {
    if (isset($equity_map[$pc['id']])) {
        $equity_map[$pc['id']]['debits']  = (float) $pc['total_debit'];
        $equity_map[$pc['id']]['credits'] = (float) $pc['total_credit'];
        $equity_map[$pc['id']]['changes'] = (float) $pc['net_bal'];
    }
}

// Inject Net Profit to Retained Earnings account dynamically
foreach ($equity_map as $eq_id => &$eq_item) {
    if (stripos($eq_item['name'], 'retained') !== false) {
        $eq_item['changes'] += $net_profit;
        break;
    }
}
unset($eq_item);

// 4. Fetch GL transaction details for equity accounts in date range
$equity_transactions = $db->fetchAll("
    SELECT a.id as account_id, a.account_name, je.je_date as entry_date, jl.debit, jl.credit,
           h.id as header_id, h.txn_number, h.txn_type, COALESCE(je.memo, h.memo) as memo, h.created_by, h.status as posting_status
    FROM accounts a
    JOIN journal_lines jl ON a.id = jl.account_id
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_date BETWEEN ? AND ? 
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND (h.source IS NULL OR h.source NOT IN ('Fiscal Year Closing', 'Fiscal Year Opening')) {$loc_sql}
    ORDER BY je.je_date ASC, h.id ASC, jl.jl_id ASC
", [$date_from, $date_to]);

$txns_by_account = [];
$contributions = 0.0;
$drawings      = 0.0;
$dividends     = 0.0;
$other_adj     = 0.0;

foreach ($equity_transactions as $et) {
    $acc_id = $et['account_id'];
    $txns_by_account[$acc_id][] = $et;

    // Categorize transaction movements
    $acc_name_lower = strtolower($et['account_name']);
    $net_val = (float)$et['credit'] - (float)$et['debit'];
    if (str_contains($acc_name_lower, 'drawing') || str_contains($acc_name_lower, 'withdrawal')) {
        $drawings += ((float)$et['debit'] - (float)$et['credit']);
    } elseif (str_contains($acc_name_lower, 'dividend')) {
        $dividends += ((float)$et['debit'] - (float)$et['credit']);
    } elseif (str_contains($acc_name_lower, 'capital') || str_contains($acc_name_lower, 'investment') || str_contains($acc_name_lower, 'contribution')) {
        $contributions += $net_val;
    } else {
        $other_adj += $net_val;
    }
}

// Calculate summary totals
$tot_beg = 0.0;
$tot_deb = 0.0;
$tot_cre = 0.0;
$tot_chg = 0.0;
$tot_end = 0.0;

foreach ($equity_map as &$val) {
    $val['ending'] = $val['beginning'] + $val['changes'];
    $tot_beg += $val['beginning'];
    $tot_deb += $val['debits'];
    $tot_cre += $val['credits'];
    $tot_chg += $val['changes'];
    $tot_end += $val['ending'];
}
unset($val);

// Balance Sheet Equity Validation
$bs_equity = (float) ($db->fetchOne("
    SELECT SUM(CASE 
        WHEN a.account_type IN ('equity', 'income') THEN (jl.credit - jl.debit)
        WHEN a.account_type = 'expense' THEN (jl.credit - jl.debit)
        ELSE 0 
    END) as bal
    FROM accounts a
    JOIN journal_lines jl ON a.id = jl.account_id
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers h ON je.transaction_id = h.id
    WHERE a.account_type IN ('equity', 'income', 'expense') AND h.txn_date <= ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
", [$date_to])['bal'] ?? 0);

$reconciled_diff = abs($tot_end - $bs_equity);
$is_reconciled   = $reconciled_diff < 0.01;
?>

<style>
    .btn-expand-eq {
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 600;
        color: #003087;
        cursor: pointer;
        margin-left: 8px;
    }
    .btn-expand-eq:hover {
        background: #003087;
        color: #fff;
    }
    .eq-breakdown-row {
        display: none;
        background: #f8fafc;
    }
    .eq-breakdown-row.active {
        display: table-row;
    }
    .inner-txns-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
        margin: 6px 0;
        border: 1px solid #cbd5e1;
    }
    .inner-txns-table th {
        background: #e2e8f0;
        color: #334155;
        padding: 6px 8px;
        border-bottom: 1px solid #cbd5e1;
        font-weight: 700;
        text-transform: uppercase;
    }
    .inner-txns-table td {
        padding: 5px 8px;
        border-bottom: 1px solid #e2e8f0;
    }
    .eq-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }
    .eq-kpi-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 14px 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        border-top: 3px solid #003087;
    }
    .eq-kpi-card .lbl { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
    .eq-kpi-card .val { font-size: 18px; font-weight: 800; color: #0f172a; margin-top: 4px; }
    .eq-recon-banner {
        padding: 12px 18px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 13px;
        font-weight: 700;
    }
    .eq-recon-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .eq-recon-warning { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

    @media print {
        .btn-expand-eq, .no-print, .no-print-toolbar, .eq-kpi-grid, .eq-recon-banner {
            display: none !important;
        }
        .eq-breakdown-row.active {
            display: table-row !important;
        }
        .inner-txns-table th {
            background: #f1f5f9 !important;
            color: #003087 !important;
            border: 1px solid #cbd5e1 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .inner-txns-table td {
            border: 1px solid #cbd5e1 !important;
            padding: 5px 8px !important;
        }
    }
</style>

<script>
    function toggleEqBreakdown(code) {
        const row = document.getElementById("eq-breakdown-row-" + code);
        if (row) row.classList.toggle("active");
    }
    function toggleAllEqBreakdowns() {
        const rows = document.querySelectorAll(".eq-breakdown-row");
        const anyHidden = Array.from(rows).some(r => !r.classList.contains("active"));
        rows.forEach(r => {
            if (anyHidden) r.classList.add("active");
            else r.classList.remove("active");
        });
    }
</script>

<?php rpt_filter_bar('Owner Equity Summary & Transaction Details', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], 'tbl-equity-statement'); ?>

<div class="ns-portlet" style="max-width: 1100px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding:15px">

        <!-- Reconciliation Audit Status Banner -->
        <div class="eq-recon-banner <?= $is_reconciled ? 'eq-recon-success' : 'eq-recon-warning' ?>">
            <div>
                <i class="fas <?= $is_reconciled ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
                <strong>Balance Sheet Reconciliation Audit:</strong> 
                <?= $is_reconciled ? 'Reconciled (Closing Equity matches General Ledger Balance Sheet)' : 'Discrepancy Detected (Diff: ' . rpt_currency($reconciled_diff) . ')' ?>
            </div>
            <div style="font-size: 11px; opacity: 0.9;">
                Calculated Closing Equity: <strong><?= rpt_currency($tot_end) ?></strong> | Balance Sheet Total: <strong><?= rpt_currency($bs_equity) ?></strong>
            </div>
        </div>

        <!-- Summary KPI Cards -->
        <div class="eq-kpi-grid">
            <div class="eq-kpi-card">
                <div class="lbl">Opening Equity</div>
                <div class="val"><?= rpt_currency($tot_beg) ?></div>
            </div>
            <div class="eq-kpi-card" style="border-top-color: #10b981;">
                <div class="lbl">Capital Contributions</div>
                <div class="val" style="color:#059669">+<?= rpt_currency($contributions) ?></div>
            </div>
            <div class="eq-kpi-card" style="border-top-color: <?= $net_profit >= 0 ? '#3b82f6' : '#ef4444' ?>;">
                <div class="lbl">Net Profit / (Loss)</div>
                <div class="val" style="color:<?= $net_profit >= 0 ? '#2563eb' : '#dc2626' ?>;">
                    <?= $net_profit >= 0 ? '+' : '' ?><?= rpt_currency($net_profit) ?>
                </div>
            </div>
            <div class="eq-kpi-card" style="border-top-color: #f59e0b;">
                <div class="lbl">Owner Drawings</div>
                <div class="val" style="color:#d97706">-<?= rpt_currency($drawings) ?></div>
            </div>
            <div class="eq-kpi-card" style="border-top-color: #8b5cf6;">
                <div class="lbl">Closing Equity</div>
                <div class="val" style="color:#003087"><?= rpt_currency($tot_end) ?></div>
            </div>
        </div>

        <!-- Action Toolbar -->
        <div class="no-print-toolbar" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 15px;">
            <div style="font-size: 13px; font-weight: 700; color: #334155;">
                <i class="fas fa-list-alt"></i> Owner Equity Summary & Account Breakdown
            </div>
            <button class="ns-btn" style="padding: 4px 12px; font-size: 11px;" onclick="toggleAllEqBreakdowns()">
                <i class="fas fa-layer-group"></i> Toggle Expand All Breakdown
            </button>
        </div>

        <!-- Summary Account Table -->
        <table class="ns-report-table-static" id="tbl-equity-statement" style="margin-bottom: 30px;">
            <thead>
                <tr>
                    <th>Account Name</th>
                    <th style="text-align:right">Opening Balance</th>
                    <th style="text-align:right">Period Debits</th>
                    <th style="text-align:right">Period Credits</th>
                    <th style="text-align:right">Net Period Changes</th>
                    <th style="text-align:right">Closing Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equity_map as $code => $eq):
                    if ($eq['beginning'] != 0 || $eq['changes'] != 0 || $eq['ending'] != 0 || $eq['debits'] != 0 || $eq['credits'] != 0):
                        $acc_txns = $txns_by_account[$code] ?? [];
                        $txn_cnt  = count($acc_txns);
                        ?>
                        <tr>
                            <td style="font-weight:600; color:#0f172a;">
                                <?= htmlspecialchars($eq['name']) ?>
                                <?php if ($txn_cnt > 0): ?>
                                    <button class="btn-expand-eq no-print" onclick="toggleEqBreakdown('<?= htmlspecialchars($code) ?>')">
                                        <i class="fas fa-chevron-down"></i> Breakdown (<?= $txn_cnt ?>)
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;"><?= rpt_currency($eq['beginning']) ?></td>
                            <td style="text-align:right; color:#dc2626;"><?= rpt_currency($eq['debits']) ?></td>
                            <td style="text-align:right; color:#059669;"><?= rpt_currency($eq['credits']) ?></td>
                            <td style="text-align:right; color:<?= $eq['changes'] >= 0 ? '#166534' : '#dc2626' ?>; font-weight:600;">
                                <?= $eq['changes'] >= 0 ? '+' : '' ?><?= rpt_currency($eq['changes']) ?>
                            </td>
                            <td style="text-align:right; font-weight:700; color:#003087;"><?= rpt_currency($eq['ending']) ?></td>
                        </tr>

                        <?php if ($txn_cnt > 0): ?>
                            <tr id="eq-breakdown-row-<?= htmlspecialchars($code) ?>" class="eq-breakdown-row">
                                <td colspan="6" style="padding: 10px 15px;">
                                    <div style="font-size: 11px; font-weight: 700; color: #003087; text-transform: uppercase; margin-bottom: 6px;">
                                        <i class="fas fa-receipt"></i> Transactions for <?= htmlspecialchars($eq['name']) ?> (<?= $txn_cnt ?> GL entries)
                                    </div>
                                    <table class="inner-txns-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Txn Type</th>
                                                <th>Doc / Ref No.</th>
                                                <th>Memo / Description</th>
                                                <th style="text-align:right">Debit</th>
                                                <th style="text-align:right">Credit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($acc_txns as $t): ?>
                                                <tr>
                                                    <td><?= rpt_date($t['entry_date']) ?></td>
                                                    <td><span class="ns-badge" style="background:#e2e8f0; color:#334155; padding:2px 6px; border-radius:4px; font-size:10px; text-transform:uppercase; font-weight:700;"><?= htmlspecialchars($t['txn_type']) ?></span></td>
                                                    <td style="font-weight:600; color:#003087;"><?= htmlspecialchars($t['txn_number']) ?></td>
                                                    <td><?= htmlspecialchars($t['memo'] ?: '—') ?></td>
                                                    <td style="text-align:right; color:<?= (float)$t['debit'] > 0 ? '#dc2626' : '#94a3b8' ?>; font-weight:600;">
                                                        <?= (float)$t['debit'] > 0 ? rpt_currency((float)$t['debit']) : '—' ?>
                                                    </td>
                                                    <td style="text-align:right; color:<?= (float)$t['credit'] > 0 ? '#059669' : '#94a3b8' ?>; font-weight:600;">
                                                        <?= (float)$t['credit'] > 0 ? rpt_currency((float)$t['credit']) : '—' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        <?php endif; ?>

                    <?php endif; endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:13px">
                    <td style="padding:10px 14px">TOTAL OWNER'S EQUITY</td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_beg) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_deb) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_cre) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= $tot_chg >= 0 ? '+' : '' ?><?= rpt_currency($tot_chg) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_end) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Complete General Ledger Equity Transactions Table with Running Equity Balance -->
        <div style="margin-top: 30px; border-top: 2px solid #cbd5e1; padding-top: 20px;">
            <div style="font-size: 14px; font-weight: 800; color: #003087; text-transform: uppercase; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
                <span><i class="fas fa-stream"></i> All General Ledger Equity Transactions (Running Equity Balance)</span>
                <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: none;">Total Entries: <?= count($equity_transactions) ?></span>
            </div>

            <table class="ns-report-table-static">
                <thead>
                    <tr>
                        <th>Posting Date</th>
                        <th>Txn Type</th>
                        <th>Doc / Ref #</th>
                        <th>Account</th>
                        <th>Memo / Description</th>
                        <th style="text-align:right">Debit</th>
                        <th style="text-align:right">Credit</th>
                        <th style="text-align:right">Running Equity Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening Equity Row -->
                    <tr style="background: #f8fafc; font-weight: 700;">
                        <td><?= rpt_date($date_from) ?></td>
                        <td><span class="ns-badge" style="background:#003087; color:#fff; padding:2px 6px; border-radius:4px; font-size:10px; text-transform:uppercase;">OPENING</span></td>
                        <td>—</td>
                        <td colspan="2" style="color:#475569;">Opening Equity Balance Prior to Period</td>
                        <td style="text-align:right">—</td>
                        <td style="text-align:right">—</td>
                        <td style="text-align:right; color:#003087; font-weight:800;"><?= rpt_currency($tot_beg) ?></td>
                    </tr>

                    <?php 
                    $running_bal = $tot_beg;
                    foreach ($equity_transactions as $t): 
                        $deb = (float)$t['debit'];
                        $cre = (float)$t['credit'];
                        
                        // Equity balance increases with Credit, decreases with Debit
                        $running_bal += ($cre - $deb);
                    ?>
                        <tr>
                            <td><?= rpt_date($t['entry_date']) ?></td>
                            <td><span class="ns-badge" style="background:#f1f5f9; color:#334155; padding:2px 6px; border-radius:4px; font-size:10px; text-transform:uppercase; font-weight:700;"><?= htmlspecialchars($t['txn_type']) ?></span></td>
                            <td style="font-weight:600; color:#003087;"><?= htmlspecialchars($t['txn_number']) ?></td>
                            <td><?= htmlspecialchars($t['account_name']) ?></td>
                            <td><?= htmlspecialchars($t['memo'] ?: '—') ?></td>
                            <td style="text-align:right; color:<?= $deb > 0 ? '#dc2626' : '#94a3b8' ?>; font-weight:600;">
                                <?= $deb > 0 ? rpt_currency($deb) : '—' ?>
                            </td>
                            <td style="text-align:right; color:<?= $cre > 0 ? '#059669' : '#94a3b8' ?>; font-weight:600;">
                                <?= $cre > 0 ? rpt_currency($cre) : '—' ?>
                            </td>
                            <td style="text-align:right; font-weight:800; color:#003087;">
                                <?= rpt_currency($running_bal) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f1f5f9; font-weight:800;">
                        <td colspan="7" style="padding:10px 14px; text-align:right; color:#334155;">CLOSING EQUITY BALANCE (AS OF <?= rpt_date($date_to) ?>):</td>
                        <td style="text-align:right; padding:10px 14px; color:#003087; font-size:14px;"><?= rpt_currency($tot_end) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

    </div>
</div>