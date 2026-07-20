<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$payment_lines = [];

if ($id) {
    $data = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
    $payment_lines = $db->fetchAll("SELECT * FROM payments WHERE header_id = ?", [$id]);
    $data['party_type'] = ($data['txn_type'] === 'vendor_payment') ? 'vendor' : 'customer';
    $first_line = $payment_lines[0] ?? [];
    $data['party_id'] = ($first_line['customer_id'] ?? null) ?: ($first_line['vendor_id'] ?? null) ?: '';
} else {
    $party_type = $_GET['party_type'] ?? 'customer';
    $txn_prefix = $party_type === 'vendor' ? 'vendor_payment' : 'customer_payment';
    $data = [
        'txn_number' => getNextTransactionNumber($txn_prefix),
        'txn_date' => date('Y-m-d'),
        'party_id' => $_GET['party_id'] ?? '',
        'party_type' => $party_type,
        'memo' => '',
        'reference_number' => ''
    ];
}

// Fetch Cash and Bank Accounts
$accounts = $db->fetchAll("SELECT id, account_name, account_subtype FROM accounts WHERE account_subtype IN ('cash', 'bank') AND is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");

// Fetch Customers and Vendors
$customers = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
$vendors = $db->fetchAll("SELECT id, company_name FROM vendors WHERE is_active = 1 AND is_deleted = 0 ORDER BY company_name ASC");

