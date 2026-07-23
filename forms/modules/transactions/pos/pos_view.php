<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? '';

if (!$id) {
    echo "<div class='alert alert-danger'>Invalid POS ID</div>";
    return;
}

$pos = $db->fetchOne("
    SELECT p.*, c.full_name as customer_name, u.full_name as user_name 
    FROM pos_entry p
    LEFT JOIN customers c ON p.customer_id = c.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.id = :id
", ['id' => $id]);

if (!$pos) {
    echo "<div class='alert alert-danger'>POS Transaction not found.</div>";
    return;
}

$items = $db->fetchAll("
    SELECT pi.*, i.item_name, i.sku
    FROM pos_items pi
    LEFT JOIN items i ON pi.item_id = i.id
    WHERE pi.pos_id = :id
", ['id' => $id]);

$payments = $db->fetchAll("
    SELECT pp.*, a.account_name
    FROM pos_payments pp
    LEFT JOIN accounts a ON pp.account_id = a.id
    WHERE pp.pos_id = :id
", ['id' => $id]);

// Find ERP header link
$erp_header = $db->fetchOne("SELECT id, txn_number FROM transaction_headers WHERE txn_number = ?", [$pos['invoice_no']]);
?>

<div class="ns-page-header">
    <h1 class="ns-page-title">
        <i class="fas fa-file-invoice-dollar"></i> POS Sale: <?php echo htmlspecialchars($pos['invoice_no']); ?>
        <div style="display: flex; gap: 10px;">
            <a href="api/print_pos.php?id=<?php echo $id; ?>" target="_blank" class="ns-btn ns-btn-primary"><i class="fas fa-print"></i> Print</a>
            <?php if($pos['status'] == 'completed'): ?>
                <button class="ns-btn ns-btn-danger" onclick="initiateReturn()"><i class="fas fa-undo"></i> Return / Refund</button>
            <?php endif; ?>
            <a href="?page=transactions/pos" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
        </div>
    </h1>
</div>

<div style="display: flex; gap: 20px; align-items: flex-start;">
    <!-- Main Info -->
    <div style="flex: 2;">
        <div class="ns-portlet">
            <div class="ns-portlet-header">
                <div class="ns-portlet-title">Items Sold</div>
            </div>
            <div class="ns-portlet-content">
                <table class="ns-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="text-align: right;">Qty</th>
                            <th style="text-align: right;">Rate</th>
                            <th style="text-align: right;">Amount</th>
                            <th style="text-align: right;">Discount</th>
                            <th style="text-align: right;">Tax</th>
                            <th style="text-align: right;">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($items as $item): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                <small style="color: #666;"><?php echo htmlspecialchars($item['sku']); ?></small>
                            </td>
                            <td style="text-align: right;"><?php echo number_format($item['quantity'], 2); ?></td>
                            <td style="text-align: right;"><?php echo number_format($item['rate'], 2); ?></td>
                            <td style="text-align: right;"><?php echo number_format($item['amount'], 2); ?></td>
                            <td style="text-align: right; color: #c00;"><?php echo number_format($item['discount'], 2); ?></td>
                            <td style="text-align: right;"><?php echo number_format($item['tax'], 2); ?></td>
                            <td style="text-align: right; font-weight: 600;"><?php echo number_format($item['net_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #f8f9fa; font-weight: 700;">
                            <td colspan="3" style="text-align: right;">Totals:</td>
                            <td style="text-align: right;"><?php echo number_format($pos['gross_amount'], 2); ?></td>
                            <td style="text-align: right; color: #c00;"><?php echo number_format($pos['discount_amount'], 2); ?></td>
                            <td style="text-align: right;"><?php echo number_format($pos['tax_amount'], 2); ?></td>
                            <td style="text-align: right; font-size: 16px;">Rs <?php echo number_format($pos['net_amount'], 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div style="flex: 1;">
        <div class="ns-portlet">
            <div class="ns-portlet-header">
                <div class="ns-portlet-title">Transaction Info</div>
            </div>
            <div class="ns-portlet-content">
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #666; text-transform: uppercase;">Customer</label>
                    <div style="font-weight: 600;"><?php echo htmlspecialchars($pos['customer_name'] ?: 'Walk-in Customer'); ?></div>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #666; text-transform: uppercase;">Date & Time</label>
                    <div><?php echo date('M d, Y H:i:s', strtotime($pos['date_time'])); ?></div>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #666; text-transform: uppercase;">Cashier</label>
                    <div><?php echo htmlspecialchars($pos['user_name']); ?></div>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #666; text-transform: uppercase;">Status</label>
                    <div>
                        <span class="badge" style="background: #2ecc71; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                            <?php echo strtoupper($pos['status']); ?>
                        </span>
                    </div>
                </div>
                <div style="margin-bottom: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                    <label style="font-size: 11px; color: #666; text-transform: uppercase;">ERP Link</label>
                    <div>
                        <?php if($erp_header): ?>
                            <a href="?page=transactions/view&id=<?php echo $erp_header['id']; ?>" class="ns-btn" style="width: 100%; text-align: center;">
                                <i class="fas fa-link"></i> View ERP Transaction
                            </a>
                        <?php else: ?>
                            <span style="color: #999;">No ERP record linked.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="ns-portlet">
            <div class="ns-portlet-header">
                <div class="ns-portlet-title">Payments</div>
            </div>
            <div class="ns-portlet-content">
                <table class="ns-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payments as $pay): ?>
                        <tr>
                            <td>
                                <strong><?php echo strtoupper($pay['payment_mode']); ?></strong><br>
                                <small style="color: #666;"><?php echo htmlspecialchars($pay['account_name']); ?></small>
                                <?php if($pay['reference_no']): ?>
                                    <br><small style="color: #0055aa;">Ref: <?php echo htmlspecialchars($pay['reference_no']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-weight: 600;">Rs <?php echo number_format($pay['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function initiateReturn() {
    if(confirm('Initiate a return for this POS transaction? This will open the return management screen.')) {
        window.location.href = '?page=transactions/pos/return&pos_id=<?php echo $id; ?>';
    }
}
</script>
