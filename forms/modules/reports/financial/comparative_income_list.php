<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today = date('Y-m-d');

// 1. Get all fiscal years for the filter dropdown
$fys = $db->fetchAll("SELECT id, name, start_date, end_date FROM fiscal_years ORDER BY start_date DESC");
$fy_options = [];
foreach ($fys as $f) {
    $fy_options[$f['id']] = $f['name'] . ' (' . date('Y', strtotime($f['start_date'])) . '-' . date('y', strtotime($f['end_date'])) . ')';
}

// 2. Determine selected and active fiscal year
$active_fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE status IN ('open', 'reopened') ORDER BY start_date DESC LIMIT 1");
if (!$active_fy) {
    $active_fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE ? BETWEEN start_date AND end_date LIMIT 1", [$today]);
}
if (!$active_fy && !empty($fys)) {
    $active_fy = $fys[0]; // fallback to latest
}

$selected_fy_id = $_GET['fy_id'] ?? ($active_fy['id'] ?? null);

$this_fy = null;
if ($selected_fy_id) {
    $this_fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE id = ?", [$selected_fy_id]);
} else {
    $this_fy = $active_fy;
}

// 3. Find the previous fiscal year immediately preceding the selected one
$prev_fy = null;
if ($this_fy) {
    $prev_fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE start_date < ? ORDER BY start_date DESC LIMIT 1", [$this_fy['start_date']]);
}

// 4. Set date ranges
$date_from_this = $this_fy ? $this_fy['start_date'] : '1970-01-01';
$date_to_this   = $this_fy ? $this_fy['end_date'] : '1970-01-01';

$date_from_prev = $prev_fy ? $prev_fy['start_date'] : '1970-01-01';
$date_to_prev   = $prev_fy ? $prev_fy['end_date'] : '1970-01-01';

