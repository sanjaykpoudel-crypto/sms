<?php
require_once 'database/DBConnection.php';
$db = db();
$list = $db->fetchAll("SELECT t.*, v.company_name as vendor_name, vb.total_amount as net_amount, vb.payment_status, vb.due_date
                      FROM transaction_headers t 
                      INNER JOIN vendor_bills vb ON t.id = vb.header_id
                      LEFT JOIN vendors v ON vb.vendor_id = v.id
                      WHERE t.txn_type = 'vendor_bill' AND t.is_deleted = 0
                      ORDER BY t.created_at DESC");
?>
<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-file-invoice" style="color: #8b5cf6; margin-right: 8px;"></i> Vendor Bills
    </h1>
    <a href="?page=transactions/bill/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New Transaction</a>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Bill #</th>
                    <th>Vendor</th>
                    <th>Due Date</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Status</th>
                    <th width="80">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['vendor_name'] ?? 'Unspecified Vendor'); ?></td>
                    <td><?php echo $row['due_date'] ?? '-'; ?></td>
                    <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['net_amount'], 2); ?></td>
                    <td>
                        <span style="color: <?php echo strtolower($row['payment_status']) == 'paid' ? '#080' : '#c00'; ?>; font-weight: 600;">
                            <?php echo strtolower($row['payment_status']) == 'paid' ? 'Paid in Full' : (ucwords($row['payment_status']) ?: 'Pending'); ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=transactions/bill/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: #c00;" title="Void" onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')"><i class="fas fa-ban"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
