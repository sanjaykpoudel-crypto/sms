<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
if ($id) {
    $data = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [$id]);
}
$accounts = $db->fetchAll("SELECT id, account_name, account_code, account_subtype FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Customer</div>
    <div class="ns-page-actions">
        <button type="submit" form="customer-form" class="ns-btn ns-btn-primary"><?php echo $id ? 'Edit' : 'Save'; ?></button>
        <button type="button" onclick="history.back()" class="ns-btn">Cancel</button>
    </div>
</div>

<div class="ns-form-container">
    <form id="customer-form" method="POST" action="api/save_customer.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        
        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Customer Code</label>
                    <input type="text" name="customer_code" class="ns-input" value="<?php echo $id ? ($data['customer_code'] ?? '') : getNextTransactionNumber('customer'); ?>" readonly style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Full Name *</label>
                    <input type="text" name="full_name" class="ns-input" value="<?php echo $data['full_name'] ?? ''; ?>" required>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Customer Type *</label>
                    <select name="customer_type" class="ns-select" required>
                        <option value="retail" <?php echo ($data['customer_type'] ?? '') == 'retail' ? 'selected' : ''; ?>>Retail</option>
                        <option value="wholesale" <?php echo ($data['customer_type'] ?? '') == 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                        <option value="bar" <?php echo ($data['customer_type'] ?? '') == 'bar' ? 'selected' : ''; ?>>Bar / Restaurant</option>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">PAN Number</label>
                    <input type="text" name="pan_number" class="ns-input" value="<?php echo $data['pan_number'] ?? ''; ?>">
                </div>
            </div>
        </div>

        <div class="ns-section-title">Contact & Financials</div>
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
                    <label class="ns-label">Credit Limit</label>
                    <input type="number" step="0.01" name="credit_limit" class="ns-input" value="<?php echo $data['credit_limit'] ?? '0.00'; ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label" style="display: block; width: 150px; text-align: right; padding-right: 15px;">Active</label>
                    <input type="checkbox" name="is_active" <?php echo ($data['is_active'] ?? 1) ? 'checked' : ''; ?>>
                </div>
            </div>
        </div>
        
        <div class="ns-section-title">Accounting & Terms</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Receivable Account *</label>
                    <select name="receivable_account_id" class="ns-select" required>
                        <option value="">Select Account</option>
                        <?php foreach($accounts as $acc): if(in_array($acc['account_subtype'], ['receivable', 'other'])): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($data['receivable_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['account_name'] . ' (' . $acc['account_code'] . ')'); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Payment Terms (Days)</label>
                    <input type="number" name="payment_terms_days" class="ns-input" value="<?php echo $data['payment_terms_days'] ?? ''; ?>">
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    document.getElementById('customer-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const submitBtn = document.querySelector('button[form="customer-form"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;

        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            if (key === 'is_active') {
                data[key] = 1;
            } else {
                data[key] = value;
            }
        });
        if (!formData.has('is_active')) data['is_active'] = 0;

        const payload = {
            action: data.id ? 'update' : 'save',
            table: 'customers',
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
                    window.location.href = '?page=master/customer/view&id=' + data.id;
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
