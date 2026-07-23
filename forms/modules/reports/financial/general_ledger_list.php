<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-01-01');
$date_to   = $_GET['date_to']   ?? $today;
$raw_account = $_GET['account_id'] ?? ($_GET['account_type'] ?? '');
$account_ids = [];

if ($raw_account === 'bank' || $raw_account === 'all_bank') {
    $bank_rows = $db->fetchAll("SELECT id FROM accounts WHERE account_subtype = 'bank' AND is_active=1 AND is_deleted=0");
    $account_ids = array_column($bank_rows, 'id');
} elseif ($raw_account === 'cash' || $raw_account === 'all_cash') {
    $cash_rows = $db->fetchAll("SELECT id FROM accounts WHERE (account_subtype = 'cash' OR account_code = '1010') AND is_active=1 AND is_deleted=0");
    $account_ids = array_column($cash_rows, 'id');
} elseif (is_array($raw_account)) {
    $account_ids = array_values(array_filter($raw_account));
} elseif (is_string($raw_account) && $raw_account !== '') {
    if (strpos($raw_account, ',') !== false) {
        $account_ids = array_values(array_filter(explode(',', $raw_account)));
    } else {
        $account_ids = [$raw_account];
    }
}

// Fetch active accounts for the filter dropdown
$accounts_list = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE is_active=1 AND is_deleted=0 ORDER BY account_code ASC");
$acct_options = ['' => 'All Accounts'];
foreach ($accounts_list as $a) { 
    $acct_options[$a['id']] = $a['account_code'].' - '.$a['account_name']; 
}

require_once 'api/reference_helper.php';
$fy_start_date = get_report_start_date($date_from);

// Calculate Opening Balance and fetch normal balance if accounts are selected
$opening_bal = 0.0;
$normal_bal = 'debit';

if (!empty($account_ids)) {
    if (count($account_ids) === 1) {
        $acct_info = $db->fetchOne("SELECT normal_balance FROM accounts WHERE id = ?", [$account_ids[0]]);
        $normal_bal = $acct_info['normal_balance'] ?? 'debit';
    }

    $placeholders = implode(',', array_fill(0, count($account_ids), '?'));
    $op_params = array_merge($account_ids, [$fy_start_date, $date_from]);

    $op_row = $db->fetchOne("
        SELECT SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
        FROM journal_entries j
        JOIN transaction_headers h ON j.header_id = h.id
        WHERE j.account_id IN ($placeholders)
          AND j.entry_date >= ? AND j.entry_date < ? 
          AND h.is_deleted = 0 
          AND h.status NOT IN ('void', 'voided', 'draft')
    ", $op_params);
    $opening_bal = (float)($op_row['bal'] ?? 0.0);
}

// Build the query from journal_entries
$where = "j.entry_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')";
$params = [$date_from, $date_to];

if (!empty($account_ids)) {
    $placeholders = implode(',', array_fill(0, count($account_ids), '?'));
    $where .= " AND j.account_id IN ($placeholders)";
    $params = array_merge($params, $account_ids);
}

$sql = "
    SELECT 
        j.entry_date,
        h.txn_number,
        h.txn_type,
        j.memo,
        a.account_name,
        a.account_code,
        j.entry_type,
        j.amount,
        CASE 
            WHEN h.party_type = 'customer' THEN (SELECT full_name FROM customers WHERE id = h.party_id)
            WHEN h.party_type = 'vendor' THEN (SELECT company_name FROM vendors WHERE id = h.party_id)
            ELSE '-'
        END as party
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    JOIN accounts a ON j.account_id = a.id
    WHERE $where
    ORDER BY j.entry_date DESC, h.updated_at DESC
";

$rows = $db->fetchAll($sql, $params);

$total_debit  = 0.0;
$total_credit = 0.0;
foreach ($rows as $r) {
    if ($r['entry_type'] === 'debit') $total_debit += (float)$r['amount'];
    else $total_credit += (float)$r['amount'];
}

$net_change = $total_debit - $total_credit;
if ($normal_bal === 'credit') {
    $net_change = -$net_change;
    $opening_bal = -$opening_bal;
}
$closing_bal = $opening_bal + $net_change;
?>

<?php rpt_filter_bar('General Ledger', [
    ['name'=>'date_from', 'label'=>'From',    'type'=>'date',        'default'=>$date_from],
    ['name'=>'date_to',   'label'=>'To',      'type'=>'date',        'default'=>$date_to],
    ['name'=>'account_id','label'=>'Accounts', 'type'=>'multiselect', 'default'=>$account_ids, 'options'=>$acct_options],
], 'tbl-ledger'); ?>

<div class="rpt-summary">
  <?php if (!empty($account_ids)): ?>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($opening_bal) ?></div><div class="lbl">Opening Balance</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_debit) ?></div><div class="lbl">Total Debits (Dr)</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_credit) ?></div><div class="lbl">Total Credits (Cr)</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($closing_bal) ?></div><div class="lbl">Closing Balance</div></div>
  <?php else: ?>
    <div class="rpt-summary-card"><div class="val"><?= count($rows) ?></div><div class="lbl">Total Entries</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_debit) ?></div><div class="lbl">Total Debits (In)</div></div>
    <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_credit) ?></div><div class="lbl">Total Credits (Out)</div></div>
    <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_debit - $total_credit) ?></div><div class="lbl">Net Change</div></div>
  <?php endif; ?>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-ledger">
      <thead>
        <tr>
          <th>Date</th>
          <th>Ref #</th>
          <th>Type</th>
          <th>Party</th>
          <th>Account</th>
          <th>Memo</th>
          <th style="text-align:right">Debit (In)</th>
          <th style="text-align:right">Credit (Out)</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr>
          <td>No entries</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['entry_date'] ?></td>
          <td style="font-weight:700"><?= htmlspecialchars($r['txn_number']) ?></td>
          <td><span class="ns-badge" style="background:#eef2ff;color:#003087;font-size:10px"><?= strtoupper($r['txn_type']) ?></span></td>
          <td style="font-size:12px"><?= htmlspecialchars($r['party']) ?></td>
          <td style="font-weight:600;font-size:12px"><?= $r['account_code'] ?> - <?= htmlspecialchars($r['account_name']) ?></td>
          <td style="font-size:11px;color:#666;max-width:200px" class="ns-text-truncate"><?= htmlspecialchars($r['memo'] ?? '-') ?></td>
          <td style="text-align:right;color:#003087;font-weight:700"><?= $r['entry_type']==='debit' ? rpt_currency($r['amount']) : '-' ?></td>
          <td style="text-align:right;color:#c00;font-weight:700"><?= $r['entry_type']==='credit' ? rpt_currency($r['amount']) : '-' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:900;background:#003087;color:#fff">
          <th>TOTAL</th>
          <th></th><th></th><th></th><th></th><th></th>
          <th style="text-align:right"><?= rpt_currency($total_debit) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_credit) ?></th>
        </tr>
      </tfoot>
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
        csv.push(row.join(','));
    });
    const b = new Blob([csv.join('\n')], {type: 'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(b);
    a.download = 'general_ledger.csv';
    a.click();
}
</script>
