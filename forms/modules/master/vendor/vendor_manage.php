<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
if ($id) {
    $data = $db->fetchOne("SELECT * FROM vendors WHERE id = ?", [$id]);
}
$accounts = $db->fetchAll("SELECT id, account_name, account_subtype FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
$locations = get_active_locations();
$curr_loc_id = $data['location_id'] ?? get_user_default_location_id();
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Vendor</div>
    <div class="ns-page-actions">
        <button type="submit" form="vendor-form"
            class="ns-btn ns-btn-primary"><?php echo $id ? 'Edit' : 'Save'; ?></button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;"
                onclick="nsDelete('vendors', '<?php echo $id; ?>', function() { window.location.href = '?page=master/vendor'; })"><i
                    class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <button type="button" onclick="history.back()" class="ns-btn">Cancel</button>
    </div>
</div>

<div class="ns-form-container">
    <form id="vendor-form" method="POST" action="api/save_vendor.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Company Name *</label>
                    <input type="text" name="company_name" class="ns-input"
                        value="<?php echo $data['company_name'] ?? ''; ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Location *</label>
                    <select name="location_id" class="ns-select" required>
                        <option value="">Select Location</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['id']); ?>" <?php echo ($curr_loc_id == $loc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Contact Person</label>
                    <input type="text" name="contact_name" class="ns-input"
                        value="<?php echo $data['contact_name'] ?? ''; ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">PAN / VAT</label>
                    <input type="text" name="pan_number" class="ns-input"
                        value="<?php echo $data['pan_number'] ?? ''; ?>">
                </div>
            </div>
        </div>

        <div class="ns-section-title">Contact & Address</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Phone</label>
                    <input type="text" name="phone" class="ns-input" value="<?php echo $data['phone'] ?? ''; ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Email</label>
                    <input type="email" name="email" class="ns-input" value="<?php echo $data['email'] ?? ''; ?>">
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Address</label>
                    <textarea name="address" class="ns-input"
                        style="height: 50px;"><?php echo $data['address'] ?? ''; ?></textarea>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label"
                        style="display: block; width: 150px; text-align: right; padding-right: 15px;">Inactive</label>
                    <input type="checkbox" name="is_inactive" <?php echo (isset($data['is_active']) && $data['is_active'] == 0) ? 'checked' : ''; ?> style="width: 18px; height: 18px; cursor: pointer;">
                </div>
            </div>
        </div>

        <div class="ns-section-title">Accounting & Terms</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Payable Account *</label>
                    <select name="payable_account_id" class="ns-select" required>
                        <option value="">Select Account</option>
                        <?php foreach ($accounts as $acc):
                            if (in_array($acc['account_subtype'], ['Accounts Payable', 'Other Current Liability'])): ?>
                                <option value="<?php echo $acc['id']; ?>" <?php echo ($data['payable_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['account_name']); ?>
                                </option>
                            <?php endif; endforeach; ?>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Credit Limit</label>
                    <input type="number" step="0.01" name="credit_limit" class="ns-input"
                        value="<?php echo $data['credit_limit'] ?? '0.00'; ?>">
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Payment Terms (Days)</label>
                    <input type="number" name="payment_terms_days" class="ns-input"
                        value="<?php echo $data['payment_terms_days'] ?? ''; ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Description</label>
                    <textarea name="description" class="ns-input"
                        style="height: 50px;" placeholder="Add description or notes..."><?php echo htmlspecialchars($data['description'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    document.getElementById('vendor-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = this;
        const submitBtn = document.querySelector('button[form="vendor-form"]');
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
        if (!data['payment_terms_days'] || String(data['payment_terms_days']).trim() === '') {
            data['payment_terms_days'] = null;
        } else {
            data['payment_terms_days'] = parseInt(data['payment_terms_days'], 10);
        }
        if (!data['credit_limit'] || String(data['credit_limit']).trim() === '') {
            data['credit_limit'] = 0.00;
        } else {
            data['credit_limit'] = parseFloat(data['credit_limit']);
        }

        const payload = {
            action: data.id ? 'update' : 'save',
            table: 'vendors',
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
                        window.location.href = '?page=master/vendor/view&id=' + data.id;
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