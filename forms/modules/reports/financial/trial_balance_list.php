<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$date_from = $_GET['date_from'] ?? $month_start;
$date_to = $_GET['date_to'] ?? $today;

require_once 'api/reference_helper.php';

// Build trial balance by aggregating all journal entries grouped by account in the selected date range
$loc_sql = rpt_location_sql('h');
$sql = "
    SELECT 
        a.account_name,
        a.account_type,
        SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE 0 END) as total_debit,
        SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE 0 END) as total_credit
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE j.entry_date BETWEEN ? AND ? AND a.is_deleted = 0 AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    GROUP BY a.id, a.account_name, a.account_type
    HAVING (total_debit != 0 OR total_credit != 0)
    ORDER BY a.account_name ASC
";

$gl_data = $db->fetchAll($sql, [$date_from, $date_to]);

$rows = [];
$debit_total = 0.0;
$credit_total = 0.0;

foreach ($gl_data as $data) {
  $net = $data['total_debit'] - $data['total_credit'];
  $dr = 0;
  $cr = 0;

  if ($net > 0) {
    $dr = abs($net);
  } else {
    $cr = abs($net);
  }

  if ($dr != 0 || $cr != 0) {
    $rows[] = [
      'code' => '',
      'name' => $data['account_name'],
      'type' => ucfirst($data['account_type']),
      'debit' => $dr,
      'credit' => $cr
    ];
    $debit_total += $dr;
    $credit_total += $cr;
  }
}

$difference = abs($debit_total - $credit_total);
$is_balanced = $difference < 0.05;

$type_colors = [
  'Asset' => '#003087',
  'Liability' => '#c00',
  'Income' => '#1a7f37',
  'Expense' => '#9a6700',
  'Equity' => '#6f42c1'
];
?>
<style>
  .tb-balance-ok {
    text-align: center;
    padding: 12px 20px;
    background: #d4edda;
    color: #1a7f37;
    font-weight: 700;
    border-radius: 6px;
    font-size: 13px;
    margin-top: 16px
  }

  .tb-balance-err {
    text-align: center;
    padding: 12px 20px;
    background: #f8d7da;
    color: #842029;
    font-weight: 700;
    border-radius: 6px;
    font-size: 13px;
    margin-top: 16px
  }

  @media print {

    .no-print,
    .tb-status-row {
      display: none !important;
    }
  }
</style>

<?php rpt_filter_bar('Trial Balance', [
  ['name' => 'date_from', 'label' => 'From Date', 'type' => 'date', 'default' => $date_from],
  ['name' => 'date_to', 'label' => 'To Date', 'type' => 'date', 'default' => $date_to],
  rpt_location_filter(),
], 'tbl-trial'); ?>

<div class="ns-portlet">
  <div class="ns-portlet-content" style="padding:0">
    <table class="ns-table" id="tbl-trial">
      <thead>
        <tr>
          <th style="width:80px">Code</th>
          <th>Account Name</th>
          <th style="width:100px">Type</th>
          <th style="text-align:right;width:160px">Debit (Dr)</th>
          <th style="text-align:right;width:160px">Credit (Cr)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($rows)):
          foreach ($rows as $r):
            $tc = $type_colors[$r['type']] ?? '#888';
            ?>
            <tr>
              <td style="font-weight:700;color:#888;font-size:12px"><?= $r['code'] ?></td>
              <td style="font-weight:600"><?= $r['name'] ?></td>
              <td>
                <span
                  style="background:<?= $tc ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">
                  <?= $r['type'] ?>
                </span>
              </td>
              <td style="text-align:right;font-weight:600;color:<?= $r['debit'] > 0 ? '#003087' : '#ccc' ?>">
                <?= $r['debit'] > 0 ? rpt_currency($r['debit']) : '—' ?>
              </td>
              <td style="text-align:right;font-weight:600;color:<?= $r['credit'] > 0 ? '#c00' : '#ccc' ?>">
                <?= $r['credit'] > 0 ? rpt_currency($r['credit']) : '—' ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr style="background:#003087;color:#fff;font-weight:900;font-size:14px">
          <td colspan="3" style="padding:12px 16px">TOTALS</td>
          <td style="text-align:right;padding:12px 16px"><?= rpt_currency($debit_total) ?></td>
          <td style="text-align:right;padding:12px 16px"><?= rpt_currency($credit_total) ?></td>
        </tr>
        <tr class="no-print tb-status-row"
          style="background:<?= $is_balanced ? '#d4edda' : '#f8d7da' ?>;font-weight:700;font-size:13px">
          <td colspan="3" style="padding:10px 16px;color:<?= $is_balanced ? '#1a7f37' : '#842029' ?>">
            <?= $is_balanced
              ? '<i class="fas fa-check-circle"></i> Trial Balance is BALANCED'
              : '<i class="fas fa-exclamation-triangle"></i> UNBALANCED — Difference' ?>
          </td>
          <td colspan="2" style="text-align:right;padding:10px 16px;color:<?= $is_balanced ? '#1a7f37' : '#842029' ?>">
            <?= $is_balanced ? '✓' : rpt_currency($difference) ?>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>