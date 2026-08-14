<?php
/**
 * forms/modules/sales/promotions/promotions_list.php
 * Promotional Discount Register & List Page
 */

require_once 'database/DBConnection.php';
require_once 'api/PromotionEngine.php';
$db = db();
$engine = PromotionEngine::getInstance();

$current_now = date('Y-m-d H:i:s');

$list = $db->fetchAll("
    SELECT 
        p.*,
        (SELECT COUNT(*) FROM promotion_items pi WHERE pi.promotion_id = p.id) as item_count,
        (SELECT GROUP_CONCAT(l.name SEPARATOR ', ') FROM promotion_locations pl JOIN locations l ON pl.location_id = l.id WHERE pl.promotion_id = p.id) as location_names,
        COALESCE(u.full_name, u.username, 'System') as creator_name
    FROM promotions p
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.is_deleted = 0
    ORDER BY p.created_at DESC
");
?>

<div class="ns-page-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
    <div>
        <h1 class="ns-page-title" style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a;">
            <i class="fas fa-tags" style="color: #0284c7; margin-right: 10px;"></i> Promotional Discounts
        </h1>
        <p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b;">Manage promotional campaigns, MRP/Selling price discounts, item selection, and location scope.</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="?page=sales/promotions/manage" class="ns-btn ns-btn-primary" style="padding: 8px 16px; font-size: 13px; font-weight: 700; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
            <i class="fas fa-plus-circle"></i> Create New Promotion
        </a>
    </div>
</div>

<div class="ns-portlet" style="background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 20px;">
    <div class="table-responsive">
        <table class="ns-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 12px; text-transform: uppercase; color: #475569; letter-spacing: 0.5px;">
                    <th style="padding: 12px 15px;">Code & Name</th>
                    <th style="padding: 12px 15px;">Validity Period</th>
                    <th style="padding: 12px 15px;">Discount Rule</th>
                    <th style="padding: 12px 15px;">Item Scope</th>
                    <th style="padding: 12px 15px;">Location Scope</th>
                    <th style="padding: 12px 15px; text-align: center;">Status</th>
                    <th style="padding: 12px 15px;">Created By</th>
                    <th style="padding: 12px 15px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($list)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="fas fa-tags" style="font-size: 48px; margin-bottom: 12px; opacity: 0.4; display: block;"></i>
                            <div style="font-size: 15px; font-weight: 700; color: #475569;">No Promotions Found</div>
                            <p style="font-size: 13px; margin-top: 4px;">Click "Create New Promotion" to set up your first promotional offer.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list as $row): 
                        $derived_status = $engine->derivePromotionStatus($row, $current_now);
                        
                        $status_badge_bg = '#10b981'; // active
                        $status_badge_txt = '#ffffff';
                        if ($derived_status === 'scheduled') { $status_badge_bg = '#0284c7'; }
                        if ($derived_status === 'expired') { $status_badge_bg = '#94a3b8'; }
                        if ($derived_status === 'inactive') { $status_badge_bg = '#ef4444'; }
                        if ($derived_status === 'draft') { $status_badge_bg = '#f59e0b'; }

                        $disc_badge = strtoupper($row['discount_basis']) . ' - ' . 
                                     ($row['discount_type'] === 'percentage' ? number_format($row['discount_value'], 1) . '%' : 'Rs ' . number_format($row['discount_value'], 2) . ' Off');
                    ?>
                    <tr id="promo-row-<?php echo $row['id']; ?>" style="border-bottom: 1px solid #f1f5f9; font-size: 13px; transition: 0.2s;">
                        <td style="padding: 14px 15px;">
                            <a href="?page=sales/promotions/manage&id=<?php echo $row['id']; ?>" style="font-weight: 800; color: #0284c7; font-size: 14px; text-decoration: none;">
                                <?php echo htmlspecialchars($row['promo_code']); ?>
                            </a>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px; font-weight: 600;">
                                <?php echo htmlspecialchars($row['name']); ?>
                            </div>
                        </td>
                        <td style="padding: 14px 15px; font-size: 12px; color: #334155;">
                            <div><i class="far fa-calendar-alt" style="color: #64748b; margin-right: 4px;"></i> <?php echo date('M d, Y H:i', strtotime($row['start_datetime'])); ?></div>
                            <div style="color: #64748b; margin-top: 2px;"><i class="fas fa-level-down-alt" style="color: #94a3b8; margin-right: 4px;"></i> <?php echo date('M d, Y H:i', strtotime($row['end_datetime'])); ?></div>
                        </td>
                        <td style="padding: 14px 15px;">
                            <span style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 12px; display: inline-block;">
                                <?php echo strtoupper($row['discount_basis']); ?> - <?php echo number_format($row['discount_value'], 1) . ($row['discount_type'] === 'percentage' ? '%' : ' Rs'); ?>
                            </span>
                        </td>
                        <td style="padding: 14px 15px;">
                            <span style="font-weight: 700; color: #475569; font-size: 12px;">
                                <i class="fas fa-boxes" style="color: #8b5cf6; margin-right: 4px;"></i> <?php echo (int)$row['item_count']; ?> Items
                            </span>
                        </td>
                        <td style="padding: 14px 15px;">
                            <?php if ($row['applies_to_locations'] === 'all'): ?>
                                <span style="background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 11px;">
                                    <i class="fas fa-globe"></i> All Locations
                                </span>
                            <?php else: ?>
                                <span style="background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 11px;" title="<?php echo htmlspecialchars($row['location_names'] ?: 'Selected Locations'); ?>">
                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['location_names'] ? (strlen($row['location_names']) > 25 ? substr($row['location_names'], 0, 25) . '...' : $row['location_names']) : 'Selected Locations'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 14px 15px; text-align: center;">
                            <span class="badge" style="background: <?php echo $status_badge_bg; ?>; color: #ffffff; padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">
                                <?php echo ucfirst($derived_status); ?>
                            </span>
                        </td>
                        <td style="padding: 14px 15px; color: #64748b; font-size: 12px;">
                            <?php echo htmlspecialchars($row['creator_name']); ?>
                        </td>
                        <td style="padding: 14px 15px; text-align: center;">
                            <div style="position: relative; display: inline-block;">
                                <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                    Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                                </button>
                                <div class="ns-action-dropdown-menu">
                                    <a href="?page=sales/promotions/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                        <i class="fas fa-edit" style="color: #0284c7; width: 14px;"></i> Edit
                                    </a>
                                    <a href="javascript:void(0)" onclick="togglePromoStatus(<?php echo $row['id']; ?>, '<?php echo $row['status'] === 'active' ? 'inactive' : 'active'; ?>')" class="ns-action-item">
                                        <i class="fas fa-power-off" style="color: #d97706; width: 14px;"></i> <?php echo $row['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                    </a>
                                    <a href="javascript:void(0)" onclick="duplicatePromo(<?php echo $row['id']; ?>)" class="ns-action-item">
                                        <i class="fas fa-copy" style="color: #8b5cf6; width: 14px;"></i> Duplicate
                                    </a>
                                    <div style="height: 1px; background: #e2e8f0; margin: 4px 0;"></div>
                                    <a href="javascript:void(0)" onclick="deletePromo(<?php echo $row['id']; ?>)" class="ns-action-item danger">
                                        <i class="fas fa-trash-alt" style="color: #dc2626; width: 14px;"></i> Delete
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function togglePromoStatus(id, newStatus) {
    if (!confirm(`Are you sure you want to set this promotion status to ${newStatus}?`)) return;
    const fd = new FormData();
    fd.append('action', 'toggle_status');
    fd.append('id', id);
    fd.append('status', newStatus);

    fetch('api/save_promotion.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                if (typeof nsNotify === 'function') nsNotify(res.message);
                setTimeout(() => location.reload(), 300);
            } else {
                alert(res.message || 'Error updating status');
            }
        })
        .catch(err => alert("Error: " + err.message));
}

function duplicatePromo(id) {
    if (!confirm("Duplicate this promotion as a new draft promotion?")) return;
    const fd = new FormData();
    fd.append('action', 'duplicate');
    fd.append('id', id);

    fetch('api/save_promotion.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                if (typeof nsNotify === 'function') nsNotify(res.message);
                setTimeout(() => location.href = '?page=sales/promotions/manage&id=' + res.id, 300);
            } else {
                alert(res.message || 'Error duplicating promotion');
            }
        })
        .catch(err => alert("Error: " + err.message));
}

function deletePromo(id) {
    if (!confirm("Are you sure you want to delete this promotion?")) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);

    fetch('api/save_promotion.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                if (typeof nsNotify === 'function') nsNotify(res.message);
                
                const row = document.getElementById('promo-row-' + id);
                if (row) row.remove();
                
                setTimeout(() => location.reload(), 300);
            } else {
                alert(res.message || 'Error deleting promotion');
            }
        })
        .catch(err => alert("Error deleting promotion: " + err.message));
}
</script>
