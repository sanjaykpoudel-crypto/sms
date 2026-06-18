<?php
require_once 'database/DBConnection.php';
$db = db();
$list = $db->fetchAll("SELECT t.*, u.full_name as creator_name,
                       (SELECT COUNT(id) FROM transaction_lines WHERE header_id = t.id) as items_count
                      FROM transaction_headers t 
                      LEFT JOIN users u ON t.created_by = u.id
                      WHERE t.txn_type = 'inventory_adjustment' AND t.is_deleted = 0
                      ORDER BY t.created_at DESC");
?>
<div class="ns-page-header">
    <h1 class="ns-page-title">
        <i class="fas fa-warehouse" style="margin-right: 10px; color: var(--ns-accent);"></i>
        Inventory Adjustments
        <a href="?page=transactions/adjustment/manage" class="ns-btn ns-btn-primary"><i class="fas fa-plus"></i> New Adjustment</a>
    </h1>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th width="100">Date</th>
                    <th width="150">Adjustment #</th>
                    <th>Memo</th>
                    <th width="120" style="text-align: center;">Items Adjusted</th>
                    <th width="150" style="text-align: right;">Total Adjusted Value</th>
                    <th width="150">Adjusted By</th>
                    <th width="150">Status</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($list)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: #888; padding: 30px;">
                        <i class="fas fa-box-open" style="font-size: 24px; opacity: 0.3; margin-bottom: 10px; display: block;"></i>
                        No inventory adjustments found.
                    </td>
                </tr>
                <?php else: foreach ($list as $row): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['memo'] ?: '-'); ?></td>
                    <td style="text-align: center; font-weight: 600;"><?php echo (int)$row['items_count']; ?></td>
                    <td style="text-align: right; font-weight: 700; color: #2c3e50;">Rs. <?php echo number_format(abs($row['net_amount']), 2); ?></td>
                    <td><?php echo htmlspecialchars($row['creator_name'] ?? 'System'); ?></td>
                    <td>
                        <span style="color: #28a745; font-weight: 700; text-transform: uppercase;">
                            <?php echo htmlspecialchars(ucfirst($row['status'])); ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=transactions/adjustment/manage&id=<?php echo urlencode($row['id']); ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn ns-remove-line" title="Delete" onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Date</th>
                    <th>Adjustment #</th>
                    <th>Memo</th>
                    <th style="text-align: center;">Items</th>
                    <th style="text-align: right;">Total Value</th>
                    <th>Adjusted By</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
