<?php
/**
 * Fiscal Year Closing Report Print Layout
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$db = db();

$id = $_GET['id'] ?? '';
if (empty($id)) {
    die("Fiscal Year ID is required");
}

// Fetch Fiscal Year Details
$fy = $db->fetchOne("
    SELECT fy.*, 
           u_closed.full_name as closed_by_name,
           j.txn_number as closing_journal_no
    FROM fiscal_years fy
    LEFT JOIN users u_closed ON fy.closed_by = u_closed.id
    LEFT JOIN transaction_headers j ON fy.closing_journal_id = j.id
    WHERE fy.id = ?
", [$id]);

if (!$fy) {
    die("Fiscal Year record not found.");
}

if ($fy['status'] !== 'closed') {
    die("Closing report is only available for closed fiscal years.");
}

// Fetch closing preferences
$retained_earnings_acct = get_accounting_preference('fy_retained_earnings_account') ?: 'acc-3200';
$income_summary_acct = get_accounting_preference('fy_income_summary_account') ?: 'acc-3300';
$dividend_payable_acct = get_accounting_preference('fy_dividend_payable_account') ?: 'acc-2400';

// Calculate calculations based on preview
$start = $fy['start_date'];
$end = $fy['end_date'];

// Fetch audit log for this closing
$audit = $db->fetchOne("
    SELECT * FROM fiscal_year_audit_logs 
    WHERE fiscal_year_id = ? AND action_type = 'close'
    ORDER BY created_at DESC 
    LIMIT 1
", [$id]);

// Calculate closing figures
$revenues = $db->fetchAll("
    SELECT a.account_code, a.account_name,
           -SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as balance
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'income' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
      AND h.id != ? -- Exclude the closing journal to get pre-closing numbers
    GROUP BY a.id, a.account_code, a.account_name
    HAVING balance != 0
", [$start, $end, $fy['closing_journal_id']]);

$expenses = $db->fetchAll("
    SELECT a.account_code, a.account_name, a.account_subtype,
           SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as balance
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE a.account_type = 'expense' AND h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 AND h.status != 'void'
      AND h.id != ?
    GROUP BY a.id, a.account_code, a.account_name, a.account_subtype
    HAVING balance != 0
", [$start, $end, $fy['closing_journal_id']]);

$total_revenue = 0.0;
foreach ($revenues as $r) $total_revenue += (float)$r['balance'];

$total_cogs = 0.0;
$total_operating_expenses = 0.0;

foreach ($expenses as $e) {
    $bal = (float)$e['balance'];
    if ($e['account_subtype'] === 'cogs') {
        $total_cogs += $bal;
    } else {
        $total_operating_expenses += $bal;
    }
}

$gross_profit = $total_revenue - $total_cogs;
$net_profit = $gross_profit - $total_operating_expenses;

// Fetch Post-Closing trial balance sheet accounts ending balances (including closing entries)
$bs_balances = $db->fetchAll("
    SELECT a.account_code, a.account_name, a.account_type, a.normal_balance,
           SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) as bal
    FROM accounts a
    JOIN journal_entries j ON a.id = j.account_id
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE h.txn_date <= ? AND a.account_type IN ('asset', 'liability', 'equity') AND h.is_deleted = 0 AND h.status != 'void'
    GROUP BY a.id, a.account_code, a.account_name, a.normal_balance, a.account_type
    HAVING bal != 0
    ORDER BY a.account_name ASC
", [$end]);

$company_name = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'name'")['meta_value'] ?? 'SMS ERP';
$company_address = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'address'")['meta_value'] ?? '';
$company_phone = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'phone'")['meta_value'] ?? '';
$company_pan = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'pan_number'")['meta_value'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Fiscal Year Closing Report: <?= htmlspecialchars($fy['name']) ?></title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; margin: 30px; font-size: 13px; line-height: 1.5; }
        .print-header { text-align: center; margin-bottom: 25px; border-bottom: 2px solid #003087; padding-bottom: 12px; }
        .company-name { font-size: 20px; font-weight: bold; color: #003087; text-transform: uppercase; }
        .company-details { font-size: 11px; color: #666; margin-top: 4px; }
        .report-title { font-size: 16px; font-weight: bold; text-transform: uppercase; margin-top: 15px; letter-spacing: 0.5px; }
        
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .meta-table td { padding: 4px 8px; vertical-align: top; }
        .meta-table td.label { font-weight: bold; color: #666; width: 150px; text-transform: uppercase; font-size: 10px; }
        
        .section-title { font-size: 12px; font-weight: bold; background: #eef2ff; color: #003087; padding: 6px 10px; margin: 25px 0 10px 0; border-left: 4px solid #003087; text-transform: uppercase; }
        
        .data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .data-table th, .data-table td { padding: 7px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .data-table th { background: #f8fafc; font-weight: bold; font-size: 11px; text-transform: uppercase; color: #64748b; }
        .data-table td.amount { text-align: right; font-weight: 600; }
        .data-table tr.total-row { font-weight: bold; background: #f8fafc; border-top: 2px solid #cbd5e1; border-bottom: 2px double #003087; }
        
        .audit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; font-size: 11px; color: #475569; background: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #e2e8f0; margin-top: 30px; }
        .audit-title { font-weight: bold; margin-bottom: 6px; color: #003087; text-transform: uppercase; }
        
        @media print {
            body { margin: 15px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 6px 15px; background: #003087; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Print Report</button>
        <button onclick="window.close()" style="padding: 6px 15px; background: #f3f4f6; color: #333; border: 1px solid #ccc; border-radius: 4px; margin-left: 8px; cursor: pointer;">Close Window</button>
    </div>

    <div class="print-header">
        <div class="company-name"><?= htmlspecialchars($company_name) ?></div>
        <div class="company-details">
            <?= htmlspecialchars($company_address) ?> | Phone: <?= htmlspecialchars($company_phone) ?> <br>
            PAN No: <?= htmlspecialchars($company_pan) ?>
        </div>
        <div class="report-title">Fiscal Year Closing & Valuation Report</div>
        <div style="font-size: 12px; color: #555; font-weight: 600; margin-top: 4px;">Fiscal Period: <?= htmlspecialchars($fy['name']) ?> (<?= $start ?> to <?= $end ?>)</div>
    </div>

    <table class="meta-table">
        <tr>
            <td class="label">Fiscal Year Status:</td>
            <td style="font-weight: bold; color: #1a7f37;">CLOSED (LOCKED)</td>
            <td class="label">Closing Journal:</td>
            <td style="font-weight: bold;"><?= htmlspecialchars($fy['closing_journal_no'] ?? 'JE-CLOSE-' . $fy['name']) ?></td>
        </tr>
        <tr>
            <td class="label">Closing Date:</td>
            <td><?= $fy['closing_date'] ?></td>
            <td class="label">Closed By:</td>
            <td><?= htmlspecialchars($fy['closed_by_name'] ?? $fy['closed_by'] ?? 'System') ?></td>
        </tr>
    </table>

    <div class="section-title">Income Statement (P&L) Summary</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Classification</th>
                <th>Account Code</th>
                <th>Account Name</th>
                <th style="text-align: right;">Balance (Pre-Closing)</th>
            </tr>
        </thead>
        <tbody>
            <tr style="font-weight: bold; color: #003087;">
                <td colspan="4">REVENUES</td>
            </tr>
            <?php foreach ($revenues as $r): ?>
                <tr>
                    <td></td>
                    <td><?= $r['account_code'] ?></td>
                    <td><?= htmlspecialchars($r['account_name']) ?></td>
                    <td class="amount">NPR <?= number_format($r['balance'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="font-weight: bold; background: #f8fafc;">
                <td colspan="3" style="padding-left: 20px;">Total Revenues (A)</td>
                <td class="amount">NPR <?= number_format($total_revenue, 2) ?></td>
            </tr>
            
            <tr style="font-weight: bold; color: #c00; margin-top: 10px;">
                <td colspan="4">COST OF GOODS SOLD</td>
            </tr>
            <?php foreach ($expenses as $e): if ($e['account_subtype'] === 'cogs'): ?>
                <tr>
                    <td></td>
                    <td><?= $e['account_code'] ?></td>
                    <td><?= htmlspecialchars($e['account_name']) ?></td>
                    <td class="amount">NPR <?= number_format($e['balance'], 2) ?></td>
                </tr>
            <?php endif; endforeach; ?>
            <tr style="font-weight: bold; background: #f8fafc;">
                <td colspan="3" style="padding-left: 20px;">Total Cost of Goods Sold (B)</td>
                <td class="amount">NPR <?= number_format($total_cogs, 2) ?></td>
            </tr>
            
            <tr style="font-weight: bold; background: #eef2ff;">
                <td colspan="3">GROSS PROFIT (C = A - B)</td>
                <td class="amount">NPR <?= number_format($gross_profit, 2) ?></td>
            </tr>

            <tr style="font-weight: bold; color: #9a6700;">
                <td colspan="4">OPERATING EXPENSES</td>
            </tr>
            <?php foreach ($expenses as $e): if ($e['account_subtype'] !== 'cogs'): ?>
                <tr>
                    <td></td>
                    <td><?= $e['account_code'] ?></td>
                    <td><?= htmlspecialchars($e['account_name']) ?></td>
                    <td class="amount">NPR <?= number_format($e['balance'], 2) ?></td>
                </tr>
            <?php endif; endforeach; ?>
            <tr style="font-weight: bold; background: #f8fafc;">
                <td colspan="3" style="padding-left: 20px;">Total Operating Expenses (D)</td>
                <td class="amount">NPR <?= number_format($total_operating_expenses, 2) ?></td>
            </tr>
            
            <tr class="total-row" style="background:#e6fffa; color:#1a7f37; font-size:14px;">
                <td colspan="3">NET YEAR-END PROFIT / LOSS (E = C - D)</td>
                <td class="amount">NPR <?= number_format($net_profit, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Post-Closing Balance Sheet Carry-Forwards</div>
    <div style="font-size: 11px; color:#666; margin-bottom: 8px; font-style: italic;">
        Note: The following balances represent the opening trial balance carried forward to the next fiscal year on date <?= date('Y-m-d', strtotime($end . ' +1 day')) ?>.
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Account Code</th>
                <th>Account Name</th>
                <th>Account Type</th>
                <th style="text-align: right;">Debit (Dr)</th>
                <th style="text-align: right;">Credit (Cr)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $tot_dr = 0.0;
            $tot_cr = 0.0;
            foreach ($bs_balances as $b): 
                $val = (float)$b['bal'];
                $dr = 0;
                $cr = 0;
                
                if ($val > 0) {
                    $dr = $val;
                    $tot_dr += $dr;
                } else {
                    $cr = abs($val);
                    $tot_cr += $cr;
                }
            ?>
                <tr>
                    <td style="font-weight: bold;"><?= $b['account_code'] ?></td>
                    <td><?= htmlspecialchars($b['account_name']) ?></td>
                    <td style="text-transform: capitalize; color:#64748b;"><?= $b['account_type'] ?></td>
                    <td style="text-align: right; color:#003087;"><?= $dr > 0 ? 'NPR ' . number_format($dr, 2) : '—' ?></td>
                    <td style="text-align: right; color:#c00;"><?= $cr > 0 ? 'NPR ' . number_format($cr, 2) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row" style="font-size: 13px;">
                <td colspan="3">BALANCED CARRY-FORWARD TOTALS</td>
                <td style="text-align: right; color:#003087;">NPR <?= number_format($tot_dr, 2) ?></td>
                <td style="text-align: right; color:#c00;">NPR <?= number_format($tot_cr, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="audit-grid">
        <div>
            <div class="audit-title">Closing Audit Trail Details</div>
            <div><strong>Closed Timestamp:</strong> <?= $fy['closed_timestamp'] ?></div>
            <div><strong>Closed User ID:</strong> <?= htmlspecialchars($fy['closed_by']) ?></div>
            <div><strong>Audit Log IP Address:</strong> <?= htmlspecialchars($audit['ip_address'] ?? '127.0.0.1') ?></div>
            <div><strong>Audit Machine Name:</strong> <?= htmlspecialchars($audit['machine_name'] ?? 'Local Host') ?></div>
            <div><strong>Audit Log Version Number:</strong> v<?= $audit['version_number'] ?? '1' ?></div>
        </div>
        <div>
            <div class="audit-title">Closing Notes & Comments</div>
            <div style="font-style: italic; font-size:11px; margin-top: 4px; color:#475569;">
                <?= nl2br(htmlspecialchars($fy['notes'] ?? 'No comments.')) ?>
            </div>
        </div>
    </div>

    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    </script>
</body>
</html>
