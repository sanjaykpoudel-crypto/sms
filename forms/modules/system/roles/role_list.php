<?php
require_once 'database/DBConnection.php';
$db = db();

$list = $db->fetchAll("
    SELECT r.*, COUNT(u.id) as user_count 
    FROM roles r 
    LEFT JOIN users u ON u.role = r.role_code AND u.is_deleted = 0
    GROUP BY r.id
    ORDER BY r.is_system DESC, r.role_name ASC
");
?>
<div class="ns-page-header">
    <h1 class="ns-page-title">
        <i class="fas fa-user-shield" style="margin-right: 10px; color: var(--ns-primary);"></i> Role Permissions & Access Control
        <a href="?page=system/roles/manage" class="ns-btn ns-btn-primary" style="padding: 4px 12px; font-size: 12px; height: 28px; display: inline-flex; align-items: center; gap: 5px;"><i class="fas fa-plus"></i> New Role</a>
    </h1>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th width="40" style="text-align: center;">#</th>
                    <th>Role Name</th>
                    <th>Role Code</th>
                    <th>Description</th>
                    <th>Access Level</th>
                    <th style="text-align: center;">Users</th>
                    <th>Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1; foreach ($list as $row): ?>
                <tr>
                    <td style="text-align: center; color: #888; font-weight: 600;"><?php echo $sn++; ?></td>
                    <td style="font-weight: 700; color: #0055aa;">
                        <a href="?page=system/roles/manage&id=<?php echo $row['id']; ?>" style="color: inherit; text-decoration: none;">
                            <?php echo htmlspecialchars($row['role_name']); ?>
                        </a>
                        <?php if ($row['is_system']): ?>
                            <span style="font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 700;">SYSTEM</span>
                        <?php endif; ?>
                    </td>
                    <td><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #475569; font-weight: 600;"><?php echo htmlspecialchars($row['role_code']); ?></code></td>
                    <td style="color: #64748b; font-size: 12px; max-width: 300px;"><?php echo htmlspecialchars($row['description'] ?: 'No description'); ?></td>
                    <td>
                        <?php 
                        $lvl = strtolower($row['access_level']);
                        if ($lvl === 'full') {
                            echo '<span style="background:#f0fdf4; color:#166534; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:700;">Full Access</span>';
                        } elseif ($lvl === 'readonly') {
                            echo '<span style="background:#fefce8; color:#854d0e; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:700;">Read Only</span>';
                        } else {
                            echo '<span style="background:#eef2ff; color:#3730a3; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:700;">Custom Matrix</span>';
                        }
                        ?>
                    </td>
                    <td style="text-align: center;">
                        <span style="background: #f1f5f9; color: #334155; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;">
                            <?php echo (int)$row['user_count']; ?>
                        </span>
                    </td>
                    <td>
                        <span style="color: <?php echo $row['is_active'] ? '#080' : '#c00'; ?>; font-weight: 600;">
                            <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <div style="display: flex; gap: 5px; justify-content: center;">
                            <a href="?page=system/roles/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit Permissions"><i class="fas fa-user-lock"></i></a>
                            <?php if (!$row['is_system']): ?>
                                <button class="ns-btn" style="color: #c00;" onclick="nsDelete('roles', '<?php echo $row['id']; ?>')"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($list)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 30px; color: #999;">No roles found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
