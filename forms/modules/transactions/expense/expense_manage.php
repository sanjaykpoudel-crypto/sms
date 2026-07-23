<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
if ($id) {
    $data = $db->fetchOne("
        SELECT h.*, e.expense_account_id, e.paid_from_account_id, e.expense_category, e.description as expense_description
        FROM transaction_headers h
        LEFT JOIN expenses e ON h.id = e.header_id
        WHERE h.id = ?", [$id]);
} else {
    $data = [
        'txn_number' => 'EXP-' . date('Ymd') . '-' . rand(1000, 9999),
        'txn_date' => date('Y-m-d'),
        'net_amount' => 0,
        'expense_account_id' => get_accounting_preference('default_expense_account')
    ];
}

// Fetch Accounts for Paid From
$paid_from_accounts = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE is_active = 1 AND is_deleted = 0 AND account_type IN ('asset') ORDER BY account_name ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'Enter'; ?> Expense</div>
    <div class="ns-page-actions">
        <button type="button" onclick="saveExpense(event)" class="ns-btn ns-btn-primary"><i class="fas fa-save"></i> Save</button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/expense')"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <a href="?page=transactions/expense" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="expense-form">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="Expense">
        <input type="hidden" name="expense_account_id" value="<?php echo $data['expense_account_id']; ?>">
        <input type="hidden" name="expense_category" value="other">
        
        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Payee *</label>
                    <input type="text" name="party_id" class="ns-input" value="<?php echo $data['party_id'] ?? ''; ?>" required placeholder="Enter Payee Name">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Paid From (Bank/Cash) *</label>
                    <select name="paid_from_account_id" class="ns-select" required>
                        <option value="">Select Account</option>
                        <?php foreach($paid_from_accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" <?php echo ($data['paid_from_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Date *</label>
                    <input type="date" name="txn_date" class="ns-input" value="<?php echo $data['txn_date']; ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Amount *</label>
                    <input type="number" step="0.01" name="net_amount" class="ns-input" value="<?php echo $data['net_amount']; ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Reference #</label>
                    <input type="text" name="ref_number" class="ns-input" value="<?php echo $data['ref_number'] ?? ''; ?>">
                </div>
            </div>
        </div>

        <div class="ns-section-title">Description</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Memo / Description</label>
                    <textarea name="memo" class="ns-input" style="height: 60px;"><?php echo $data['memo'] ?? ''; ?></textarea>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function saveExpense(event) {
    const form = document.getElementById('expense-form');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);
    
    // Show loading state
    const btn = event.target;
    const originalText = btn.innerText;
    btn.innerText = 'Saving...';
    btn.disabled = true;

    fetch('api/save_expense.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            nsNotify(res.message, 'success');
            setTimeout(() => {
                window.location.href = '?page=transactions/view&id=' + res.id;
            }, 1000);
        } else {
            nsNotify(res.message || 'Failed to save expense', 'error');
            btn.innerText = originalText;
            btn.disabled = false;
        }
    })
    .catch(err => {
        nsNotify('Network error occurred', 'error');
        btn.innerText = originalText;
        btn.disabled = false;
    });
}
</script>