// Get default accounts from preferences
$default_bank = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_bank_account'")['meta_value'] ?? '';
$default_cash = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_cash_account'")['meta_value'] ?? '';
?>
<style>
    .pos-style-container { display: flex; gap: 20px; align-items: flex-start; }
    .pos-style-left { flex: 1.5; }
    .pos-style-right { flex: 1; position: sticky; top: 20px; }
    
    .pos-pay-box { background: #fff; border: 1px solid #ddd; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .pos-pay-header { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #666; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
    
    .pos-pay-row { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; background: #f8f9fa; padding: 10px; border-radius: 8px; border: 1px solid #eee; }
    .pos-pay-select { flex: 1.5; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; outline: none; }
    .pos-pay-input { flex: 1; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; font-weight: 700; text-align: right; outline: none; }
    
    .pos-summary-box { background: #4a5d7a; color: white; border-radius: 12px; padding: 25px; margin-bottom: 20px; text-align: center; }
    .pos-summary-label { font-size: 11px; text-transform: uppercase; opacity: 0.8; margin-bottom: 5px; }
    .pos-summary-value { font-size: 36px; font-weight: 800; }
    
    .pos-apply-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; border: 1px solid #eee; }
    .pos-apply-table th { background: #f1f4f8; padding: 12px; text-align: left; font-size: 12px; color: #4a5d7a; border-bottom: 1px solid #dee2e6; }
    .pos-apply-table td { padding: 12px; border-bottom: 1px solid #eee; font-size: 13px; }
    
    .btn-split { background: #eef2f7; color: #4a5d7a; border: 1px solid #ccd6e0; padding: 5px 12px; border-radius: 15px; font-size: 11px; font-weight: 700; cursor: pointer; transition: 0.2s; }
    .btn-split:hover { background: #4a5d7a; color: white; }
</style>

<div class="ns-form-header">
    <div class="ns-form-title">
        <i class="fas fa-cash-register" style="margin-right: 10px; color: #4a5d7a;"></i>
        <?php echo $id ? 'Edit' : 'Record'; ?> Payment
    </div>
    <div class="ns-page-actions">
        <button type="submit" form="payment-form" class="ns-btn ns-btn-primary"><i class="fas fa-check-circle"></i> <?php echo $id ? 'Edit' : 'Save'; ?></button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/payment')"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <button type="button" onclick="history.back()" class="ns-btn"><i class="fas fa-times"></i> Cancel</button>
    </div>
</div>

<div class="ns-form-container">
    <form id="payment-form" method="POST" action="api/save_transaction.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="Payment">
        <input type="hidden" name="net_amount" id="net_amount" value="0.00">

        <div class="pos-style-container">
            <!-- Left Side: Party & Application -->
            <div class="pos-style-left">
                <div class="ns-portlet" style="margin-bottom: 20px;">
                    <div class="ns-portlet-content">
                        <div class="ns-form-row">
                            <div style="flex: 1;">
                                <div class="ns-form-group">
                                    <label class="ns-label">Type</label>
                                    <select name="party_type" id="party_type" class="ns-select" onchange="updatePartyLabel(this.value)">
                                        <option value="customer" <?php echo ($data['party_type'] == 'customer') ? 'selected' : ''; ?>>Customer Payment (In)</option>
                                        <option value="vendor" <?php echo ($data['party_type'] == 'vendor') ? 'selected' : ''; ?>>Vendor Payment (Out)</option>
                                    </select>
                                </div>
                                <div class="ns-form-group">
                                    <label class="ns-label" id="party-label">Customer</label>
                                    <select name="party_id" id="party_id" class="ns-select" required onchange="fetchOpenTransactions()">
                                        <option value="">Select Party...</option>
                                        <?php 
                                        $parties = ($data['party_type'] == 'vendor') ? $vendors : $customers;
                                        $name_field = ($data['party_type'] == 'vendor') ? 'company_name' : 'full_name';
                                        foreach($parties as $p): ?>
                                            <option value="<?php echo $p['id']; ?>" <?php echo ($data['party_id'] == $p['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p[$name_field]); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div style="flex: 1;">
                                <div class="ns-form-group">
                                    <label class="ns-label">Payment #</label>
                                    <input type="text" name="txn_number" class="ns-input" value="<?php echo $data['txn_number'] ?? ''; ?>" readonly style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);">
                                </div>
                                <div class="ns-form-group">
                                    <label class="ns-label">Date</label>
                                    <input type="date" name="txn_date" class="ns-input" value="<?php echo $data['txn_date']; ?>" required>
                                </div>
                                <div class="ns-form-group">
                                    <label class="ns-label">Global Ref</label>
                                    <input type="text" name="reference_number" class="ns-input" value="<?php echo $data['reference_number'] ?? ''; ?>" placeholder="Reference / Cheque #">
                                </div>
                            </div>
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Notes</label>
                            <textarea name="memo" class="ns-input" style="height: 40px;"><?php echo $data['memo'] ?? ''; ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="ns-section-title" id="apply-title">Apply to Open Invoices</div>
                <div style="background: white; border-radius: 12px; border: 1px solid #ddd; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    <table class="pos-apply-table">
                        <thead>
                            <tr>
                                <th width="30">#</th>
                                <th>Transaction #</th>
                                <th>Date</th>
                                <th style="text-align: right;">Original Amount</th>
                                <th style="text-align: right;">Balance Due</th>
                                <th width="150" style="text-align: right;">Payment</th>
                                <th width="60" style="text-align: center;">Apply</th>
                            </tr>
                        </thead>
                        <tbody id="open-txns-body">
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                                    <i class="fas fa-user-clock" style="font-size: 32px; display: block; margin-bottom: 10px; opacity: 0.3;"></i>
                                    Select a customer/vendor to see open transactions.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Side: POS Style Payment Box -->
            <div class="pos-style-right">
                <div class="pos-summary-box">
                    <div class="pos-summary-label">Total Payment Tendered</div>
                    <div class="pos-summary-value" id="display-total-payment">0.00</div>
                    <div style="font-size: 11px; margin-top: 5px; opacity: 0.8;">NPR</div>
                </div>

                <div class="pos-pay-box">
                    <div class="pos-pay-header">
                        <span>Payment Methods</span>
                        <button type="button" class="btn-split" onclick="addPaymentRow()"><i class="fas fa-plus"></i> Split</button>
                    </div>

                    <div id="payment-rows-container">
                        <!-- Rendered by JS -->
                        <?php 
                        $p_rows = empty($payment_lines) ? [null] : $payment_lines;
                        foreach($p_rows as $idx => $pl): 
                            $isNew = ($pl === null);
                        ?>
                        <div class="pos-pay-row">
                            <select name="bank_account_id[]" class="pos-pay-select" required>
                                <option value="">Select Account...</option>
                                <?php foreach($accounts as $acc): 
                                    $isSelected = (!$isNew && $pl['bank_account_id'] == $acc['id']) || ($isNew && $acc['id'] == $default_bank);
                                ?>
                                    <option value="<?php echo $acc['id']; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($acc['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" step="0.01" name="line_amount[]" class="pos-pay-input payment-line-amount" 
                                   value="<?php echo $isNew ? '0.00' : $pl['amount']; ?>" oninput="calculatePaymentTotals()">
                            <button type="button" class="ns-btn-icon" style="color: #c00; margin-left: 5px;" onclick="removePaymentRow(this)"><i class="fas fa-times"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top: 20px; border-top: 2px dashed #eee; padding-top: 15px;">
                        <div style="display: flex; justify-content: space-between; font-size: 13px; color: #666; margin-bottom: 8px;">
                            <span>Total Applied</span>
                            <span id="total-applied-summary">0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 800; color: #c00;" id="unapplied-row">
                            <span>Unapplied Balance</span>
                            <span id="unapplied-balance">0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    document.getElementById('payment-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const submitBtn = document.querySelector('button[form="payment-form"]');
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
                nsNotify(data.message || 'Payment has been recorded successfully.');
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

    const accounts = <?php echo json_encode($accounts); ?>;

function addPaymentRow() {
    const container = document.getElementById('payment-rows-container');
    const row = document.createElement('div');
    row.className = 'pos-pay-row';
    
    let options = '<option value="">Select Account...</option>';
    accounts.forEach(acc => {
        options += `<option value="${acc.id}">${acc.account_name}</option>`;
    });

    row.innerHTML = `
        <select name="bank_account_id[]" class="pos-pay-select" required>
            ${options}
        </select>
        <input type="number" step="0.01" name="line_amount[]" class="pos-pay-input payment-line-amount" value="0.00" oninput="calculatePaymentTotals()">
        <button type="button" class="ns-btn-icon" style="color: #c00; margin-left: 5px;" onclick="removePaymentRow(this)"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(row);
}

function removePaymentRow(btn) {
    const container = document.getElementById('payment-rows-container');
    if (container.children.length > 1) {
        btn.closest('.pos-pay-row').remove();
        calculatePaymentTotals();
    }
}


const customersData = <?php echo json_encode($customers); ?>;
const vendorsData = <?php echo json_encode($vendors); ?>;
const customerTxnNumber = "<?php echo getNextTransactionNumber('customer_payment'); ?>";
const vendorTxnNumber = "<?php echo getNextTransactionNumber('vendor_payment'); ?>";
const isEditMode = <?php echo $id ? 'true' : 'false'; ?>;

function updatePartyLabel(type) {
    const label = document.getElementById('party-label');
    const applyTitle = document.getElementById('apply-title');
    const partySelect = document.getElementById('party_id');
    
    label.innerText = (type === 'customer') ? 'Customer' : 'Vendor';
    applyTitle.innerText = (type === 'customer') ? 'Apply to Open Invoices' : 'Apply to Open Bills';
    
    if (!isEditMode) {
        document.querySelector('input[name="txn_number"]').value = (type === 'customer') ? customerTxnNumber : vendorTxnNumber;
    }
    
    partySelect.innerHTML = '<option value="">Select Party...</option>';
    const dataList = (type === 'customer') ? customersData : vendorsData;
    const nameField = (type === 'customer') ? 'full_name' : 'company_name';
    
    dataList.forEach(party => {
        const option = document.createElement('option');
        option.value = party.id;
        option.innerText = party[nameField];
        partySelect.appendChild(option);
    });
    
    fetchOpenTransactions();
}

function calculatePaymentTotals() {
    let totalPayment = 0;
    document.querySelectorAll('.payment-line-amount').forEach(input => {
        totalPayment += parseFloat(input.value) || 0;
    });
    
    document.getElementById('display-total-payment').innerText = totalPayment.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('net_amount').value = totalPayment.toFixed(2);
    
    updateUnappliedBalance();
}

function updateUnappliedBalance() {
    const totalPayment = parseFloat(document.getElementById('net_amount').value) || 0;
    let totalApplied = 0;
    document.querySelectorAll('.apply-amount-input').forEach(input => {
        if (input.closest('tr').querySelector('.apply-checkbox').checked) {
            totalApplied += parseFloat(input.value) || 0;
        }
    });
    
    document.getElementById('total-applied-summary').innerText = totalApplied.toFixed(2);
    const unapplied = totalPayment - totalApplied;
    const unappliedEl = document.getElementById('unapplied-balance');
    unappliedEl.innerText = unapplied.toFixed(2);
    
    const unappliedRow = document.getElementById('unapplied-row');
    if (Math.abs(unapplied) < 0.01) {
        unappliedRow.style.color = '#28a745';
    } else {
        unappliedRow.style.color = '#c00';
    }
}

function toggleApply(checkbox) {
    const row = checkbox.closest('tr');
    const amountInput = row.querySelector('.apply-amount-input');
    const balanceDue = parseFloat(row.querySelector('.balance-due-text').innerText) || 0;
    
    if (checkbox.checked) {
        const totalPayment = parseFloat(document.getElementById('net_amount').value) || 0;
        let alreadyApplied = 0;
        document.querySelectorAll('.apply-amount-input').forEach(input => {
            if (input !== amountInput && input.closest('tr').querySelector('.apply-checkbox').checked) {
                alreadyApplied += parseFloat(input.value) || 0;
            }
        });
        
        const remaining = Math.max(0, totalPayment - alreadyApplied);
        amountInput.value = Math.min(remaining, balanceDue).toFixed(2);
        amountInput.readOnly = false;
    } else {
        amountInput.value = '0.00';
        amountInput.readOnly = true;
    }
    updateUnappliedBalance();
}

function fetchOpenTransactions() {
    const partyId = document.getElementById('party_id').value;
    const partyType = document.getElementById('party_type').value;
    const paymentId = document.querySelector('input[name="id"]').value;
    const body = document.getElementById('open-txns-body');
    if (!partyId) {
        body.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #999;"><i class="fas fa-user-clock" style="font-size: 32px; display: block; margin-bottom: 10px; opacity: 0.3;"></i>Select a customer/vendor to see open transactions.</td></tr>';
        return;
    }
    
    body.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading transactions...</td></tr>';
    
    fetch(`api/get_open_transactions.php?party_id=${partyId}&party_type=${partyType}&payment_id=${paymentId}`)
    .then(r => r.json())
    .then(data => {
        if (data.length === 0) {
            body.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px; color: #666;">No open transactions found for this party.</td></tr>';
            return;
        }
        
        let html = '';
        data.forEach((row, idx) => {
            const appliedAmt = parseFloat(row.applied_amount) || 0;
            const isChecked = appliedAmt > 0 ? 'checked' : '';
            const readOnly = appliedAmt > 0 ? '' : 'readonly';
            html += `
                <tr>
                    <td style="text-align: center;">${idx + 1}</td>
                    <td><a href="?page=transactions/view&id=${row.id}" target="_blank">${row.txn_number}</a></td>
                    <td>${row.txn_date}</td>
                    <td style="text-align: right;">${parseFloat(row.total_amount).toFixed(2)}</td>
                    <td style="text-align: right;" class="balance-due-text">${parseFloat(row.balance_due).toFixed(2)}</td>
                    <td><input type="number" name="apply_amount[${row.id}]" class="ns-input apply-amount-input" value="${appliedAmt.toFixed(2)}" step="0.01" style="text-align: right;" ${readOnly} oninput="updateUnappliedBalance()"></td>
                    <td style="text-align: center;"><input type="checkbox" name="apply_txn_id[]" value="${row.id}" class="apply-checkbox" onchange="toggleApply(this)" ${isChecked}></td>
                </tr>
            `;
        });
        body.innerHTML = html;
        updateUnappliedBalance();
    })
    .catch(err => {
        body.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px; color: #c00;">Error fetching transactions.</td></tr>';
    });
}

window.addEventListener('load', function() {
    if (isEditMode || document.getElementById('party_id').value) {
        fetchOpenTransactions();
    }
    calculatePaymentTotals();
});
</script>
