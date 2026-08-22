<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';
require_once 'api/ReportingEngine.php';
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

$user_loc = function_exists('get_user_default_location_id') ? get_user_default_location_id() : '';
$location_id = $_GET['location_id'] ?? ($user_loc ?: ($_SESSION['location_id'] ?? null));

// 4. Set cumulative dates
$as_of_this = $this_fy ? $this_fy['end_date'] : $today;
$as_of_prev = $prev_fy ? $prev_fy['end_date'] : '1970-01-01';

// Fetch via central ReportingEngine
$bs_this = re_get_balance_sheet($db, $as_of_this, $location_id);
$bs_prev = $prev_fy ? re_get_balance_sheet($db, $as_of_prev, $location_id) : null;

// Helper to extract categorized figures from BS array
function parse_bs_data($bs) {
  if (!$bs) return null;
  $cash_subtypes = ['Cash', 'cash'];
  $bank_subtypes = ['Bank', 'bank'];
  $inv_subtypes  = ['Inventory Asset', 'inventory', 'Inventory'];
  $ar_subtypes   = ['Accounts Receivable', 'receivable'];
  $fa_subtypes   = ['Fixed Asset'];
  $ca_subtypes   = ['Contra Asset'];
  
  $cash = 0; $bank = 0; $fd = 0; $ar = 0; $inv = 0;
  $fa = 0; $contra = 0; $other_assets = [];
  
  foreach ($bs['assets'] as $a) {
    $sub = $a['subtype'] ?? '';
    $name = $a['name'] ?? '';
    if (stripos($name, 'Fixed Deposit') !== false || stripos($name, 'FD ') !== false || stripos($sub, 'Fixed Deposit') !== false) {
      $fd += $a['balance'];
    } elseif (in_array($sub, $cash_subtypes) || stripos($name, 'Cash') !== false) {
      $cash += $a['balance'];
    } elseif (in_array($sub, $bank_subtypes)) {
      $bank += $a['balance'];
    } elseif (in_array($sub, $ar_subtypes)) {
      $ar += $a['balance'];
    } elseif (in_array($sub, $inv_subtypes)) {
      $inv += $a['balance'];
    } elseif (in_array($sub, $ca_subtypes) || stripos($name, 'deprec') !== false) {
      $contra += abs($a['balance']);
    } elseif (in_array($sub, $fa_subtypes)) {
      $fa += $a['balance'];
    } else {
      $other_assets[$name] = ($other_assets[$name] ?? 0) + $a['balance'];
    }
  }
  
  $total_curr_assets = $cash + $bank + $fd + $ar + $inv;
  $total_other_assets = array_sum($other_assets) + $fa - $contra;
  
  // Liabilities
  $ap = 0; $tax = 0; $other_liabs = [];
  $tax_keywords = ['VAT', 'Tax', 'Duty', 'Excise'];
  foreach ($bs['liabilities'] as $l) {
    $sub = $l['subtype'] ?? '';
    $name = $l['name'] ?? '';
    if (in_array($sub, ['Accounts Payable', 'payable'])) {
      $ap += $l['balance'];
    } else {
      $is_tax = false;
      foreach ($tax_keywords as $kw) {
        if (stripos($name, $kw) !== false || stripos($sub, $kw) !== false) { $is_tax = true; break; }
      }
      if ($is_tax) {
        $tax += $l['balance'];
      } else {
        $other_liabs[$name] = ($other_liabs[$name] ?? 0) + $l['balance'];
      }
    }
  }
  
  // Equity
  $equity_rows = [];
  foreach ($bs['equity'] as $e) {
    $equity_rows[$e['name']] = ($equity_rows[$e['name']] ?? 0) + $e['balance'];
  }
  
  return [
    'cash' => $cash,
    'bank' => $bank,
    'fd' => $fd,
    'ar' => $ar,
    'inv' => $inv,
    'total_curr_assets' => $total_curr_assets,
    'fixed_assets' => $fa,
    'contra_assets' => $contra,
    'other_assets' => $other_assets,
    'total_other_assets' => $total_other_assets,
    'total_assets' => $bs['total_assets'],
    'ap' => $ap,
    'tax' => $tax,
    'other_liabs' => $other_liabs,
    'total_liab' => $bs['total_liabilities'],
    'equity_rows' => $equity_rows,
    'net_income' => $bs['net_income'],
    'total_equity' => $bs['total_equity'],
    'total_liab_equity' => $bs['total_liab_equity'],
    'is_balanced' => $bs['is_balanced']
  ];
}

$p_this = parse_bs_data($bs_this);
$p_prev = parse_bs_data($bs_prev);

function g_val($data, $key, $sub_key = null) {
  if (!$data) return 0.0;
  if ($sub_key !== null) return (float)($data[$key][$sub_key] ?? 0.0);
  return (float)($data[$key] ?? 0.0);
}

