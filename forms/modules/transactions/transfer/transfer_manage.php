<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
if ($id) {
    // Fetch from transaction_headers and join with account_transfers
    $data = $db->fetchOne("SELECT t.*, at2.from_account_id, at2.to_account_id, at2.amount, at2.transfer_type
                          FROM transaction_headers t 
                          INNER JOIN account_transfers at2 ON t.id = at2.header_id 
                          WHERE t.id = ?", [$id]);
} else {
    $data = [
        'txn_number' => getNextTransactionNumber('account_transfer'),
        'txn_date' => date('Y-m-d'),
        'from_account_id' => '',
        'to_account_id' => '',
        'amount' => '0.00',
        'memo' => '',
        'status' => 'posted'
    ];
}

// Fetch Cash and Bank Accounts
$bank_accounts = $db->fetchAll("SELECT id, account_code, account_name, account_subtype FROM accounts WHERE account_subtype IN ('bank', 'cash') AND is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><i class="fas fa-random" style="margin-right: 10px; color: var(--ns-accent);"></i>
        <?php echo $id ? 'Edit' : 'New'; ?> Bank Fund Transfer</div>
    <div class="ns-page-actions">
        <button type="submit" form="transfer-form" class="ns-btn ns-btn-primary"><i class="fas fa-save"></i> Save Transfer</button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/transfer')"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <button type="button" onclick="history.back()" class="ns-btn"><i class="fas fa-times"></i> Cancel</button>
    </div>
</div>

<div class="ns-form-container">
    <form id="transfer-form" method="POST" action="api/save_transfer.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="account_transfer">

        <div class="ns-section-title">Fund Transfer Details</div>
        <div class="ns-form-row">
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Transfer #</label>
                    <input type="text" name="txn_number" class="ns-input" value="<?php echo $data['txn_number'] ?? ''; ?>" readonly style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">From Account <span class="ns-required">*</span></label>
                    <select name="from_account_id" class="ns-select" required>
                        <option value="">Select Source Account</option>
                        <?php foreach($bank_accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" <?php echo ($data['from_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">To Account <span class="ns-required">*</span></label>
                    <select name="to_account_id" class="ns-select" required>
                        <option value="">Select Destination Account</option>
                        <?php foreach($bank_accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" <?php echo ($data['to_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Date <span class="ns-required">*</span></label>
                    <input type="date" name="txn_date" class="ns-input" value="<?php echo $data['txn_date']; ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Transfer Amount <span class="ns-required">*</span></label>
                    <input type="number" name="amount" class="ns-input ns-input-gross" value="<?php echo $data['amount']; ?>" min="0.01" step="any" required style="font-size: 16px; font-weight: bold; color: #003366;">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Memo / Description</label>
                    <input type="text" name="memo" class="ns-input" value="<?php echo $data['memo'] ?? ''; ?>" placeholder="Notes about this transfer...">
                </div>
            </div>
        </div>
        <div style="margin-bottom: 50px;"></div>
    </form>
</div>

<script>
    document.getElementById('transfer-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;

        // Validation
        const fromAcc = form.querySelector('select[name="from_account_id"]').value;
        const toAcc = form.querySelector('select[name="to_account_id"]').value;
        const amount = parseFloat(form.querySelector('input[name="amount"]').value) || 0;

        if (fromAcc === toAcc) {
            nsNotify('Source and Destination accounts cannot be the same.', 'error');
            return;
        }
        if (amount <= 0) {
            nsNotify('Transfer amount must be greater than zero.', 'error');
            return;
        }

        const submitBtn = document.querySelector('button[form="transfer-form"]');
        const originalText = submitBtn.innerHTML;

        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;

        const formData = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                nsNotify(data.message || 'Fund transfer recorded successfully.');
                setTimeout(() => {
                    window.location.href = '?page=transactions/view&id=' + data.id;
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
