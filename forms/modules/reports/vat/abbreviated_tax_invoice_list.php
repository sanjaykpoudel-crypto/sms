<?php
require_once 'database/DBConnection.php';
$db = db();

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$payment_method = $_GET['payment_method'] ?? '';

$sql = "
    SELECT p.*, c.full_name as customer_name, c.pan_number as customer_pan, u.full_name as user_name
    FROM pos_entry p
    LEFT JOIN customers c ON p.customer_id = c.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.is_deleted = 0 AND p.status != 'voided'
      AND DATE(p.date_time) >= ? AND DATE(p.date_time) <= ?
";
$params = [$from_date, $to_date];

if (!empty($payment_method)) {
    $sql .= " AND LOWER(p.payment_method) = LOWER(?)";
    $params[] = $payment_method;
}

$sql .= " ORDER BY p.date_time DESC";
$rows = $db->fetchAll($sql, $params);

// Totals
$tot_gross = 0;
$tot_discount = 0;
$tot_taxable = 0;
$tot_vat = 0;
$tot_net = 0;
?>

<div class="ns-page-header">
    <h1 class="ns-page-title">
        <i class="fas fa-receipt"></i> Abbreviated Tax Invoice Register (लघु कर बिजक दर्ता)
        <div style="font-size: 12px; color: #7f8c8d; font-weight: normal; margin-top: 4px;">
            IRD Nepal Compliance POS Register — <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
        </div>
    </h1>
    <div class="ns-page-actions">
        <button class="ns-btn ns-btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Register</button>
        <a href="?page=reports/reports_list" class="ns-btn">Back to Reports</a>
    </div>
</div>

<div class="ns-filter-card" style="background: #fff; padding: 15px 20px; border-radius: 12px; border: 1px solid #eef2f6; margin-bottom: 20px;">
    <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="page" value="reports/vat/abbreviated_tax_invoice">
        <div>
            <label class="ns-label">From Date</label>
            <input type="date" name="from_date" class="ns-input" value="<?php echo htmlspecialchars($from_date); ?>">
        </div>
        <div>
            <label class="ns-label">To Date</label>
            <input type="date" name="to_date" class="ns-input" value="<?php echo htmlspecialchars($to_date); ?>">
        </div>
        <div>
            <label class="ns-label">Payment Method</label>
            <select name="payment_method" class="ns-select">
                <option value="">All Methods</option>
                <option value="cash" <?php echo $payment_method == 'cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="esewa" <?php echo $payment_method == 'esewa' ? 'selected' : ''; ?>>Esewa</option>
                <option value="khalti" <?php echo $payment_method == 'khalti' ? 'selected' : ''; ?>>Khalti</option>
                <option value="card" <?php echo $payment_method == 'card' ? 'selected' : ''; ?>>Card</option>
                <option value="bank" <?php echo $payment_method == 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
            </select>
        </div>
        <button type="submit" class="ns-btn ns-btn-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="?page=reports/vat/abbreviated_tax_invoice" class="ns-btn">Reset</a>
    </form>
</div>

<div class="ns-card" style="background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #eef2f6;">
    <div style="overflow-x: auto;">
        <table class="ns-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                    <th style="padding: 10px;">#</th>
                    <th style="padding: 10px;">Invoice No</th>
                    <th style="padding: 10px;">Date & Time</th>
                    <th style="padding: 10px;">Customer</th>
                    <th style="padding: 10px;">Payment Mode</th>
                    <th style="padding: 10px; text-align: right;">Gross Subtotal</th>
                    <th style="padding: 10px; text-align: right;">Discount</th>
                    <th style="padding: 10px; text-align: right;">Taxable Sales</th>
                    <th style="padding: 10px; text-align: right;">13% VAT</th>
                    <th style="padding: 10px; text-align: right;">Net Amount</th>
                    <th style="padding: 10px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="11" style="text-align: center; padding: 30px; color: #94a3b8;">
                        <i class="fas fa-inbox fa-2x" style="margin-bottom: 10px; display: block;"></i>
                        No Abbreviated Tax Invoices found for the selected period.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($rows as $idx => $r): 
                    $net = (float)$r['total_amount'];
                    $disc = (float)$r['discount'];
                    $gross = $net + $disc;
                    $taxable = round($net / 1.13, 2);
                    $vat = round($net - $taxable, 2);

                    $tot_gross += $gross;
                    $tot_discount += $disc;
                    $tot_taxable += $taxable;
                    $tot_vat += $vat;
                    $tot_net += $net;
                ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px;"><?php echo $idx + 1; ?></td>
                    <td style="padding: 10px; font-weight: 600; color: #003087;"><?php echo htmlspecialchars($r['invoice_no']); ?></td>
                    <td style="padding: 10px;"><?php echo date('Y-m-d H:i', strtotime($r['date_time'])); ?></td>
                    <td style="padding: 10px;"><?php echo htmlspecialchars($r['customer_name'] ?? 'Walk-in Customer'); ?></td>
                    <td style="padding: 10px;">
                        <span style="background: #eef2ff; color: #3730a3; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                            <?php echo htmlspecialchars($r['payment_method'] ?? 'CASH'); ?>
                        </span>
                    </td>
                    <td style="padding: 10px; text-align: right;">Rs <?php echo number_format($gross, 2); ?></td>
                    <td style="padding: 10px; text-align: right; color: #ef4444;">- Rs <?php echo number_format($disc, 2); ?></td>
                    <td style="padding: 10px; text-align: right;">Rs <?php echo number_format($taxable, 2); ?></td>
                    <td style="padding: 10px; text-align: right; color: #059669;">Rs <?php echo number_format($vat, 2); ?></td>
                    <td style="padding: 10px; text-align: right; font-weight: 700; color: #0f172a;">Rs <?php echo number_format($net, 2); ?></td>
                    <td style="padding: 10px; text-align: center;">
                        <a href="api/print_pos.php?id=<?php echo $r['id']; ?>" target="_blank" class="ns-btn ns-btn-outline" style="padding: 4px 10px; font-size: 12px;">
                            <i class="fas fa-print"></i> Receipt
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr style="background: #f8fafc; font-weight: bold; border-top: 2px solid #cbd5e1;">
                    <td colspan="5" style="padding: 12px 10px;">Total (<?php echo count($rows); ?> Invoices):</td>
                    <td style="padding: 12px 10px; text-align: right;">Rs <?php echo number_format($tot_gross, 2); ?></td>
                    <td style="padding: 12px 10px; text-align: right; color: #ef4444;">- Rs <?php echo number_format($tot_discount, 2); ?></td>
                    <td style="padding: 12px 10px; text-align: right;">Rs <?php echo number_format($tot_taxable, 2); ?></td>
                    <td style="padding: 12px 10px; text-align: right; color: #059669;">Rs <?php echo number_format($tot_vat, 2); ?></td>
                    <td style="padding: 12px 10px; text-align: right; color: #003087; font-weight: 800; font-size: 15px;">Rs <?php echo number_format($tot_net, 2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
