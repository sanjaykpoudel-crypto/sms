<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';
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

// 4. Set cumulative dates
$as_of_this        = $this_fy ? $this_fy['end_date'] : $today;
$start_date_this   = get_report_start_date($as_of_this);

$as_of_prev        = $prev_fy ? $prev_fy['end_date'] : '1970-01-01';
$start_date_prev   = $prev_fy ? get_report_start_date($as_of_prev) : '1970-01-01';

/**
 * Helpers to fetch GL balances
 */
function get_gl_bal_for_dates($db, $id_or_subtype, $start_date, $as_of, $is_id = true, $exclude_inv_adj = false) {
    $field = $is_id ? 'j.account_id' : 'a.account_subtype';
    $extra_cond = $exclude_inv_adj ? " AND h.txn_type != 'inventory_adjustment' " : "";
    $row = $db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE $field = ? AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') $extra_cond
    ", [$id_or_subtype, $start_date, $as_of]);
    return (float)($row['bal'] ?? 0);
}

function get_bank_bal_for_dates($db, $start_date, $as_of) {
    $row = $db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN accounts a ON j.account_id = a.id
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_subtype = 'bank' AND a.id != 'acc-1010' AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ", [$start_date, $as_of]);
    return (float)($row['bal'] ?? 0);
}

function get_re_for_dates($db, $start_date, $as_of) {
    $revenue = -(float)($db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
        FROM journal_entries j 
        JOIN accounts a ON j.account_id = a.id 
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_type = 'income' AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.source IS NULL
    ", [$start_date, $as_of])['v'] ?? 0);

    $expenses = (float)($db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) AS v 
        FROM journal_entries j 
        JOIN accounts a ON j.account_id = a.id 
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE a.account_type = 'expense' AND j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.source IS NULL
    ", [$start_date, $as_of])['v'] ?? 0);

    return $revenue - $expenses;
}

function get_inv_reserve_for_dates($db, $start_date, $as_of) {
    // Inventory adjustments are now included in their original accounts directly.
    return 0.0;
}

// ─── 1. ASSETS ───────────────────────────────────────────────────────────────
$cash_this = get_gl_bal_for_dates($db, 'acc-1010', $start_date_this, $as_of_this);
$cash_prev = $prev_fy ? get_gl_bal_for_dates($db, 'acc-1010', $start_date_prev, $as_of_prev) : 0.0;

$bank_this = get_bank_bal_for_dates($db, $start_date_this, $as_of_this);
$bank_prev = $prev_fy ? get_bank_bal_for_dates($db, $start_date_prev, $as_of_prev) : 0.0;

$ar_this   = get_gl_bal_for_dates($db, 'receivable', $start_date_this, $as_of_this, false);
$ar_prev   = $prev_fy ? get_gl_bal_for_dates($db, 'receivable', $start_date_prev, $as_of_prev, false) : 0.0;

$inv_this  = get_gl_bal_for_dates($db, 'inventory', $start_date_this, $as_of_this, false);
$inv_prev  = $prev_fy ? get_gl_bal_for_dates($db, 'inventory', $start_date_prev, $as_of_prev, false) : 0.0;

$total_curr_assets_this = $cash_this + $bank_this + $ar_this + $inv_this;
$total_curr_assets_prev = $cash_prev + $bank_prev + $ar_prev + $inv_prev;

// Other Assets
$other_asset_accounts = $db->fetchAll("
    SELECT id, account_code, account_name FROM accounts 
    WHERE account_type = 'asset' 
      AND account_subtype NOT IN ('receivable', 'inventory') 
      AND id != 'acc-1010' 
      AND account_subtype != 'bank'
      AND is_deleted = 0 AND is_active = 1
    ORDER BY account_name ASC
");
$other_assets_rows = [];
$other_assets_total_this = 0.0;
$other_assets_total_prev = 0.0;

foreach ($other_asset_accounts as $acc) {
    $val_this = get_gl_bal_for_dates($db, $acc['id'], $start_date_this, $as_of_this);
    $val_prev = $prev_fy ? get_gl_bal_for_dates($db, $acc['id'], $start_date_prev, $as_of_prev) : 0.0;
    if (abs($val_this) < 0.005 && abs($val_prev) < 0.005) continue;

    $other_assets_rows[] = [
        'name' => $acc['account_name'],
        'this' => $val_this,
        'prev' => $val_prev
    ];
    $other_assets_total_this += $val_this;
    $other_assets_total_prev += $val_prev;
}

$total_assets_this = $total_curr_assets_this + $other_assets_total_this;
$total_assets_prev = $total_curr_assets_prev + $other_assets_total_prev;

// ─── 2. LIABILITIES ──────────────────────────────────────────────────────────
$ap_this   = -get_gl_bal_for_dates($db, 'payable', $start_date_this, $as_of_this, false);
$ap_prev   = $prev_fy ? -get_gl_bal_for_dates($db, 'payable', $start_date_prev, $as_of_prev, false) : 0.0;

$tax_this  = -get_gl_bal_for_dates($db, 'tax', $start_date_this, $as_of_this, false);
$tax_prev  = $prev_fy ? -get_gl_bal_for_dates($db, 'tax', $start_date_prev, $as_of_prev, false) : 0.0;

// Other Liabilities
$other_liability_accounts = $db->fetchAll("
    SELECT id, account_code, account_name FROM accounts 
    WHERE account_type = 'liability' 
      AND account_subtype NOT IN ('payable', 'tax')
      AND is_deleted = 0 AND is_active = 1
    ORDER BY account_name ASC
");
$other_liab_rows = [];
$other_liab_total_this = 0.0;
$other_liab_total_prev = 0.0;

foreach ($other_liability_accounts as $acc) {
    $val_this = -get_gl_bal_for_dates($db, $acc['id'], $start_date_this, $as_of_this);
    $val_prev = $prev_fy ? -get_gl_bal_for_dates($db, $acc['id'], $start_date_prev, $as_of_prev) : 0.0;
    if (abs($val_this) < 0.005 && abs($val_prev) < 0.005) continue;

    $other_liab_rows[] = [
        'name' => $acc['account_name'],
        'this' => $val_this,
        'prev' => $val_prev
    ];
    $other_liab_total_this += $val_this;
    $other_liab_total_prev += $val_prev;
}

$total_liabilities_this = $ap_this + $tax_this + $other_liab_total_this;
$total_liabilities_prev = $ap_prev + $tax_prev + $other_liab_total_prev;

// ─── 3. EQUITY ───────────────────────────────────────────────────────────────
$re_this   = get_re_for_dates($db, $start_date_this, $as_of_this);
$re_prev   = $prev_fy ? get_re_for_dates($db, $start_date_prev, $as_of_prev) : 0.0;

// Other Equity Accounts
$equity_accounts_list = $db->fetchAll("
    SELECT id, account_code, account_name FROM accounts 
    WHERE account_type = 'equity' 
      AND is_deleted = 0 AND is_active = 1
    ORDER BY account_name ASC
");
$equity_rows = [];
$equity_total_this = 0.0;
$equity_total_prev = 0.0;

foreach ($equity_accounts_list as $acc) {
    $val_this = -get_gl_bal_for_dates($db, $acc['id'], $start_date_this, $as_of_this, true, false);
    $val_prev = $prev_fy ? -get_gl_bal_for_dates($db, $acc['id'], $start_date_prev, $as_of_prev, true, false) : 0.0;
    if (abs($val_this) < 0.005 && abs($val_prev) < 0.005) continue;

    $equity_rows[] = [
        'name' => $acc['account_name'],
        'this' => $val_this,
        'prev' => $val_prev
    ];
    $equity_total_this += $val_this;
    $equity_total_prev += $val_prev;
}

$reserve_this = get_inv_reserve_for_dates($db, $start_date_this, $as_of_this);
$reserve_prev = $prev_fy ? get_inv_reserve_for_dates($db, $start_date_prev, $as_of_prev) : 0.0;

$total_equity_this = $re_this + $equity_total_this + $reserve_this;
$total_equity_prev = $re_prev + $equity_total_prev + $reserve_prev;

$total_liab_equity_this = $total_liabilities_this + $total_equity_this;
$total_liab_equity_prev = $total_liabilities_prev + $total_equity_prev;

$is_balanced_this = abs($total_assets_this - $total_liab_equity_this) < 0.05;
$is_balanced_prev = abs($total_assets_prev - $total_liab_equity_prev) < 0.05;

/**
 * Renders variance columns (variance amount, percentage)
 */
function render_var_cols($this_val, $prev_val, $is_bold = false) {
    $variance = $this_val - $prev_val;
    
    if (abs($variance) < 0.005) {
        return '<td style="text-align:right">-</td><td style="text-align:right">-</td>';
    }

    $color = $variance > 0 ? '#1a7f37' : '#c00';
    $sign = $variance > 0 ? '+' : '-';
    $formatted_var = rpt_currency(abs($variance));

    $pct = $prev_val != 0 ? ($variance / abs($prev_val)) * 100 : 100;
    $formatted_pct = number_format($pct, 1) . '%';
    if ($pct > 0) $formatted_pct = '+' . $formatted_pct;

    $weight = $is_bold ? 'font-weight:800;' : '';
    return '<td style="text-align:right;' . $weight . 'color:' . $color . '">' . $sign . $formatted_var . '</td>' .
           '<td style="text-align:right;' . $weight . 'color:' . $color . '">' . $formatted_pct . '</td>';
}
?>
<style>
.comp-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.comp-table th, .comp-table td { padding: 10px 15px; border-bottom: 1px solid #cbd5e1; }
.comp-table th { background: #003087; color: #fff; text-align: right; }
.comp-table th:first-child { text-align: left; }
.comp-table td { font-size: 13px; vertical-align: middle; }
.comp-table tr:hover:not(.grand-total-row):not(.total-row) { background: #f8fafc; }
.comp-table .sec-hdr { background: #f1f5f9; font-weight: 800; color: #1e293b; text-align: left !important; font-size: 13px; text-transform: uppercase; padding: 8px 12px; }
.comp-table .sub-sec-hdr { background: #f8fafc; font-weight: 700; color: #475569; text-align: left !important; font-size: 12px; padding-left: 20px; }
.comp-table .total-row { font-weight: 700; background: #e2e8f0; color: #0f172a; }
.comp-table .grand-total-row { font-weight: 800; background: #003087; color: #fff; }
.comp-table .grand-total-row td { color: #fff !important; }
.comp-table .indent-1 { padding-left: 28px; }
.comp-table .indent-2 { padding-left: 40px; }
.status-bar { display: flex; gap: 20px; max-width: 1000px; margin: 15px auto; }
.status-bar-box { flex: 1; text-align: center; padding: 10px; border-radius: 6px; font-weight: 700; font-size: 13px; }
@media print { .ns-header, .ns-nav, .rpt-toolbar, form { display: none !important; } }
</style>

<?php rpt_filter_bar('Comparative Balance Sheet', [
    ['name'=>'fy_id','label'=>'Fiscal Year','type'=>'select','default'=>$selected_fy_id,'options'=>$fy_options]
], 'tbl-comp-bs'); ?>

<div class="status-bar">
  <div class="status-bar-box" style="<?= $is_balanced_this ? 'background:#d4edda;color:#1a7f37' : 'background:#f8d7da;color:#842029' ?>">
      <?= $this_fy ? htmlspecialchars($this_fy['name']) : 'Current Period' ?>: <?= $is_balanced_this ? 'Balanced ✓' : 'Out of Balance ✗' ?>
  </div>
  <?php if ($prev_fy): ?>
  <div class="status-bar-box" style="<?= $is_balanced_prev ? 'background:#d4edda;color:#1a7f37' : 'background:#f8d7da;color:#842029' ?>">
      <?= htmlspecialchars($prev_fy['name']) ?>: <?= $is_balanced_prev ? 'Balanced ✓' : 'Out of Balance ✗' ?>
  </div>
  <?php endif; ?>
</div>

<div class="ns-portlet" style="max-width: 1000px; margin: 0 auto;">
  <div class="ns-portlet-content">
    <table class="comp-table" id="tbl-comp-bs">
      <thead>
        <tr>
          <th style="width:35%">Account Title</th>
          <th style="width:18%"><?= $this_fy ? htmlspecialchars($this_fy['name']) : 'This Year' ?></th>
          <th style="width:18%"><?= $prev_fy ? htmlspecialchars($prev_fy['name']) : 'Prev Year' ?></th>
          <th style="width:15%">Variance</th>
          <th style="width:14%">Variance %</th>
        </tr>
      </thead>
      <tbody>
        <!-- ─── ASSETS ─── -->
        <tr><td class="sec-hdr" colspan="5">ASSETS</td></tr>
        <tr><td class="sub-sec-hdr" colspan="5">Current Assets</td></tr>
        <tr>
          <td class="indent-1"><i class="fas fa-coins" style="color:#9a6700;margin-right:6px"></i>Cash on Hand</td>
          <td style="text-align:right"><?= rpt_currency($cash_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($cash_prev) ?></td>
          <?= render_var_cols($cash_this, $cash_prev) ?>
        </tr>
        <tr>
          <td class="indent-1"><i class="fas fa-university" style="color:#003087;margin-right:6px"></i>Bank / Digital Balance</td>
          <td style="text-align:right"><?= rpt_currency($bank_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($bank_prev) ?></td>
          <?= render_var_cols($bank_this, $bank_prev) ?>
        </tr>
        <tr>
          <td class="indent-1"><i class="fas fa-file-invoice-dollar" style="color:#1a7f37;margin-right:6px"></i>Accounts Receivable (AR)</td>
          <td style="text-align:right"><?= rpt_currency($ar_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($ar_prev) ?></td>
          <?= render_var_cols($ar_this, $ar_prev) ?>
        </tr>
        <tr>
          <td class="indent-1"><i class="fas fa-boxes" style="color:#6f42c1;margin-right:6px"></i>Inventory (at Cost)</td>
          <td style="text-align:right"><?= rpt_currency($inv_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($inv_prev) ?></td>
          <?= render_var_cols($inv_this, $inv_prev) ?>
        </tr>
        <tr class="total-row">
          <td style="padding-left:20px">Total Current Assets</td>
          <td style="text-align:right"><?= rpt_currency($total_curr_assets_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_curr_assets_prev) ?></td>
          <?= render_var_cols($total_curr_assets_this, $total_curr_assets_prev, true) ?>
        </tr>

        <!-- Other Assets -->
        <?php if (!empty($other_assets_rows)): ?>
          <tr><td class="sub-sec-hdr" colspan="5">Non-Current & Other Assets</td></tr>
          <?php foreach ($other_assets_rows as $row): ?>
            <tr>
              <td class="indent-1"><i class="fas fa-building" style="color:#475569;margin-right:6px"></i><?= htmlspecialchars($row['name']) ?></td>
              <td style="text-align:right"><?= rpt_currency($row['this']) ?></td>
              <td style="text-align:right"><?= rpt_currency($row['prev']) ?></td>
              <?= render_var_cols($row['this'], $row['prev']) ?>
            </tr>
          <?php endforeach; ?>
          <tr class="total-row">
            <td style="padding-left:20px">Total Non-Current & Other Assets</td>
            <td style="text-align:right"><?= rpt_currency($other_assets_total_this) ?></td>
            <td style="text-align:right"><?= rpt_currency($other_assets_total_prev) ?></td>
            <?= render_var_cols($other_assets_total_this, $other_assets_total_prev, true) ?>
          </tr>
        <?php endif; ?>

        <tr class="grand-total-row">
          <td>TOTAL ASSETS</td>
          <td style="text-align:right"><?= rpt_currency($total_assets_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_assets_prev) ?></td>
          <?= render_var_cols($total_assets_this, $total_assets_prev, true) ?>
        </tr>

        <!-- ─── LIABILITIES ─── -->
        <tr><td class="sec-hdr" colspan="5">LIABILITIES</td></tr>
        <tr>
          <td class="indent-1"><i class="fas fa-file-invoice-dollar" style="color:#c00;margin-right:6px"></i>Accounts Payable (AP)</td>
          <td style="text-align:right"><?= rpt_currency($ap_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($ap_prev) ?></td>
          <?= render_var_cols($ap_this, $ap_prev) ?>
        </tr>
        <tr>
          <td class="indent-1"><i class="fas fa-percentage" style="color:#e28743;margin-right:6px"></i>Tax Payable</td>
          <td style="text-align:right"><?= rpt_currency($tax_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($tax_prev) ?></td>
          <?= render_var_cols($tax_this, $tax_prev) ?>
        </tr>

        <!-- Other Liabilities -->
        <?php if (!empty($other_liab_rows)): ?>
          <tr><td class="sub-sec-hdr" colspan="5">Other Liabilities</td></tr>
          <?php foreach ($other_liab_rows as $row): ?>
            <tr>
              <td class="indent-1"><i class="fas fa-hand-holding-usd" style="color:#475569;margin-right:6px"></i><?= htmlspecialchars($row['name']) ?></td>
              <td style="text-align:right"><?= rpt_currency($row['this']) ?></td>
              <td style="text-align:right"><?= rpt_currency($row['prev']) ?></td>
              <?= render_var_cols($row['this'], $row['prev']) ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>

        <tr class="total-row">
          <td style="padding-left:20px">Total Liabilities</td>
          <td style="text-align:right"><?= rpt_currency($total_liabilities_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_liabilities_prev) ?></td>
          <?= render_var_cols($total_liabilities_this, $total_liabilities_prev, true) ?>
        </tr>

        <!-- ─── EQUITY ─── -->
        <tr><td class="sec-hdr" colspan="5">EQUITY</td></tr>
        <tr>
          <td class="indent-1"><i class="fas fa-history" style="color:#2563eb;margin-right:6px"></i>Retained Earnings (Net Profit)</td>
          <td style="text-align:right"><?= rpt_currency($re_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($re_prev) ?></td>
          <?= render_var_cols($re_this, $re_prev) ?>
        </tr>
        <?php foreach ($equity_rows as $row): ?>
          <tr>
            <td class="indent-1"><i class="fas fa-user-friends" style="color:#475569;margin-right:6px"></i><?= htmlspecialchars($row['name']) ?></td>
            <td style="text-align:right"><?= rpt_currency($row['this']) ?></td>
            <td style="text-align:right"><?= rpt_currency($row['prev']) ?></td>
            <?= render_var_cols($row['this'], $row['prev']) ?>
          </tr>
        <?php endforeach; ?>
        <?php if (abs($reserve_this) > 0.005 || abs($reserve_prev) > 0.005): ?>
          <tr>
            <td class="indent-1"><i class="fas fa-calculator" style="color:#475569;margin-right:6px"></i>Inventory Adjustment Reserve</td>
            <td style="text-align:right"><?= rpt_currency($reserve_this) ?></td>
            <td style="text-align:right"><?= rpt_currency($reserve_prev) ?></td>
            <?= render_var_cols($reserve_this, $reserve_prev) ?>
          </tr>
        <?php endif; ?>

        <tr class="total-row">
          <td style="padding-left:20px">Total Equity</td>
          <td style="text-align:right"><?= rpt_currency($total_equity_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_equity_prev) ?></td>
          <?= render_var_cols($total_equity_this, $total_equity_prev, true) ?>
        </tr>

        <tr class="grand-total-row">
          <td>TOTAL LIABILITIES & EQUITY</td>
          <td style="text-align:right"><?= rpt_currency($total_liab_equity_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($total_liab_equity_prev) ?></td>
          <?= render_var_cols($total_liab_equity_this, $total_liab_equity_prev, true) ?>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='comparative_balance_sheet.csv';a.click()}
</script>
