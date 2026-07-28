<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$current_balance = 0.00;

if ($id) {
    $data = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$id]);
    if ($data) {
        $bal_row = $db->fetchOne("
            SELECT COALESCE(SUM(CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE -je.amount END), 0) as bal
            FROM journal_entries je
            JOIN transaction_headers h ON je.header_id = h.id
            WHERE je.account_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ", [$id]);
        $net_debit = (float) ($bal_row['bal'] ?? 0);
        $type = strtolower($data['account_type'] ?? '');
        $normal = strtolower($data['normal_balance'] ?? '');

        if ($normal === 'credit' || in_array($type, ['liability', 'equity', 'income'])) {
            $current_balance = -$net_debit;
        } else {
            $current_balance = $net_debit;
        }
    }
}
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Account</div>
    <div class="ns-page-actions">
        <button type="submit" form="account-form" class="ns-btn ns-btn-primary">Save</button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;"
                onclick="nsDelete('accounts', '<?php echo $id; ?>', function() { window.location.href = '?page=master/account'; })"><i
                    class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <a href="?page=master/account" class="ns-btn">Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <?php if ($id): ?>
        <div
            style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
            <span style="font-weight: 600; color: #475569; font-size: 14px;">
                <i class="fas fa-wallet" style="margin-right: 8px; color: #003087;"></i> Current Account Balance
            </span>
            <span
                style="font-weight: 800; font-size: 17px; color: <?php echo $current_balance >= 0 ? '#16a085' : '#dc2626'; ?>;">
                Rs <?php echo number_format(abs($current_balance), 2); ?>
                <?php if ($current_balance < 0): ?>
                    <span style="font-size:11px; font-weight:600; color:#dc2626; margin-left:4px;">(Credit/Overdrawn)</span>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
    <form id="account-form" method="POST" action="api/save_account.php">
        <?php
        $account_type_masters = $db->fetchAll("SELECT * FROM AccountTypeMaster WHERE IsActive = 1 ORDER BY SortOrder ASC, AccountTypeName ASC");
        ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="account_type" id="account_type_hidden"
            value="<?php echo htmlspecialchars($data['account_type'] ?? 'asset'); ?>">
        <input type="hidden" name="account_subtype" id="account_subtype_hidden"
            value="<?php echo htmlspecialchars($data['account_subtype'] ?? 'other'); ?>">
        <input type="hidden" name="normal_balance" id="normal_balance_hidden"
            value="<?php echo htmlspecialchars($data['normal_balance'] ?? 'debit'); ?>">

        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Account Name *</label>
                    <input type="text" name="account_name" class="ns-input"
                        value="<?php echo htmlspecialchars($data['account_name'] ?? ''); ?>" required>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Account Type *</label>
                    <select name="account_type_id" id="account_type_id_select" class="ns-select" required
                        onchange="onAccountTypeMasterChange(this)">
                        <option value="">Select Account Type</option>
                        <?php foreach ($account_type_masters as $atm):
                            $sel = ($data['account_type_id'] ?? '') == $atm['AccountTypeId'] ? 'selected' : '';
                            ?>
                            <option value="<?php echo $atm['AccountTypeId']; ?>"
                                data-typename="<?php echo htmlspecialchars($atm['AccountTypeName']); ?>"
                                data-category="<?php echo strtolower($atm['Category']); ?>"
                                data-normal="<?php echo strtolower($atm['NormalBalance']); ?>" <?php echo $sel; ?>>
                                <?php echo htmlspecialchars($atm['AccountTypeName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Description & Details</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Description</label>
                    <textarea name="description" class="ns-input" rows="3"
                        placeholder="Enter account description or purpose..."><?php echo htmlspecialchars($data['description'] ?? ''); ?></textarea>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group" style="margin-top: 24px;">
                    <label
                        style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 13px;">
                        <input type="checkbox" name="is_inactive" <?php echo (isset($data['is_active']) && $data['is_active'] == 0) ? 'checked' : ''; ?> style="width: 16px; height: 16px;">
                        Inactive
                    </label>
                </div>
            </div>
        </div>

        <script>
            function onAccountTypeMasterChange(selectEl) {
                const selected = selectEl.options[selectEl.selectedIndex];
                if (selected && selected.value) {
                    const typename = selected.getAttribute('data-typename');
                    const cat = selected.getAttribute('data-category');
                    const normal = selected.getAttribute('data-normal');
                    document.getElementById('account_subtype_hidden').value = typename;
                    document.getElementById('account_type_hidden').value = cat;
                    document.getElementById('normal_balance_hidden').value = normal;
                    const catDisplayEl = document.getElementById('cat_normal_display');
                    if (catDisplayEl) {
                        catDisplayEl.value = cat.charAt(0).toUpperCase() + cat.slice(1) + ' / ' + normal.charAt(0).toUpperCase() + normal.slice(1);
                    }
                } else {
                    document.getElementById('account_subtype_hidden').value = 'other';
                    document.getElementById('account_type_hidden').value = '';
                    document.getElementById('normal_balance_hidden').value = '';
                    const catDisplayEl = document.getElementById('cat_normal_display');
                    if (catDisplayEl) {
                        catDisplayEl.value = '';
                    }
                }
            }
        </script>

        <div class="ns-section-title">System Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Category & Normal Balance (System Derived)</label>
                    <input type="text" id="cat_normal_display" class="ns-input" readonly
                        style="background: #f8fafc; font-weight: 600; color: #64748b;"
                        value="<?php echo ucfirst($data['account_type'] ?? '') . ' / ' . ucfirst($data['normal_balance'] ?? ''); ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">System Record ID</label>
                    <input type="text" class="ns-input"
                        value="<?php echo htmlspecialchars($data['id'] ?? 'New Record'); ?>" readonly
                        style="background: #f8fafc; color: #64748b;">
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Created Date / Time</label>
                    <input type="text" class="ns-input"
                        value="<?php echo !empty($data['created_at']) ? date('Y-m-d H:i:s', strtotime($data['created_at'])) : 'N/A'; ?>"
                        readonly style="background: #f8fafc; color: #64748b;">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Last Updated Date / Time</label>
                    <input type="text" class="ns-input"
                        value="<?php echo !empty($data['updated_at']) ? date('Y-m-d H:i:s', strtotime($data['updated_at'])) : 'N/A'; ?>"
                        readonly style="background: #f8fafc; color: #64748b;">
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    document.getElementById('account-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = this;
        const submitBtn = document.querySelector('button[form="account-form"]');
        const originalText = submitBtn.innerHTML;

        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;

        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            if (key !== 'is_inactive') {
                data[key] = value;
            }
        });
        data['is_active'] = form.querySelector('[name="is_inactive"]').checked ? 0 : 1;

        const payload = {
            action: data.id ? 'update' : 'save',
            table: 'accounts',
            primary_key: 'id',
            primary_value: data.id || null,
            data: data
        };

        fetch('api/transaction_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    nsNotify(data.message);
                    setTimeout(() => {
                        window.location.href = '?page=master/account';
                    }, 1500);
                } else {
                    nsNotify(data.message || 'Error occurred while saving.', 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(err => {
                nsNotify('Network error or server failed.', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
    });
</script>