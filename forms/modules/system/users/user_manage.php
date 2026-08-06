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
    <form id="user-form" method="POST" action="api/save_user.php" enctype="multipart/form-data">
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
                    <input type="email" name="email" class="ns-input" value="<?php echo htmlspecialchars($data['email'] ?? ''); ?>" required placeholder="e.g. user@domain.com" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" title="Please enter a valid email address (e.g. name@domain.com)">
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

        <div class="ns-section-title">Employee Details</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Phone Number</label>
                    <input type="tel" name="phone" class="ns-input" value="<?php echo htmlspecialchars($data['phone'] ?? ''); ?>" placeholder="e.g. +977 9801234567" pattern="^[0-9+\-\s()]{7,20}$" title="Please enter a valid phone number (e.g. +977 9801234567 or 9801234567)">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Designation / Title</label>
                    <input type="text" name="designation" class="ns-input" value="<?php echo htmlspecialchars($data['designation'] ?? ''); ?>" placeholder="e.g. Senior Store Manager">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Department</label>
                    <input type="text" name="department" class="ns-input" value="<?php echo htmlspecialchars($data['department'] ?? ''); ?>" placeholder="e.g. Inventory & Sales">
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Date of Joining</label>
                    <input type="date" name="joining_date" class="ns-input" value="<?php echo htmlspecialchars($data['joining_date'] ?? ''); ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Emergency Contact</label>
                    <input type="text" name="emergency_contact" class="ns-input" value="<?php echo htmlspecialchars($data['emergency_contact'] ?? ''); ?>" placeholder="e.g. Relative / Phone (+977 98...)">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Address</label>
                    <input type="text" name="address" class="ns-input" value="<?php echo htmlspecialchars($data['address'] ?? ''); ?>" placeholder="e.g. Kathmandu, Nepal">
                </div>
            </div>
        </div>

        <div class="ns-section-title">Photograph & Citizenship Documents</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Employee Photograph (Avatar)</label>
                    <input type="file" name="avatar" class="ns-input" accept="image/*">
                    <?php if (!empty($data['avatar']) && file_exists(__DIR__ . '/../../../../' . $data['avatar'])): ?>
                        <div style="margin-top: 8px; display: flex; align-items: center; gap: 10px;">
                            <img src="<?php echo htmlspecialchars($data['avatar']); ?>" alt="Photo" style="width: 50px; height: 50px; object-fit: cover; border-radius: 50%; border: 2px solid #cbd5e1;">
                            <span style="font-size: 12px; color: #16a34a;"><i class="fas fa-check-circle"></i> Photo Uploaded</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Citizenship Card (Front)</label>
                    <input type="file" name="citizenship_front" class="ns-input" accept="image/*,.pdf">
                    <?php if (!empty($data['citizenship_front']) && file_exists(__DIR__ . '/../../../../' . $data['citizenship_front'])): ?>
                        <div style="margin-top: 8px;">
                            <a href="<?php echo htmlspecialchars($data['citizenship_front']); ?>" target="_blank" style="font-size: 12px; color: var(--ns-primary); text-decoration: none; font-weight: 600;">
                                <i class="fas fa-file-image"></i> View Front Document
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Citizenship Card (Back)</label>
                    <input type="file" name="citizenship_back" class="ns-input" accept="image/*,.pdf">
                    <?php if (!empty($data['citizenship_back']) && file_exists(__DIR__ . '/../../../../' . $data['citizenship_back'])): ?>
                        <div style="margin-top: 8px;">
                            <a href="<?php echo htmlspecialchars($data['citizenship_back']); ?>" target="_blank" style="font-size: 12px; color: var(--ns-primary); text-decoration: none; font-weight: 600;">
                                <i class="fas fa-file-image"></i> View Back Document
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Role, Location & Status</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">User Role *</label>
                    <select name="role" id="user_role_select" class="ns-select" required>
                        <option value="" disabled <?php echo empty($data['role']) ? 'selected' : 'hidden'; ?>>-- Select Role --</option>
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
                    <script>
                        document.getElementById('user_role_select')?.addEventListener('focus', function() {
                            const opt = this.querySelector('option[value=""]');
                            if (opt) opt.remove();
                        });
                        document.getElementById('user_role_select')?.addEventListener('click', function() {
                            const opt = this.querySelector('option[value=""]');
                            if (opt && this.value !== "") opt.remove();
                        });
                    </script>
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