// 5. Fetch balances for This FY
$this_bal_rows = $db->fetchAll("
    SELECT j.account_id, 
           SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.source IS NULL
    GROUP BY j.account_id
", [$date_from_this, $date_to_this]);

$this_balances = [];
foreach ($this_bal_rows as $row) {
    $this_balances[$row['account_id']] = (float)$row['bal'];
}

// 6. Fetch balances for Prev FY
$prev_balances = [];
if ($prev_fy) {
    $prev_bal_rows = $db->fetchAll("
        SELECT j.account_id, 
               SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE h.txn_date BETWEEN ? AND ?
          AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.source IS NULL
        GROUP BY j.account_id
    ", [$date_from_prev, $date_to_prev]);

    foreach ($prev_bal_rows as $row) {
        $prev_balances[$row['account_id']] = (float)$row['bal'];
    }
}

// 7. Fetch all active and non-deleted accounts of type income and expense
$accounts = $db->fetchAll("
    SELECT id, account_code, account_name, account_type, account_subtype
    FROM accounts
    WHERE account_type IN ('income', 'expense') AND is_deleted = 0 AND is_active = 1
    ORDER BY account_name ASC
");

// 8. Group accounts into sections
$revenue_accounts = [];
$cogs_accounts = [];
$expense_accounts = [];

foreach ($accounts as $acc) {
    $bal_this = $this_balances[$acc['id']] ?? 0.00;
    $bal_prev = $prev_balances[$acc['id']] ?? 0.00;
    
    // Negate income accounts to reflect credit balances as positive amounts
    if ($acc['account_type'] === 'income') {
        $bal_this = -$bal_this;
        $bal_prev = -$bal_prev;
    }

    // Skip accounts that have 0 balance in both years
    if (abs($bal_this) < 0.005 && abs($bal_prev) < 0.005) {
        continue;
    }

    $acc_data = [
        'id' => $acc['id'],
        'code' => $acc['account_code'],
        'name' => $acc['account_name'],
        'this_val' => $bal_this,
        'prev_val' => $bal_prev
    ];

    if ($acc['account_type'] === 'income') {
        $revenue_accounts[] = $acc_data;
    } elseif ($acc['account_subtype'] === 'cogs') {
        $cogs_accounts[] = $acc_data;
    } else {
        $expense_accounts[] = $acc_data;
    }
}

// Calculate Section Totals
$total_rev_this = array_sum(array_column($revenue_accounts, 'this_val'));
$total_rev_prev = array_sum(array_column($revenue_accounts, 'prev_val'));

$total_cogs_this = array_sum(array_column($cogs_accounts, 'this_val'));
$total_cogs_prev = array_sum(array_column($cogs_accounts, 'prev_val'));

$gross_profit_this = $total_rev_this - $total_cogs_this;
$gross_profit_prev = $total_rev_prev - $total_cogs_prev;

$total_exp_this = array_sum(array_column($expense_accounts, 'this_val'));
$total_exp_prev = array_sum(array_column($expense_accounts, 'prev_val'));

$net_profit_this = $gross_profit_this - $total_exp_this;
$net_profit_prev = $gross_profit_prev - $total_exp_prev;

// Helper function to render variance column
function render_variance_cols($this_val, $prev_val, $is_expense = false) {
    $variance = $this_val - $prev_val;
    $formatted_var = rpt_currency(abs($variance));

    if ($variance > 0) {
        $formatted_var = '+' . $formatted_var;
    } elseif ($variance < 0) {
        $formatted_var = '-' . $formatted_var;
    } else {
        $formatted_var = rpt_currency(0);
    }

    // Determine color classes
    if ($prev_val == 0.00) {
        if ($this_val == 0.00) {
            $pct_text = '0.0%';
            $class = 'text-muted';
        } else {
            $pct_text = 'New';
            $class = $is_expense ? 'text-danger' : 'text-success';
        }
    } else {
        $pct = ($variance / abs($prev_val)) * 100;
        $pct_text = number_format($pct, 1) . '%';
        if ($pct > 0) {
            $pct_text = '+' . $pct_text;
            $class = $is_expense ? 'text-danger' : 'text-success';
        } elseif ($pct < 0) {
            $class = $is_expense ? 'text-success' : 'text-danger';
        } else {
            $class = 'text-muted';
        }
    }

    return [
        'amount' => "<span class='{$class}' style='font-weight: 600;'>{$formatted_var}</span>",
        'pct' => "<span class='{$class}' style='font-weight: 600;'>{$pct_text}</span>"
    ];
}
?>
<style>
    .comp-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        font-size: 13px;
        background: #fff;
    }
    .comp-table th {
        background: #003087;
        color: #fff;
        padding: 12px 16px;
        font-weight: 700;
        text-align: right;
        border: 1px solid #002366;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.5px;
    }
    .comp-table th:first-child {
        text-align: left;
    }
    .comp-table td {
        padding: 9px 16px;
        border-bottom: 1px solid #edf2f7;
        text-align: right;
    }
    .comp-table td:first-child {
        text-align: left;
        font-weight: 500;
        color: #334155;
    }
    .comp-section-row td {
        background: #f8fafc;
        font-weight: 700;
        color: #1e293b;
        text-align: left !important;
        padding: 10px 16px;
        font-size: 12px;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        border-bottom: 2px solid #cbd5e1;
        border-top: 1px solid #e2e8f0;
    }
    .comp-subtotal-row td {
        font-weight: 700;
        background: #f1f5f9;
        border-top: 1.5px solid #003087;
        border-bottom: 1.5px solid #003087;
        font-size: 13px;
        color: #0f172a;
    }
    .comp-grand-total-row td {
        font-weight: 800;
        font-size: 14px;
        border-top: 2px solid #003087;
        border-bottom: 4px double #003087;
    }
    .text-success { color: #16a34a !important; }
    .text-danger { color: #dc2626 !important; }
    .text-muted { color: #94a3b8 !important; }
    
    .comp-card {
        max-width: 900px;
        margin: 0 auto;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }
    
    .comp-header {
        text-align: center;
        padding: 24px;
        background: #fff;
        border-bottom: 2px solid #003087;
    }
    .comp-title {
        font-size: 20px;
        font-weight: 800;
        color: #003087;
        letter-spacing: 0.5px;
    }
    .comp-subtitle {
        font-size: 13px;
        color: #64748b;
        margin-top: 6px;
        font-weight: 500;
    }
</style>

<?php rpt_filter_bar('Comparative Income Statement', [
    ['name' => 'fy_id', 'label' => 'Fiscal Year', 'type' => 'select', 'options' => $fy_options, 'default' => $selected_fy_id],
], 'comparative-income-table'); ?>

<div class="ns-portlet comp-card">
    <div class="ns-portlet-content" style="padding: 0;">
        <div class="comp-header">
            <div class="comp-title">COMPARATIVE INCOME STATEMENT</div>
            <div class="comp-subtitle">
                This Fiscal Year: <strong><?= htmlspecialchars($this_fy['name'] ?? 'N/A') ?></strong> (<?= htmlspecialchars($date_from_this) ?> to <?= htmlspecialchars($date_to_this) ?>)<br>
                Previous Fiscal Year: <strong><?= htmlspecialchars($prev_fy['name'] ?? 'None') ?></strong> 
                <?php if ($prev_fy): ?>
                    (<?= htmlspecialchars($date_from_prev) ?> to <?= htmlspecialchars($date_to_prev) ?>)
                <?php endif; ?>
            </div>
        </div>

        <table class="comp-table" id="comparative-income-table">
            <thead>
                <tr>
                    <th>Account / Description</th>
                    <th>This FY (<?= htmlspecialchars($this_fy['name'] ?? 'N/A') ?>)</th>
                    <th>Prev FY (<?= htmlspecialchars($prev_fy['name'] ?? 'N/A') ?>)</th>
                    <th>Variance (Rs.)</th>
                    <th>Variance (%)</th>
                </tr>
            </thead>
            <tbody>
                <!-- REVENUE SECTION -->
                <tr class="comp-section-row">
                    <td colspan="5">Revenue</td>
                </tr>
                <?php if (empty($revenue_accounts)): ?>
                    <tr>
                        <td style="color: #94a3b8; font-style: italic;">No revenue recorded.</td>
                        <td><?= rpt_currency(0) ?></td>
                        <td><?= rpt_currency(0) ?></td>
                        <td class="text-muted"><?= rpt_currency(0) ?></td>
                        <td class="text-muted">0.0%</td>
                    </tr>
                <?php else: foreach ($revenue_accounts as $rev): 
                    $var = render_variance_cols($rev['this_val'], $rev['prev_val'], false);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($rev['name']) ?></td>
                        <td><?= rpt_currency($rev['this_val']) ?></td>
                        <td><?= rpt_currency($rev['prev_val']) ?></td>
                        <td><?= $var['amount'] ?></td>
                        <td><?= $var['pct'] ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                
                <?php 
                $rev_total_var = render_variance_cols($total_rev_this, $total_rev_prev, false);
                ?>
                <tr class="comp-subtotal-row">
                    <td>Total Revenue</td>
                    <td><?= rpt_currency($total_rev_this) ?></td>
                    <td><?= rpt_currency($total_rev_prev) ?></td>
                    <td><?= $rev_total_var['amount'] ?></td>
                    <td><?= $rev_total_var['pct'] ?></td>
                </tr>

                <!-- COGS SECTION -->
                <tr class="comp-section-row">
                    <td colspan="5">Cost of Goods Sold</td>
                </tr>
                <?php if (empty($cogs_accounts)): ?>
                    <tr>
                        <td style="color: #94a3b8; font-style: italic;">No cost of goods sold recorded.</td>
                        <td><?= rpt_currency(0) ?></td>
                        <td><?= rpt_currency(0) ?></td>
                        <td class="text-muted"><?= rpt_currency(0) ?></td>
                        <td class="text-muted">0.0%</td>
                    </tr>
                <?php else: foreach ($cogs_accounts as $cogs): 
                    $var = render_variance_cols($cogs['this_val'], $cogs['prev_val'], true);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($cogs['name']) ?></td>
                        <td><?= rpt_currency($cogs['this_val']) ?></td>
                        <td><?= rpt_currency($cogs['prev_val']) ?></td>
                        <td><?= $var['amount'] ?></td>
                        <td><?= $var['pct'] ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                
                <?php 
                $cogs_total_var = render_variance_cols($total_cogs_this, $total_cogs_prev, true);
                ?>
                <tr class="comp-subtotal-row">
                    <td>Total Cost of Goods Sold</td>
                    <td><?= rpt_currency($total_cogs_this) ?></td>
                    <td><?= rpt_currency($total_cogs_prev) ?></td>
                    <td><?= $cogs_total_var['amount'] ?></td>
                    <td><?= $cogs_total_var['pct'] ?></td>
                </tr>

                <!-- GROSS PROFIT -->
                <?php 
                $gp_total_var = render_variance_cols($gross_profit_this, $gross_profit_prev, false);
                ?>
                <tr class="comp-subtotal-row" style="background: #e0f2fe; color: #0369a1; border-top: 2px solid #0284c7; border-bottom: 2px solid #0284c7;">
                    <td>Gross Profit</td>
                    <td><?= rpt_currency($gross_profit_this) ?></td>
                    <td><?= rpt_currency($gross_profit_prev) ?></td>
                    <td><?= $gp_total_var['amount'] ?></td>
                    <td><?= $gp_total_var['pct'] ?></td>
                </tr>

                <!-- OPERATING EXPENSES SECTION -->
                <tr class="comp-section-row">
                    <td colspan="5">Operating Expenses</td>
                </tr>
                <?php if (empty($expense_accounts)): ?>
                    <tr>
                        <td style="color: #94a3b8; font-style: italic;">No expenses recorded.</td>
                        <td><?= rpt_currency(0) ?></td>
                        <td><?= rpt_currency(0) ?></td>
                        <td class="text-muted"><?= rpt_currency(0) ?></td>
                        <td class="text-muted">0.0%</td>
                    </tr>
                <?php else: foreach ($expense_accounts as $exp): 
                    $var = render_variance_cols($exp['this_val'], $exp['prev_val'], true);
                ?>
                    <tr>
                        <td><?= htmlspecialchars($exp['name']) ?></td>
                        <td><?= rpt_currency($exp['this_val']) ?></td>
                        <td><?= rpt_currency($exp['prev_val']) ?></td>
                        <td><?= $var['amount'] ?></td>
                        <td><?= $var['pct'] ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                
                <?php 
                $exp_total_var = render_variance_cols($total_exp_this, $total_exp_prev, true);
                ?>
                <tr class="comp-subtotal-row">
                    <td>Total Operating Expenses</td>
                    <td><?= rpt_currency($total_exp_this) ?></td>
                    <td><?= rpt_currency($total_exp_prev) ?></td>
                    <td><?= $exp_total_var['amount'] ?></td>
                    <td><?= $exp_total_var['pct'] ?></td>
                </tr>

                <!-- NET PROFIT -->
                <?php 
                $net_total_var = render_variance_cols($net_profit_this, $net_profit_prev, false);
                $net_bg = $net_profit_this >= 0 ? '#dcfce7' : '#fee2e2';
                $net_fg = $net_profit_this >= 0 ? '#166534' : '#991b1b';
                $net_border = $net_profit_this >= 0 ? '#15803d' : '#b91c1c';
                ?>
                <tr class="comp-grand-total-row" style="background: <?= $net_bg ?>; color: <?= $net_fg ?>; border-top: 2.5px solid <?= $net_border ?>; border-bottom: 5px double <?= $net_border ?>;">
                    <td><?= $net_profit_this >= 0 ? 'NET PROFIT' : 'NET LOSS' ?></td>
                    <td><?= rpt_currency($net_profit_this) ?></td>
                    <td><?= rpt_currency($net_profit_prev) ?></td>
                    <td><?= $net_total_var['amount'] ?></td>
                    <td><?= $net_total_var['pct'] ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
