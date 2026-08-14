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

<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-boxes" style="color: #0284c7; margin-right: 8px;"></i> Inventory Adjustments
    </h1>
    <a href="?page=transactions/adjustment/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New</a>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Adjustment #</th>
                    <th>Memo</th>
                    <th width="120" style="text-align: center;">Items Adjusted</th>
                    <th width="150" style="text-align: right;">Total Adjusted Value</th>
                    <th width="150">Adjusted By</th>
                    <th width="150">Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
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
                    <td style="text-align: center;">
                        <div style="position: relative; display: inline-block;">
                            <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                            </button>
                            <div class="ns-action-dropdown-menu">
                                <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-eye" style="color: #64748b; width: 14px;"></i> View
                                </a>
                                <a href="?page=transactions/adjustment/manage&id=<?php echo urlencode($row['id']); ?>" class="ns-action-item">
                                    <i class="fas fa-edit" style="color: #0284c7; width: 14px;"></i> Edit
                                </a>
                                <div style="height: 1px; background: #e2e8f0; margin: 4px 0;"></div>
                                <a href="javascript:void(0)" onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')" class="ns-action-item danger">
                                    <i class="fas fa-ban" style="color: #dc2626; width: 14px;"></i> Void / Delete
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>


