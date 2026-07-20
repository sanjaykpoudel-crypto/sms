<?php
require_once 'database/DBConnection.php';
$db = db();

// Fetch all bank and cash accounts
$accounts = $db->fetchAll("SELECT * FROM accounts WHERE account_subtype IN ('bank', 'cash') AND is_deleted = 0 AND is_active = 1 ORDER BY account_code ASC");

// Fetch existing opening balances transaction date if any
$opening_txn = $db->fetchOne("SELECT txn_date FROM transaction_headers WHERE txn_number = 'OPENING-BALANCES'");
$opening_date = $opening_txn ? $opening_txn['txn_date'] : (date('Y') . '-01-01');
?>
<div class="ns-form-header">
    <div class="ns-form-title">Bank Opening Balances</div>
    <div class="ns-page-actions">
        <button type="submit" form="opening-balances-form" class="ns-btn ns-btn-primary">Save Balances</button>
        <a href="?page=master/account" class="ns-btn">Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="opening-balances-form" method="POST" action="api/save_opening_balances.php">
        <div class="ns-section-title">Set Opening Balances for Bank Accounts</div>
        <p class="ns-text-muted" style="margin-bottom: 20px; font-size: 13px;">
            Enter the opening balance for each bank account. Positive amounts are Debits. 
            An offsetting entry will be automatically generated to the **Opening Balance** account (`open`) to ensure double-entry accounting is balanced.
        </p>

        <div class="ns-form-row" style="margin-bottom: 20px;">
            <div style="flex: 0 0 250px;">
                <div class="ns-form-group">
                    <label class="ns-label" for="opening_balance_date">Opening Balance Date *</label>
                    <input type="date" id="opening_balance_date" name="opening_balance_date" class="ns-input" required value="<?php echo htmlspecialchars($opening_date); ?>">
                </div>
            </div>
        </div>

        <div class="ns-portlet">
            <div class="ns-portlet-content" style="padding: 0;">
                <table class="ns-table">
                    <thead>
                        <tr>
                            <th>Account Code</th>
                            <th>Account Name</th>
                            <th style="width: 250px; text-align: right; padding-right: 25px;">Opening Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #888; padding: 30px;">
                                <i class="fas fa-university" style="font-size: 24px; display: block; margin-bottom: 8px; opacity: 0.3;"></i>
                                No active bank accounts found.
                            </td>
                        </tr>
                        <?php else: foreach ($accounts as $row): ?>
                        <tr>
                            <td style="font-weight: 600;"><?php echo htmlspecialchars($row['account_code']); ?></td>
                            <td><?php echo htmlspecialchars($row['account_name']); ?></td>
                            <td style="text-align: right; padding-right: 25px;">
                                <input type="number" 
                                       name="balances[<?php echo $row['id']; ?>]" 
                                       class="ns-input" 
                                       style="width: 200px; text-align: right; font-weight: 600; display: inline-block;" 
                                       value="<?php echo number_format($row['opening_balance'] ?? 0, 2, '.', ''); ?>" 
                                       step="0.01" 
                                       min="0">
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>

<script>
document.getElementById('opening-balances-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const submitBtn = document.querySelector('button[form="opening-balances-form"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;

    const formData = new FormData(form);

    fetch('api/save_opening_balances.php', {
        method: 'POST',
        body: formData
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
