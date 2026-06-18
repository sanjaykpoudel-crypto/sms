<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
if ($id) {
    $data = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$id]);
}
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Account</div>
    <div class="ns-page-actions">
        <button type="submit" form="account-form" class="ns-btn ns-btn-primary">Save</button>
        <a href="?page=master/account" class="ns-btn">Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="account-form" method="POST" action="api/save_account.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        
        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Account Code *</label>
                    <input type="text" name="account_code" class="ns-input" value="<?php echo $data['account_code'] ?? ''; ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Account Name *</label>
                    <input type="text" name="account_name" class="ns-input" value="<?php echo $data['account_name'] ?? ''; ?>" required>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Account Type *</label>
                    <select name="account_type" class="ns-select" required>
                        <option value="">Select Type</option>
                        <option value="asset" <?php echo ($data['account_type'] ?? '') == 'asset' ? 'selected' : ''; ?>>Asset</option>
                        <option value="liability" <?php echo ($data['account_type'] ?? '') == 'liability' ? 'selected' : ''; ?>>Liability</option>
                        <option value="equity" <?php echo ($data['account_type'] ?? '') == 'equity' ? 'selected' : ''; ?>>Equity</option>
                        <option value="income" <?php echo ($data['account_type'] ?? '') == 'income' ? 'selected' : ''; ?>>Income</option>
                        <option value="expense" <?php echo ($data['account_type'] ?? '') == 'expense' ? 'selected' : ''; ?>>Expense</option>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Normal Balance</label>
                    <select name="normal_balance" class="ns-select">
                        <option value="debit" <?php echo ($data['normal_balance'] ?? '') == 'debit' ? 'selected' : ''; ?>>Debit</option>
                        <option value="credit" <?php echo ($data['normal_balance'] ?? '') == 'credit' ? 'selected' : ''; ?>>Credit</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="ns-section-title">Classification & Status</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Subtype</label>
                    <select name="account_subtype" class="ns-select">
                        <option value="other">Other</option>
                        <option value="cash" <?php echo ($data['account_subtype'] ?? '') == 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="bank" <?php echo ($data['account_subtype'] ?? '') == 'bank' ? 'selected' : ''; ?>>Bank</option>
                        <option value="receivable" <?php echo ($data['account_subtype'] ?? '') == 'receivable' ? 'selected' : ''; ?>>Receivable</option>
                        <option value="payable" <?php echo ($data['account_subtype'] ?? '') == 'payable' ? 'selected' : ''; ?>>Payable</option>
                        <option value="inventory" <?php echo ($data['account_subtype'] ?? '') == 'inventory' ? 'selected' : ''; ?>>Inventory</option>
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

<script>
    document.getElementById('account-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const submitBtn = document.querySelector('button[form="account-form"]');
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