/**
 * Renders variance columns (variance amount, percentage)
 */
function render_var_cols($this_val, $prev_val, $is_bold = false)
{
  $variance = $this_val - $prev_val;

  if (abs($variance) < 0.005) {
    return '<td style="text-align:right">-</td><td style="text-align:right">-</td>';
  }

  $color = $variance > 0 ? '#1a7f37' : '#c00';
  $sign = $variance > 0 ? '+' : '-';
  $formatted_var = rpt_currency(abs($variance));

  $pct = $prev_val != 0 ? ($variance / abs($prev_val)) * 100 : 100;
  $formatted_pct = number_format($pct, 1) . '%';
  if ($pct > 0)
    $formatted_pct = '+' . $formatted_pct;

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
  @media print {
    .ns-header, .ns-nav, .rpt-toolbar, form, .status-bar, .no-print { display: none !important; }
  }
</style>

<?php rpt_filter_bar('Comparative Balance Sheet', [
  ['name' => 'fy_id', 'label' => 'Fiscal Year', 'type' => 'select', 'default' => $selected_fy_id, 'options' => $fy_options],
  rpt_location_filter(),
], 'tbl-comp-bs'); ?>

<div class="status-bar no-print">
  <div class="status-bar-box"
    style="<?= $p_this['is_balanced'] ? 'background:#d4edda;color:#1a7f37' : 'background:#f8d7da;color:#842029' ?>">
    <?= $this_fy ? htmlspecialchars($this_fy['name']) : 'Current Period' ?>:
    <?= $p_this['is_balanced'] ? 'Balanced ✓' : 'Out of Balance ✗' ?>
  </div>
  <?php if ($prev_fy): ?>
    <div class="status-bar-box"
      style="<?= ($p_prev['is_balanced'] ?? false) ? 'background:#d4edda;color:#1a7f37' : 'background:#f8d7da;color:#842029' ?>">
      <?= htmlspecialchars($prev_fy['name']) ?>: <?= ($p_prev['is_balanced'] ?? false) ? 'Balanced ✓' : 'Out of Balance ✗' ?>
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
        <tr>
          <td class="sec-hdr" colspan="5">ASSETS</td>
        </tr>
        <tr>
          <td class="sub-sec-hdr" colspan="5">Current Assets</td>
        </tr>
        <tr>
          <td class="indent-1"><i class="fas fa-coins" style="color:#9a6700;margin-right:6px"></i>Cash on Hand</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'cash')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'cash')) ?></td>
          <?= render_var_cols(g_val($p_this, 'cash'), g_val($p_prev, 'cash')) ?>
        </tr>
        <tr>
          <td class="indent-1"><i class="fas fa-university" style="color:#003087;margin-right:6px"></i>Bank / Digital Balance</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'bank')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'bank')) ?></td>
          <?= render_var_cols(g_val($p_this, 'bank'), g_val($p_prev, 'bank')) ?>
        </tr>
        <?php if (g_val($p_this, 'fd') > 0 || g_val($p_prev, 'fd') > 0): ?>
        <tr>
          <td class="indent-1"><i class="fas fa-piggy-bank" style="color:#2b6cb0;margin-right:6px"></i>Fixed Deposit Account</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'fd')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'fd')) ?></td>
          <?= render_var_cols(g_val($p_this, 'fd'), g_val($p_prev, 'fd')) ?>
        </tr>
        <?php endif; ?>
        <tr>
          <td class="indent-1"><i class="fas fa-file-invoice-dollar" style="color:#1a7f37;margin-right:6px"></i>Accounts Receivable (AR)</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'ar')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'ar')) ?></td>
          <?= render_var_cols(g_val($p_this, 'ar'), g_val($p_prev, 'ar')) ?>
        </tr>
        <tr>
          <td class="indent-1"><i class="fas fa-boxes" style="color:#6f42c1;margin-right:6px"></i>Inventory (at Cost)</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'inv')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'inv')) ?></td>
          <?= render_var_cols(g_val($p_this, 'inv'), g_val($p_prev, 'inv')) ?>
        </tr>
        <tr class="total-row">
          <td style="padding-left:20px">Total Current Assets</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'total_curr_assets')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'total_curr_assets')) ?></td>
          <?= render_var_cols(g_val($p_this, 'total_curr_assets'), g_val($p_prev, 'total_curr_assets'), true) ?>
        </tr>

        <!-- Non-Current Assets -->
        <tr>
          <td class="sub-sec-hdr" colspan="5">Non-Current &amp; Other Assets</td>
        </tr>
        <?php 
        $all_oa_keys = array_unique(array_merge(array_keys($p_this['other_assets'] ?? []), array_keys($p_prev['other_assets'] ?? [])));
        foreach ($all_oa_keys as $oan):
          $v_this = g_val($p_this, 'other_assets', $oan);
          $v_prev = g_val($p_prev, 'other_assets', $oan);
        ?>
        <tr>
          <td class="indent-1"><i class="fas fa-layer-group" style="color:#4a5568;margin-right:6px"></i><?= htmlspecialchars($oan) ?></td>
          <td style="text-align:right"><?= rpt_currency($v_this) ?></td>
          <td style="text-align:right"><?= rpt_currency($v_prev) ?></td>
          <?= render_var_cols($v_this, $v_prev) ?>
        </tr>
        <?php endforeach; ?>
        <?php if (g_val($p_this, 'fixed_assets') > 0 || g_val($p_prev, 'fixed_assets') > 0): ?>
        <tr>
          <td class="indent-1"><i class="fas fa-building" style="color:#4a5568;margin-right:6px"></i>Fixed Assets (Equipment)</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'fixed_assets')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'fixed_assets')) ?></td>
          <?= render_var_cols(g_val($p_this, 'fixed_assets'), g_val($p_prev, 'fixed_assets')) ?>
        </tr>
        <?php endif; ?>
        <?php if (g_val($p_this, 'contra_assets') > 0 || g_val($p_prev, 'contra_assets') > 0): ?>
        <tr style="color:#c00">
          <td class="indent-1"><i class="fas fa-minus-circle" style="color:#c00;margin-right:6px"></i>Less: Accumulated Depreciation</td>
          <td style="text-align:right">( <?= rpt_currency(g_val($p_this, 'contra_assets')) ?> )</td>
          <td style="text-align:right">( <?= rpt_currency(g_val($p_prev, 'contra_assets')) ?> )</td>
          <?= render_var_cols(-g_val($p_this, 'contra_assets'), -g_val($p_prev, 'contra_assets')) ?>
        </tr>
        <?php endif; ?>
        <tr class="total-row">
          <td style="padding-left:20px">Total Non-Current Assets</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'total_other_assets')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'total_other_assets')) ?></td>
          <?= render_var_cols(g_val($p_this, 'total_other_assets'), g_val($p_prev, 'total_other_assets'), true) ?>
        </tr>

        <tr class="grand-total-row">
          <td>TOTAL ASSETS</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'total_assets')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'total_assets')) ?></td>
          <?= render_var_cols(g_val($p_this, 'total_assets'), g_val($p_prev, 'total_assets'), true) ?>
        </tr>

        <!-- ─── LIABILITIES ─── -->
        <tr>
          <td class="sec-hdr" colspan="5">LIABILITIES</td>
        </tr>
        <tr>
          <td class="indent-1"><i class="fas fa-file-invoice" style="color:#c00;margin-right:6px"></i>Accounts Payable (AP)</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'ap')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'ap')) ?></td>
          <?= render_var_cols(g_val($p_this, 'ap'), g_val($p_prev, 'ap')) ?>
        </tr>
        <?php if (g_val($p_this, 'tax') != 0 || g_val($p_prev, 'tax') != 0): ?>
        <tr>
          <td class="indent-1"><i class="fas fa-percent" style="color:#e67e22;margin-right:6px"></i>Tax Credit / VAT Receivable</td>
          <td style="text-align:right"><?= g_val($p_this, 'tax') < 0 ? '( '.rpt_currency(abs(g_val($p_this, 'tax'))).' )' : rpt_currency(g_val($p_this, 'tax')) ?></td>
          <td style="text-align:right"><?= g_val($p_prev, 'tax') < 0 ? '( '.rpt_currency(abs(g_val($p_prev, 'tax'))).' )' : rpt_currency(g_val($p_prev, 'tax')) ?></td>
          <?= render_var_cols(g_val($p_this, 'tax'), g_val($p_prev, 'tax')) ?>
        </tr>
        <?php endif; ?>
        <?php 
        $all_ol_keys = array_unique(array_merge(array_keys($p_this['other_liabs'] ?? []), array_keys($p_prev['other_liabs'] ?? [])));
        foreach ($all_ol_keys as $oln):
          $v_this = g_val($p_this, 'other_liabs', $oln);
          $v_prev = g_val($p_prev, 'other_liabs', $oln);
        ?>
        <tr>
          <td class="indent-1"><i class="fas fa-wallet" style="color:#c00;margin-right:6px"></i><?= htmlspecialchars($oln) ?></td>
          <td style="text-align:right"><?= $v_this < 0 ? '( '.rpt_currency(abs($v_this)).' )' : rpt_currency($v_this) ?></td>
          <td style="text-align:right"><?= $v_prev < 0 ? '( '.rpt_currency(abs($v_prev)).' )' : rpt_currency($v_prev) ?></td>
          <?= render_var_cols($v_this, $v_prev) ?>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row" style="background:#fdf2f2;color:#c00">
          <td style="padding-left:20px">Total Liabilities</td>
          <td style="text-align:right"><?= g_val($p_this, 'total_liab') < 0 ? '( '.rpt_currency(abs(g_val($p_this, 'total_liab'))).' )' : rpt_currency(g_val($p_this, 'total_liab')) ?></td>
          <td style="text-align:right"><?= g_val($p_prev, 'total_liab') < 0 ? '( '.rpt_currency(abs(g_val($p_prev, 'total_liab'))).' )' : rpt_currency(g_val($p_prev, 'total_liab')) ?></td>
          <?= render_var_cols(g_val($p_this, 'total_liab'), g_val($p_prev, 'total_liab'), true) ?>
        </tr>

        <!-- ─── EQUITY ─── -->
        <tr>
          <td class="sec-hdr" colspan="5">EQUITY</td>
        </tr>
        <?php 
        $all_eq_keys = array_unique(array_merge(array_keys($p_this['equity_rows'] ?? []), array_keys($p_prev['equity_rows'] ?? [])));
        foreach ($all_eq_keys as $eqn):
          $v_this = g_val($p_this, 'equity_rows', $eqn);
          $v_prev = g_val($p_prev, 'equity_rows', $eqn);
        ?>
        <tr>
          <td class="indent-1"><i class="fas fa-coins" style="color:#6f42c1;margin-right:6px"></i><?= htmlspecialchars($eqn) ?></td>
          <td style="text-align:right"><?= $v_this < 0 ? '( '.rpt_currency(abs($v_this)).' )' : rpt_currency($v_this) ?></td>
          <td style="text-align:right"><?= $v_prev < 0 ? '( '.rpt_currency(abs($v_prev)).' )' : rpt_currency($v_prev) ?></td>
          <?= render_var_cols($v_this, $v_prev) ?>
        </tr>
        <?php endforeach; ?>
        <tr>
          <td class="indent-1"><i class="fas fa-chart-line" style="color:<?= g_val($p_this, 'net_income')>=0?'#1a7f37':'#c00' ?>;margin-right:6px"></i>Cumulative Net <?= g_val($p_this, 'net_income')>=0?'Profit':'Loss' ?></td>
          <td style="text-align:right"><?= g_val($p_this, 'net_income') < 0 ? '( '.rpt_currency(abs(g_val($p_this, 'net_income'))).' )' : rpt_currency(g_val($p_this, 'net_income')) ?></td>
          <td style="text-align:right"><?= g_val($p_prev, 'net_income') < 0 ? '( '.rpt_currency(abs(g_val($p_prev, 'net_income'))).' )' : rpt_currency(g_val($p_prev, 'net_income')) ?></td>
          <?= render_var_cols(g_val($p_this, 'net_income'), g_val($p_prev, 'net_income')) ?>
        </tr>
        <tr class="total-row" style="background:#f0fff4;color:#1a7f37">
          <td style="padding-left:20px">Total Equity</td>
          <td style="text-align:right"><?= g_val($p_this, 'total_equity') < 0 ? '( '.rpt_currency(abs(g_val($p_this, 'total_equity'))).' )' : rpt_currency(g_val($p_this, 'total_equity')) ?></td>
          <td style="text-align:right"><?= g_val($p_prev, 'total_equity') < 0 ? '( '.rpt_currency(abs(g_val($p_prev, 'total_equity'))).' )' : rpt_currency(g_val($p_prev, 'total_equity')) ?></td>
          <?= render_var_cols(g_val($p_this, 'total_equity'), g_val($p_prev, 'total_equity'), true) ?>
        </tr>

        <tr class="grand-total-row">
          <td>TOTAL LIABILITIES &amp; EQUITY</td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_this, 'total_liab_equity')) ?></td>
          <td style="text-align:right"><?= rpt_currency(g_val($p_prev, 'total_liab_equity')) ?></td>
          <?= render_var_cols(g_val($p_this, 'total_liab_equity'), g_val($p_prev, 'total_liab_equity'), true) ?>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<script>
  function exportTableToCSV(id) { 
    const t = document.getElementById(id); 
    let csv = []; 
    t.querySelectorAll('tr').forEach(r => { 
      let row = []; 
      r.querySelectorAll('th,td').forEach(c => row.push('"' + c.innerText.replace(/"/g, '""') + '"')); 
      csv.push(row.join(',')) 
    }); 
    const b = new Blob([csv.join('\n')], { type: 'text/csv' }); 
    const a = document.createElement('a'); 
    a.href = URL.createObjectURL(b); 
    a.download = 'comparative_balance_sheet.csv'; 
    a.click() 
  }
</script>