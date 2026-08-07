<?php
/**
 * Vendor Accounts Payable (AP) Balance Summary Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$today   = date('Y-m-d');
$date_to = $_GET['date_to'] ?? $today;

$vendors = $db->fetchAll("SELECT id, vendor_code, company_name FROM vendors WHERE is_deleted = 0 ORDER BY company_name ASC");

$vendor_balances = [];
$total_ap_balance = 0.0;

foreach ($vendors as $v) {
    $ag = get_vendor_aging_summary($db, $v['id'], $date_to);
    $net_bal = (float)($ag['total_due'] ?? 0.0);

    if (abs($net_bal) > 0.005) {
        $vendor_balances[] = [
            'id'      => $v['vendor_code'] ?: $v['id'],
            'name'    => $v['company_name'],
            'balance' => $net_bal
        ];
        $total_ap_balance += $net_bal;
    }
}
?>

<?php rpt_filter_bar('Vendor AP Balance Summary', [
    ['name' => 'date_to', 'label' => 'As of Date', 'type' => 'date', 'default' => $date_to],
], 'tbl-ap-summary'); ?>

<div class="ns-portlet" style="max-width: 950px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding:14px 18px; background:#fff5f5; border:1px solid #fed7d7; border-radius:8px;">
            <div style="font-size:13px; font-weight:700; color:#9b2c2c;">
                <i class="fas fa-building"></i> Total Accounts Payable Outstanding Balance (As of <?= rpt_date($date_to) ?>)
            </div>
            <div style="font-size:20px; font-weight:800; color:#c53030;">
                <?= rpt_currency($total_ap_balance) ?>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-ap-summary">
            <thead>
                <tr>
                    <th>Vendor ID</th>
                    <th>Vendor Company Name</th>
                    <th style="text-align:right">Outstanding Payable (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendor_balances)): ?>
                    <tr><td colspan="3" style="text-align:center; padding:30px; color:#94a3b8;">No vendor payables outstanding as of selected date.</td></tr>
                <?php else: ?>
                    <?php foreach ($vendor_balances as $vb): ?>
                        <tr>
                            <td style="font-weight:700; color:#64748b;"><?= htmlspecialchars($vb['id']) ?></td>
                            <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($vb['name']) ?></td>
                            <td style="text-align:right; font-weight:700; color:#dc2626;"><?= rpt_currency((float)$vb['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:14px">
                    <td colspan="2" style="padding:12px 16px">TOTAL ACCOUNTS PAYABLE</td>
                    <td style="text-align:right; padding:12px 16px"><?= rpt_currency($total_ap_balance) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>

