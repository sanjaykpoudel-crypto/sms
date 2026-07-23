<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$lines = [];
if ($id) {
    $data = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
    $lines = $db->fetchAll("SELECT * FROM journal_entries WHERE header_id = ? ORDER BY id ASC", [$id]);
} else {
    $data = [
        'txn_number' => getNextTransactionNumber('journal_entry'),
        'txn_date' => date('Y-m-d')
    ];
}

if (empty($lines)) {
    $lines = [
        ['account_id' => '', 'entry_type' => 'debit', 'amount' => 0, 'party_type' => '', 'party_id' => '', 'memo' => '']
    ];
}

$customers = $db->fetchAll("SELECT id, full_name as name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY name ASC");
$vendors = $db->fetchAll("SELECT id, company_name as name FROM vendors WHERE is_active = 1 AND is_deleted = 0 ORDER BY name ASC");
$users = $db->fetchAll("SELECT id, full_name as name FROM users WHERE is_active = 1 AND is_deleted = 0 ORDER BY name ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Journal Entry</div>
    <div class="ns-page-actions">
        <button type="submit" form="journal-form" class="ns-btn ns-btn-primary"><?php echo $id ? 'Edit' : 'Save'; ?></button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/journal')"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <button type="button" onclick="window.location.href='?page=transactions/journal'" class="ns-btn">Cancel</button>
    </div>
</div>

<div class="ns-form-container">
    <form id="journal-form" method="POST" action="api/save_journal.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="Journal">
        
        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row" style="display: flex; gap: 40px; align-items: flex-start; justify-content: space-between; width: 100%;">
            <div style="flex: 2; max-width: 60%; display: flex; flex-direction: column; gap: 8px;">
                <div class="ns-form-group">
                    <label class="ns-label">Entry #</label>
                    <input type="text" name="txn_number" class="ns-input" value="<?php echo $data['txn_number']; ?>" readonly style="background: #f0f0f0;">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Date *</label>
                    <input type="date" name="txn_date" class="ns-input" value="<?php echo $data['txn_date']; ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Reference #</label>
                    <input type="text" name="ref_number" class="ns-input" value="<?php echo $data['ref_number'] ?? ''; ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Memo</label>
                    <input type="text" name="memo" class="ns-input" value="<?php echo $data['memo'] ?? ''; ?>">
                </div>
            </div>
            <div style="flex: 1; min-width: 350px;">
                <div class="ns-total-box" style="float: none; margin-top: 0; width: 100%;">
                    <div class="ns-total-row">
                        <span>Total Debit</span>
                        <span id="total-debit">0.00</span>
                    </div>
                    <div class="ns-total-row">
                        <span>Total Credit</span>
                        <span id="total-credit">0.00</span>
                    </div>
                    <div class="ns-total-row">
                        <span>Out of Balance</span>
                        <span id="out-of-balance" style="color: #c00;">0.00</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="ns-section-title">GL Impact</div>
        <table class="ns-item-table">
            <thead>
                <tr>
                    <th width="30">#</th>
                    <th>Account</th>
                    <th width="140">Debit</th>
                    <th width="140">Credit</th>
                    <th>Memo</th>
                    <th width="200">Name / Entity</th>
                    <th width="40"></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $accounts = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
                foreach ($lines as $i => $line):
                    $debit_val = ($line['entry_type'] === 'debit') ? (float)$line['amount'] : 0.00;
                    $credit_val = ($line['entry_type'] === 'credit') ? (float)$line['amount'] : 0.00;
                    $line_party_type = $line['party_type'] ?? '';
                    $line_party_id = $line['party_id'] ?? '';
                ?>
                <tr>
                    <td class="text-center"><?php echo $i+1; ?></td>
                    <td>
                        <select name="account_id[]" class="ns-select" style="width: 100%;">
                            <option value="">Select Account</option>
                            <?php foreach($accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>" <?php echo ($acc['id'] == $line['account_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($acc['account_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" name="debit[]" class="ns-input debit-input" value="<?php echo number_format($debit_val, 2, '.', ''); ?>" step="0.01" oninput="handleDebitInput(this)"></td>
                    <td><input type="number" name="credit[]" class="ns-input credit-input" value="<?php echo number_format($credit_val, 2, '.', ''); ?>" step="0.01" oninput="handleCreditInput(this)"></td>
                    <td><input type="text" name="line_memo[]" class="ns-input" value="<?php echo htmlspecialchars($line['memo'] ?? ''); ?>"></td>
                    <td>
                        <div style="display: flex; gap: 2px;">
                            <select name="line_party_type[]" class="ns-select" style="width: 70px; font-size: 10px;" onchange="updateLineEntity(this)">
                                <option value="">Type</option>
                                <option value="customer" <?php echo ($line_party_type === 'customer') ? 'selected' : ''; ?>>Cust</option>
                                <option value="vendor" <?php echo ($line_party_type === 'vendor') ? 'selected' : ''; ?>>Vend</option>
                                <option value="user" <?php echo ($line_party_type === 'user') ? 'selected' : ''; ?>>Emp</option>
                            </select>
                            <select name="line_party_id[]" class="ns-select line-party-select" style="flex: 1; font-size: 10px;">
                                <option value="">Name</option>
                                <?php 
                                if ($line_party_type) {
                                    $party_list = [];
                                    if ($line_party_type === 'customer') $party_list = $customers;
                                    elseif ($line_party_type === 'vendor') $party_list = $vendors;
                                    elseif ($line_party_type === 'user') $party_list = $users;
                                    
                                    foreach ($party_list as $p) {
                                        $sel = ($p['id'] == $line_party_id) ? 'selected' : '';
                                        echo "<option value=\"{$p['id']}\" {$sel}>" . htmlspecialchars($p['name']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </td>
                    <td><button type="button" class="ns-btn-link text-danger remove-line-btn" onclick="removeLine(this)" style="background: none; border: none; cursor: pointer; color: #ef4444;"><i class="fas fa-trash"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="ns-btn" style="margin-top: 10px;" onclick="addLine()"><i class="fas fa-plus"></i> Add Line</button>


        <div style="clear: both;"></div>
    </form>
</div>
<script>
    const entities = {
        customer: <?php echo json_encode($customers); ?>,
        vendor: <?php echo json_encode($vendors); ?>,
        user: <?php echo json_encode($users); ?>
    };
    const accounts = <?php echo json_encode($accounts); ?>;

    function updateLineEntity(selectEl) {
        const type = selectEl.value;
        const row = selectEl.closest('tr');
        const entitySelect = row.querySelector('.line-party-select');
        
        entitySelect.innerHTML = '<option value="">Name</option>';
        if (type && entities[type]) {
            entities[type].forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                entitySelect.appendChild(opt);
            });
        }
    }

    function handleDebitInput(input) {
        const row = input.closest('tr');
        const creditInput = row.querySelector('.credit-input');
        if (parseFloat(input.value) > 0) {
            creditInput.value = '0.00';
        }
        calculateTotals();
    }

    function handleCreditInput(input) {
        const row = input.closest('tr');
        const debitInput = row.querySelector('.debit-input');
        if (parseFloat(input.value) > 0) {
            debitInput.value = '0.00';
        }
        calculateTotals();
    }

    function addLine() {
        const tbody = document.querySelector('.ns-item-table tbody');
        const rowCount = tbody.rows.length;
        const tr = document.createElement('tr');
        
        // Calculate unbalanced amount
        let totalDebit = 0;
        let totalCredit = 0;
        document.querySelectorAll('.debit-input').forEach(input => {
            totalDebit += parseFloat(input.value) || 0;
        });
        document.querySelectorAll('.credit-input').forEach(input => {
            totalCredit += parseFloat(input.value) || 0;
        });
        
        let newDebit = '0.00';
        let newCredit = '0.00';
        if (totalDebit > totalCredit) {
            newCredit = (totalDebit - totalCredit).toFixed(2);
        } else if (totalCredit > totalDebit) {
            newDebit = (totalCredit - totalDebit).toFixed(2);
        }

        let accountOptions = '<option value="">Select Account</option>';
        accounts.forEach(acc => {
            accountOptions += `<option value="${acc.id}">${acc.account_name}</option>`;
        });

        tr.innerHTML = `
            <td class="text-center">${rowCount + 1}</td>
            <td>
                <select name="account_id[]" class="ns-select" style="width: 100%;">
                    ${accountOptions}
                </select>
            </td>
            <td><input type="number" name="debit[]" class="ns-input debit-input" value="${newDebit}" step="0.01" oninput="handleDebitInput(this)"></td>
            <td><input type="number" name="credit[]" class="ns-input credit-input" value="${newCredit}" step="0.01" oninput="handleCreditInput(this)"></td>
            <td><input type="text" name="line_memo[]" class="ns-input"></td>
            <td>
                <div style="display: flex; gap: 2px;">
                    <select name="line_party_type[]" class="ns-select" style="width: 70px; font-size: 10px;" onchange="updateLineEntity(this)">
                        <option value="">Type</option>
                        <option value="customer">Cust</option>
                        <option value="vendor">Vend</option>
                        <option value="user">Emp</option>
                    </select>
                    <select name="line_party_id[]" class="ns-select line-party-select" style="flex: 1; font-size: 10px;">
                        <option value="">Name</option>
                    </select>
                </div>
            </td>
            <td><button type="button" class="ns-btn-link text-danger remove-line-btn" onclick="removeLine(this)" style="background: none; border: none; cursor: pointer; color: #ef4444;"><i class="fas fa-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
        calculateTotals();
    }

    function removeLine(btn) {
        const row = btn.closest('tr');
        const tbody = row.parentNode;
        if (tbody.rows.length <= 1) {
            nsNotify('A journal entry must have at least 1 line.', 'warning');
            return;
        }
        row.remove();
        
        // Re-index rows
        Array.from(tbody.rows).forEach((r, idx) => {
            r.cells[0].textContent = idx + 1;
        });
        
        calculateTotals();
    }

    function calculateTotals() {
        let totalDebit = 0;
        let totalCredit = 0;
        
        document.querySelectorAll('.debit-input').forEach(input => {
            totalDebit += parseFloat(input.value) || 0;
        });
        document.querySelectorAll('.credit-input').forEach(input => {
            totalCredit += parseFloat(input.value) || 0;
        });
        
        document.getElementById('total-debit').textContent = totalDebit.toFixed(2);
        document.getElementById('total-credit').textContent = totalCredit.toFixed(2);
        
        const diff = Math.abs(totalDebit - totalCredit);
        const diffEl = document.getElementById('out-of-balance');
        diffEl.textContent = diff.toFixed(2);
        
        if (diff > 0.005) {
            diffEl.style.color = '#ef4444';
        } else {
            diffEl.style.color = '#10b981';
        }
    }

    window.addEventListener('load', () => {
        calculateTotals();
        const form = document.getElementById('journal-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Check out of balance
            let totalDebit = 0;
            let totalCredit = 0;
            document.querySelectorAll('.debit-input').forEach(input => {
                totalDebit += parseFloat(input.value) || 0;
            });
            document.querySelectorAll('.credit-input').forEach(input => {
                totalCredit += parseFloat(input.value) || 0;
            });
            
            if (Math.abs(totalDebit - totalCredit) > 0.005) {
                nsNotify('The journal entry is out of balance. Debits must equal Credits.', 'error');
                return;
            }
            
            if (totalDebit <= 0) {
                nsNotify('The total amount must be greater than zero.', 'error');
                return;
            }

            const submitBtn = document.querySelector('button[form="journal-form"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;

            const formData = new FormData(this);
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    nsNotify(data.message);
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
    });
</script>
