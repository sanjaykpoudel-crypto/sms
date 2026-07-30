<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
if ($id) {
    $data = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
}
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Employee / User</div>
    <div class="ns-page-actions">
        <button type="submit" form="user-form" class="ns-btn ns-btn-primary"><?php echo $id ? 'Edit' : 'Save'; ?></button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDelete('users', '<?php echo $id; ?>', function() { window.location.href = '?page=system/users/user_list'; })"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <button type="button" onclick="history.back()" class="ns-btn">Cancel</button>
    </div>
</div>

<?php if (!empty($_SESSION['error'])): ?>
    <div style="background:#fff5f5; border-left:4px solid #e74c3c; padding:12px 16px; border-radius:6px; margin-bottom:16px; color:#c0392b; font-size:13px;">
        <i class="fas fa-exclamation-circle" style="margin-right:8px;"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<div class="ns-form-container">
    <form id="user-form" method="POST" action="api/save_user.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        
        <div class="ns-section-title">User Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Full Name *</label>
                    <input type="text" name="full_name" class="ns-input" value="<?php echo htmlspecialchars($data['full_name'] ?? ''); ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Username *</label>
                    <input type="text" name="username" class="ns-input" value="<?php echo htmlspecialchars($data['username'] ?? ''); ?>" required autocomplete="off">
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Email *</label>
                    <input type="email" name="email" class="ns-input" value="<?php echo htmlspecialchars($data['email'] ?? ''); ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Password <?php echo $id ? '' : '*'; ?></label>
                    <input type="password" name="password" class="ns-input" autocomplete="new-password" <?php echo $id ? '' : 'required'; ?>>
                    <?php if($id): ?>
                        <small style="color: #666; font-size: 11px; display: block; margin-top: 5px;">Leave blank to keep current password</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Role, Location & Status</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">User Role *</label>
                    <select name="role" class="ns-select" required>
                        <option value="">-- Select Role --</option>
                        <?php 
                        $roles_list = $db->fetchAll("SELECT role_code, role_name FROM roles WHERE is_active = 1 ORDER BY role_name ASC");
                        if (empty($roles_list)) {
                            $roles_list = [
                                ['role_code' => 'admin', 'role_name' => 'Administrator'],
                                ['role_code' => 'manager', 'role_name' => 'Manager'],
                                ['role_code' => 'accountant', 'role_name' => 'Accountant'],
                                ['role_code' => 'cashier', 'role_name' => 'Cashier']
                            ];
                        }
                        foreach ($roles_list as $r): 
                        ?>
                            <option value="<?php echo htmlspecialchars($r['role_code']); ?>" <?php echo (($data['role'] ?? '') == $r['role_code']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Assigned Location</label>
                    <select name="location_id" class="ns-select">
                        <option value="">-- All / Default --</option>
                        <?php foreach (get_active_locations() as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['id']); ?>" <?php echo (($data['location_id'] ?? '') == $loc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['name']); ?><?php echo !empty($loc['is_default']) ? ' (Default)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex: 0.5;">
                <div class="ns-form-group">
                    <label class="ns-label" style="display: block; width: 100px; text-align: left;">Inactive</label>
                    <input type="checkbox" name="is_inactive" <?php echo (isset($data['is_active']) && $data['is_active'] == 0) ? 'checked' : ''; ?> style="width: 18px; height: 18px; cursor: pointer;">
                </div>
            </div>
        </div>
    </form>
</div>
