<?php
/**
 * Statement of Owner Equity Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$today = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to = $_GET['date_to'] ?? $today;

// Resolve aggregation boundary start date to prevent double-counting closed years
$fy_start_date = get_report_start_date($date_from);

$loc_sql = rpt_location_sql('h');

// 1. Fetch beginning balances of all equity accounts as of date_from (from boundary start date)
$beginning_balances = $db->fetchAll("
    SELECT a.id, a.account_name,
           -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_date >= ? AND h.txn_date < ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    GROUP BY a.id, a.account_name
", [$fy_start_date, $date_from]);

// 2. Fetch changes during the period (date_from to date_to)
$period_changes = $db->fetchAll("
    SELECT a.id, a.account_name,
           -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL {$loc_sql}
    GROUP BY a.id, a.account_name
", [$date_from, $date_to]);

// 3. Fetch net profit for the period
// Revenues in period
$revenue = -(float) ($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'income' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL {$loc_sql}
", [$date_from, $date_to])['v'] ?? 0);

// Expenses in period
$expenses = (float) ($db->fetchOne("
    SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
    FROM journal_entries j 
    JOIN accounts a ON j.account_id = a.id 
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL {$loc_sql}
", [$date_from, $date_to])['v'] ?? 0);

$net_profit = $revenue - $expenses;

// Organize balances
$equity_map = [];

// Initialize map with accounts
$all_eq = $db->fetchAll("SELECT id, account_name FROM accounts WHERE account_type = 'equity' AND is_active=1 AND is_deleted=0");
foreach ($all_eq as $ae) {
    $equity_map[$ae['id']] = [
        'name' => $ae['account_name'],
        'beginning' => 0.0,
        'changes' => 0.0,
        'ending' => 0.0
    ];
}

// Add beginning balances
foreach ($beginning_balances as $bb) {
    if (isset($equity_map[$bb['id']])) {
        $equity_map[$bb['id']]['beginning'] = (float) $bb['bal'];
    }
}

// Add period changes
foreach ($period_changes as $pc) {
    if (isset($equity_map[$pc['id']])) {
        $equity_map[$pc['id']]['changes'] = (float) $pc['bal'];
    }
}

// Inject Net Profit to Retained Earnings (acc-3200) changes
if (isset($equity_map['acc-3200'])) {
    $equity_map['acc-3200']['changes'] += $net_profit;
}

// 4. Fetch transaction details for equity accounts in the selected date range
$equity_transactions = $db->fetchAll("
    SELECT a.id as account_id, a.account_name, j.entry_date, j.entry_type, j.amount,
           h.txn_number, h.txn_type, h.memo
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'equity' AND h.txn_date BETWEEN ? AND ? 
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
    ORDER BY j.entry_date ASC, h.id ASC
", [$date_from, $date_to]);

$txns_by_code = [];
foreach ($equity_transactions as $et) {
    $txns_by_code[$et['account_code']][] = $et;
}

// Calculate ending balances
$tot_beg = 0.0;
$tot_chg = 0.0;
$tot_end = 0.0;

foreach ($equity_map as $code => &$val) {
    $val['ending'] = $val['beginning'] + $val['changes'];
    $tot_beg += $val['beginning'];
    $tot_chg += $val['changes'];
    $tot_end += $val['ending'];
}
unset($val);
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

    @media print {

        .btn-expand-eq,
        .no-print,
        .no-print-toolbar {
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

<?php rpt_filter_bar('Statement of Owner Equity', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
], ''); ?>

<div class="ns-portlet" style="max-width: 900px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding:0">

        <div class="no-print-toolbar"
            style="display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
            <div style="font-size: 13px; font-weight: 700; color: #334155;">
                <i class="fas fa-list-alt"></i> Owner Equity Summary & Transaction Details
            </div>
            <button class="ns-btn" style="padding: 4px 12px; font-size: 11px;" onclick="toggleAllEqBreakdowns()"><i
                    class="fas fa-layer-group"></i> Toggle Expand All Transactions</button>
        </div>

        <table class="ns-report-table-static" id="tbl-equity-statement">
            <thead>
                <tr>
                    <th>Account Code</th>
                    <th>Account Name</th>
                    <th style="text-align:right">Beginning Balance</th>
                    <th style="text-align:right">Net Period Changes</th>
                    <th style="text-align:right">Ending Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equity_map as $code => $eq):
                    if ($eq['beginning'] != 0 || $eq['changes'] != 0 || $eq['ending'] != 0):
                        $txns = $txns_by_code[$code] ?? [];
                        $txn_cnt = count($txns);
                        ?>
                        <tr>
                            <td style="font-weight:700; color:#888;"><?= $code ?></td>
                            <td style="font-weight:600;">
                                <?= htmlspecialchars($eq['name']) ?>
                                <?php if ($txn_cnt > 0): ?>
                                    <button class="btn-expand-eq no-print" onclick="toggleEqBreakdown('<?= $code ?>')">
                                        <i class="fas fa-chevron-down"></i> Breakdown (<?= $txn_cnt ?>)
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;"><?= rpt_currency($eq['beginning']) ?></td>
                            <td
                                style="text-align:right; color:<?= $eq['changes'] >= 0 ? '#1a7f37' : '#c00' ?>; font-weight:600;">
                                <?= $eq['changes'] >= 0 ? '+' : '' ?>        <?= rpt_currency($eq['changes']) ?>
                            </td>
                            <td style="text-align:right; font-weight:700; color:#003087;"><?= rpt_currency($eq['ending']) ?>
                            </td>
                        </tr>

                        <?php if ($txn_cnt > 0): ?>
                            <tr id="eq-breakdown-row-<?= $code ?>" class="eq-breakdown-row">
                                <td colspan="5" style="padding: 10px 15px;">
                                    <div
                                        style="font-size: 11px; font-weight: 700; color: #003087; text-transform: uppercase; margin-bottom: 4px;">
                                        <i class="fas fa-receipt"></i> Transactions for <?= htmlspecialchars($eq['name']) ?>
                                        (<?= $txn_cnt ?> entries)
                                    </div>
                                    <table class="inner-txns-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Txn Type</th>
                                                <th>Doc / Ref No.</th>
                                                <th>Memo / Description</th>
                                                <th style="text-align:right">Entry Type</th>
                                                <th style="text-align:right">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($txns as $t): ?>
                                                <tr>
                                                    <td><?= rpt_date($t['entry_date']) ?></td>
                                                    <td><?= htmlspecialchars(strtoupper($t['txn_type'])) ?></td>
                                                    <td><?= htmlspecialchars($t['txn_number']) ?></td>
                                                    <td><?= htmlspecialchars($t['memo'] ?: '—') ?></td>
                                                    <td
                                                        style="text-align:right; font-weight:600; color:<?= $t['entry_type'] === 'debit' ? '#003087' : '#c00' ?>">
                                                        <?= strtoupper($t['entry_type']) ?></td>
                                                    <td style="text-align:right; font-weight:600;">
                                                        <?= rpt_currency((float) $t['amount']) ?></td>
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
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:14px">
                    <td colspan="2" style="padding:12px 16px">TOTAL OWNER'S EQUITY</td>
                    <td style="text-align:right; padding:12px 16px"><?= rpt_currency($tot_beg) ?></td>
                    <td style="text-align:right; padding:12px 16px">
                        <?= $tot_chg >= 0 ? '+' : '' ?><?= rpt_currency($tot_chg) ?></td>
                    <td style="text-align:right; padding:12px 16px"><?= rpt_currency($tot_end) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>