<?php
require_once 'database/DBConnection.php';
$db = db();
$list = $db->fetchAll("
    SELECT th.*, l.name as location_name, COALESCE(u.full_name, u.username) as creator_name 
    FROM transaction_headers th 
    LEFT JOIN locations l ON th.location_id = l.id 
    LEFT JOIN users u ON th.created_by = u.id
    WHERE th.txn_type = 'cash_denomination' AND th.is_deleted = 0 
    ORDER BY th.created_at DESC
");
?>

<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-coins" style="color: #0284c7; margin-right: 8px;"></i> Cash Denomination Entries
    </h1>
    <a href="?page=transactions/cash_denom/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New</a>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Entry #</th>
                    <th>Location</th>
                    <th>Shift/Counter</th>
                    <th style="text-align: right;">Total Amount</th>
                    <th>Created By</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): 
                    $shift_val = $row['reference_number'] ?: $row['party_id'];
                    $shift_name = 'Main Counter';
                    if ($shift_val === 'Shift_A') {
                        $shift_name = 'Shift_A';
                    } elseif ($shift_val === 'Shift_B') {
                        $shift_name = 'Shift_B';
                    } elseif (!empty($shift_val) && $shift_val !== '1' && $shift_val !== 1 && $shift_val !== 'Main') {
                        $shift_name = $shift_val;
                    }
                ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?></td>
                    <td>
                        <span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;">
                            <?php echo htmlspecialchars($row['location_name'] ?? 'Main Store'); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($shift_name); ?></td>
                    <td style="text-align: right; font-weight: bold;">Rs. <?php echo number_format($row['net_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['creator_name'] ?? ('User #' . $row['created_by'])); ?></td>
                    <td style="text-align: center;">
                        <div style="position: relative; display: inline-block;">
                            <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                            </button>
                            <div class="ns-action-dropdown-menu">
                                <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-eye" style="color: #64748b; width: 14px;"></i> View
                                </a>
                                <a href="?page=transactions/cash_denom/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
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
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


