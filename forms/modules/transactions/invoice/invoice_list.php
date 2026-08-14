<?php
require_once 'database/DBConnection.php';
$db = db();
$list = $db->fetchAll("SELECT t.*, ci.customer_id, c.full_name as customer_name, ci.total_amount as net_amount, ci.payment_status, ci.due_date
                      FROM transaction_headers t 
                      INNER JOIN customer_invoices ci ON t.id = ci.header_id
                      LEFT JOIN customers c ON ci.customer_id = c.id
                      WHERE t.txn_type = 'customer_invoice' AND t.is_deleted = 0
                      ORDER BY t.created_at DESC");
?>

<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-file-invoice-dollar" style="color: #0284c7; margin-right: 8px;"></i> Sales Invoices
    </h1>
    <a href="?page=transactions/invoice/manage" class="ns-btn ns-btn-primary"
        style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i
            class="fas fa-plus"></i> New</a>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th>Due Date</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): ?>
                    <tr>
                        <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                        <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['customer_name'] ?? 'Walk-in Customer'); ?></td>
                        <td><?php echo $row['due_date'] ?? '-'; ?></td>
                        <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['net_amount'], 2); ?>
                        </td>
                        <td>
                            <span
                                style="color: <?php echo strtolower($row['payment_status']) == 'paid' ? '#080' : '#c00'; ?>; font-weight: 600;">
                                <?php echo strtolower($row['payment_status']) == 'paid' ? 'Paid in Full' : (ucwords($row['payment_status']) ?: 'Open'); ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <div style="position: relative; display: inline-block;">
                                <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                    Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                                </button>
                                <div class="ns-action-dropdown-menu">
                                    <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                        <i class="fas fa-eye" style="color: #64748b; width: 14px;"></i> View
                                    </a>
                                    <?php 
                                    $is_pos_inv = (!empty($row['txn_number']) && (strpos($row['txn_number'], 'INV-POS-') === 0 || strpos($row['txn_number'], 'POS-SUM-') === 0 || strpos($row['txn_number'], 'POS-') === 0));
                                    if ($is_pos_inv): 
                                    ?>
                                        <span class="ns-action-item" style="color: #94a3b8; cursor: not-allowed;" title="Edit Disabled (POS)">
                                            <i class="fas fa-lock" style="color: #94a3b8; width: 14px;"></i> Edit (Disabled)
                                        </span>
                                    <?php else: ?>
                                        <a href="?page=transactions/invoice/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                            <i class="fas fa-edit" style="color: #0284c7; width: 14px;"></i> Edit
                                        </a>
                                    <?php endif; ?>
                                    <a href="?page=transactions/credit_memo/manage&invoice_id=<?php echo $row['id']; ?>&customer_id=<?php echo $row['customer_id'] ?? ''; ?>"
                                        class="ns-action-item">
                                        <i class="fas fa-undo-alt" style="color: #16a34a; width: 14px;"></i> Credit
                                    </a>
                                    <div style="height: 1px; background: #e2e8f0; margin: 4px 0;"></div>
                                    <a href="javascript:void(0)"
                                        onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')"
                                        class="ns-action-item danger">
                                        <i class="fas fa-ban" style="color: #dc2626; width: 14px;"></i> Void / Delete
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
