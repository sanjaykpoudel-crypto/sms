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

        <div class="ns-section-title">Role & Status</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">User Role *</label>
                    <select name="role" class="ns-select" required>
                        <option value="cashier" <?php echo (($data['role'] ?? '') == 'cashier') ? 'selected' : ''; ?>>Cashier</option>
                        <option value="accountant" <?php echo (($data['role'] ?? '') == 'accountant') ? 'selected' : ''; ?>>Accountant</option>
                        <option value="manager" <?php echo (($data['role'] ?? '') == 'manager') ? 'selected' : ''; ?>>Manager</option>
                        <option value="admin" <?php echo (($data['role'] ?? '') == 'admin') ? 'selected' : ''; ?>>Administrator</option>
                    </select>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label" style="display: block; width: 150px; text-align: right; padding-right: 15px;">Active</label>
                    <input type="checkbox" name="is_active" <?php echo ($data['is_active'] ?? 1) ? 'checked' : ''; ?>>
                </div>
            </div>
        </div>
    </form>
</div>
