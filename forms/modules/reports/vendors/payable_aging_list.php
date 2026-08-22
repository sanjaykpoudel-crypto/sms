<?php
/**
 * Accounts Payable (AP) Aging Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';

$db = db();
$today = date('Y-m-d');

// Fetch vendor aging outstanding balances (Net Outstanding = Bills - Applied Payments)
$sql = "
    SELECT 
        v.vendor_code,
        v.company_name as vendor_name,
        COALESCE(SUM(open_docs.balance_due), 0.00) as total_due,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) <= 30 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_30,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) BETWEEN 31 AND 60 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) BETWEEN 61 AND 90 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_90,
        COALESCE(SUM(CASE WHEN DATEDIFF(?, open_docs.doc_date) > 90 THEN open_docs.balance_due ELSE 0.00 END), 0.00) as bucket_over_90
    FROM vendors v
    JOIN (
        -- Open Vendor Bills (Net balance after payments, aged by Due Date)
        SELECT 
            vb.vendor_id, 
            COALESCE(vb.due_date, vb.bill_date) as doc_date, 
            vb.balance_due
        FROM vendor_bills vb
        JOIN transaction_headers h ON vb.header_id = h.id 
        WHERE h.is_deleted = 0 
          AND h.status NOT IN ('void', 'voided', 'draft')
          AND vb.balance_due > 0.001

        UNION ALL

        -- Open Journal Entries to Accounts Payable
        SELECT 
            COALESCE(jl.entity_id, h.party_id) as vendor_id,
            h.txn_date as doc_date,
            (
                SUM(jl.credit - jl.debit) 
                - COALESCE((
                    SELECT SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)))
                    FROM transaction_links tl
                    JOIN transaction_headers ph ON tl.parent_id = ph.id
                    WHERE tl.child_id = h.id 
                      AND tl.link_type LIKE 'payment:%' 
                      AND ph.is_deleted = 0 
                      AND ph.status NOT IN ('void', 'voided', 'draft')
                ), 0.00)
            ) as balance_due
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN transaction_headers h ON je.transaction_id = h.id
        JOIN accounts a ON jl.account_id = a.id
        WHERE (jl.entity_type = 'VENDOR' OR jl.entity_type IS NULL) 
          AND a.account_subtype IN ('Accounts Payable', 'payable')
          AND h.is_deleted = 0 
          AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.txn_type IN ('Journal', 'journal_entry', 'journal')
        GROUP BY h.id, jl.entity_id, h.party_id, h.txn_date
        HAVING balance_due > 0.001
    ) open_docs ON v.id = open_docs.vendor_id
    WHERE v.is_deleted = 0
    GROUP BY v.id, v.vendor_code, v.company_name
    HAVING total_due > 0.001
    ORDER BY v.company_name ASC
";

$rows = $db->fetchAll($sql, [$today, $today, $today, $today]);

$total_due = 0.0;
$total_30 = 0.0;
$total_60 = 0.0;
$total_90 = 0.0;
$total_over_90 = 0.0;

foreach ($rows as $r) {
    $total_due += (float)$r['total_due'];
    $total_30 += (float)$r['bucket_30'];
    $total_60 += (float)$r['bucket_60'];
    $total_90 += (float)$r['bucket_90'];
    $total_over_90 += (float)$r['bucket_over_90'];
}

// ── AP Subledger vs GL Reconciliation Check ──
require_once 'api/ReportingEngine.php';
$ap_gl = re_get_ap_gl_balance($db, $today);
$ap_ok = abs($total_due - $ap_gl) < 0.05;
?>
<?php rpt_header('Accounts Payable (AP) Aging Report'); ?>

<div class="ns-page-header" style="margin-bottom: 20px;">
    <h1 class="ns-page-title"><i class="fas fa-history"></i> Accounts Payable (AP) Aging Report</h1>
    <div style="font-size: 12px; color: #666; margin-top: 4px;">As of Date: <?= rpt_date($today) ?></div>
</div>

<?php if (!$ap_ok): ?>
<div class="bs-recon-warn" style="text-align:center;padding:8px 20px;margin:6px auto 16px auto;max-width:1000px;background:#fff3cd;color:#856404;font-weight:600;border-radius:6px;font-size:12px">
  <i class="fas fa-exclamation-circle"></i> AP RECONCILIATION ERROR — Subledger: <?= rpt_currency($total_due) ?> | GL: <?= rpt_currency($ap_gl) ?> | Diff: <?= rpt_currency(abs($total_due - $ap_gl)) ?>
</div>
<?php endif; ?>

<div class="rpt-summary">
  <div class="rpt-summary-card"><div class="val"><?= rpt_currency($total_due) ?></div><div class="lbl">Total Payables</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#1a7f37"><?= rpt_currency($total_30) ?></div><div class="lbl">0 - 30 Days (Current)</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#003087"><?= rpt_currency($total_60) ?></div><div class="lbl">31 - 60 Days</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#b7791f"><?= rpt_currency($total_90) ?></div><div class="lbl">61 - 90 Days</div></div>
  <div class="rpt-summary-card"><div class="val" style="color:#c00"><?= rpt_currency($total_over_90) ?></div><div class="lbl">91+ Days</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-ap-aging">
      <thead>
        <tr>
          <th>Vendor Company Name</th>
          <th style="text-align:right">Total Outstanding</th>
          <th style="text-align:right">0 - 30 Days</th>
          <th style="text-align:right">31 - 60 Days</th>
          <th style="text-align:right">61 - 90 Days</th>
          <th style="text-align:right">91+ Days</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($r['vendor_name']) ?></td>
            <td style="text-align:right; font-weight:700; color:#c00;"><?= rpt_currency($r['total_due']) ?></td>
            <td style="text-align:right; color:<?= $r['bucket_30'] > 0 ? '#1a7f37' : '#ccc' ?>"><?= $r['bucket_30'] > 0 ? rpt_currency($r['bucket_30']) : '—' ?></td>
            <td style="text-align:right; color:<?= $r['bucket_60'] > 0 ? '#003087' : '#ccc' ?>"><?= $r['bucket_60'] > 0 ? rpt_currency($r['bucket_60']) : '—' ?></td>
            <td style="text-align:right; color:<?= $r['bucket_90'] > 0 ? '#b7791f' : '#ccc' ?>"><?= $r['bucket_90'] > 0 ? rpt_currency($r['bucket_90']) : '—' ?></td>
            <td style="text-align:right; color:<?= $r['bucket_over_90'] > 0 ? '#c00' : '#ccc' ?>; font-weight:700"><?= $r['bucket_over_90'] > 0 ? rpt_currency($r['bucket_over_90']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:900; background:#003087; color:#fff">
          <th>TOTALS</th>
          <th style="text-align:right"><?= rpt_currency($total_due) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_30) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_60) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_90) ?></th>
          <th style="text-align:right"><?= rpt_currency($total_over_90) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
