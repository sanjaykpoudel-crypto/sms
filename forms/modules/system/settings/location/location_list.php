<?php
require_once 'database/DBConnection.php';
$db = db();

$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$status_filter = $show_all ? "" : " AND is_active = 1 ";

$locations = $db->fetchAll("
    SELECT * 
    FROM locations 
    WHERE is_deleted = 0 $status_filter 
    ORDER BY name ASC
");
?>
<style>
    .ns-badge-type {
        background: #e2e8f0;
        color: #1e293b;
        font-weight: 600;
        border-radius: 4px;
        padding: 2px 8px;
        font-size: 11px;
    }
</style>

<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        Locations Master
    </h1>
    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="?page=system/settings/location/manage" class="ns-btn ns-btn-primary"
            style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;">
            <i class="fas fa-plus" style="margin-right: 4px;"></i> New Location
        </a>
    </div>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th width="40" style="text-align: center;">#</th>
                    <th>Location Name</th>
                    <th>Short Code</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($locations)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #888; padding: 20px;">No location records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $sn = 1;
                    foreach ($locations as $row): ?>
                        <tr>
                            <td style="text-align: center; color: #888; font-weight: 600;"><?php echo $sn++; ?></td>
                            <td style="font-weight: 600; color: #003087;"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><span style="font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 2px 8px; border-radius: 4px; font-size: 11px;"><?php echo htmlspecialchars($row['code'] ?? '-'); ?></span></td>
                            <td><span class="ns-badge-type"><?php echo htmlspecialchars($row['type']); ?></span></td>
                            <td style="color: #64748b; font-size: 12px;">
                                <?php echo htmlspecialchars($row['description'] ?? '-'); ?></td>
                            <td>
                                <span
                                    style="color: <?php echo $row['is_active'] ? '#080' : '#c00'; ?>; font-weight: 600; font-size: 11px;">
                                    <i class="fas <?php echo $row['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                    <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px;">
                                    <a href="?page=system/settings/location/manage&id=<?php echo urlencode($row['id']); ?>"
                                        class="ns-btn" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button class="ns-btn" style="color: #c00;" title="Delete"
                                        onclick="nsDelete('locations', '<?php echo $row['id']; ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>