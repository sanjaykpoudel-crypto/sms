<?php
require_once 'database/DBConnection.php';
$db = db();
$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$status_filter = $show_all ? "" : " AND is_active = 1 ";

$list = $db->fetchAll("SELECT * FROM users WHERE is_deleted = 0 $status_filter ORDER BY full_name ASC");
?>
<div class="ns-page-header">
    <h1 class="ns-page-title">
        Employees & Users
        <a href="?page=system/users/manage" class="ns-btn ns-btn-primary">New Employee</a>
    </h1>
</div>

<div style="display: none;">
    <label id="inactive-filter-container" style="margin-left: 15px; font-size: 12px; font-weight: normal; color: #333; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; vertical-align: middle;">
        <input type="checkbox" id="show-inactive-checkbox" <?php echo $show_all ? 'checked' : ''; ?> onchange="toggleStatusFilter(this.checked)" style="cursor: pointer; margin: 0; width: 13px; height: 13px; vertical-align: middle;">
        Inactive
    </label>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th width="80" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): ?>
                <tr>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td><?php echo ucfirst(htmlspecialchars($row['role'])); ?></td>
                    <td>
                        <span style="color: <?php echo $row['is_active'] ? '#080' : '#c00'; ?>">
                            <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <div style="display: flex; gap: 5px; justify-content: center;">
                            <a href="?page=system/users/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=system/users/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: #c00;" onclick="nsDelete('users', '<?php echo $row['id']; ?>')"><i class="fas fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($list)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 20px; color: #999;">No users found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleStatusFilter(checked) {
    const url = new URL(window.location.href);
    if (checked) {
        url.searchParams.set('show_all', '1');
    } else {
        url.searchParams.delete('show_all');
    }
    window.location.href = url.toString();
}
</script>