<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$txn_items = [];
if ($id) {
    // Fetch from transaction_headers
    $data = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ? AND txn_type = 'inventory_adjustment'", [$id]);
    $txn_items = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$id]);
} else {
    $data = [
        'txn_number' => getNextTransactionNumber('inventory_adjustment'),
        'txn_date' => date('Y-m-d'),
        'memo' => '',
        'status' => 'posted',
        // Optional default account (e.g. acc-6160 - Miscellaneous Expense)
        'party_id' => 'acc-6160' 
    ];
}

$all_items = $db->fetchAll("SELECT id, item_name, sku, cost_price, current_stock FROM items WHERE is_active = 1 AND is_deleted = 0 ORDER BY item_name ASC");
$expense_accounts = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE account_type IN ('expense', 'income', 'equity') AND is_active = 1 AND is_deleted = 0 ORDER BY account_code ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><i class="fas fa-warehouse" style="margin-right: 10px; color: var(--ns-accent);"></i>
        <?php echo $id ? 'Edit' : 'New'; ?> Inventory Adjustment</div>
    <div class="ns-page-actions">
        <button type="submit" form="adjustment-form" class="ns-btn ns-btn-primary"><i class="fas fa-save"></i> Save Adjustment</button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/adjustment')"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <a href="?page=transactions/adjustment" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="adjustment-form" method="POST" action="api/save_adjustment.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="inventory_adjustment">

        <div class="ns-section-title">Adjustment Details</div>
        <div class="ns-form-row">
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Adjustment #</label>
                    <input type="text" name="txn_number" class="ns-input" value="<?php echo $data['txn_number'] ?? ''; ?>" readonly style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Adjustment Account <span class="ns-required">*</span></label>
                    <select name="adjustment_account_id" class="ns-select" required>
                        <option value="">Select Account</option>
                        <?php foreach($expense_accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" <?php echo ($data['party_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>>
                                <?php echo $acc['account_code'] . ' - ' . htmlspecialchars($acc['account_name']); ?>
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
                    <label class="ns-label">Memo / Reason</label>
                    <input type="text" name="memo" class="ns-input" value="<?php echo $data['memo'] ?? ''; ?>" placeholder="e.g., Damaged items, annual audit recount...">
                </div>
            </div>
        </div>

        <div class="ns-section-title">Adjusted Items</div>
        <div style="overflow-x: auto;">
            <table class="ns-item-table" id="adjustment-items-table">
                <thead>
                    <tr>
                        <th width="36" style="text-align: center;">#</th>
                        <th width="220">Item Name <span class="ns-required">*</span></th>
                        <th width="100" style="text-align: right;">Current Stock</th>
                        <th width="120" style="text-align: right;">Adjustment Qty (+/-) <span class="ns-required">*</span></th>
                        <th width="100" style="text-align: right; color: #312e81;">New Stock</th>
                        <th width="80" style="text-align: center;">Unit</th>
                        <th width="110" style="text-align: right;">Current Cost</th>
                        <th width="120" style="text-align: right;">New/Adjusted Cost <span class="ns-required">*</span></th>
                        <th width="130" style="text-align: right; color: var(--ns-primary);">Adjusted Value</th>
                        <th width="55" style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = empty($txn_items) ? [null] : $txn_items;
                    foreach ($rows as $idx => $ti):
                        $isNew = ($ti === null);
                        $qty = $isNew ? '' : $ti['quantity'];
                        $currentCost = $isNew ? '0.00' : $ti['cost_price'];
                        $newCost = $isNew ? '0.00' : $ti['unit_price'];
                        $adjustedVal = $isNew ? '0.00' : ($ti['quantity'] * $ti['unit_price']);
                        $unit = $isNew ? '' : ($ti['unit'] ?? '');
                        $selItem = $isNew ? '' : $ti['item_id'];
                        ?>
                        <tr>
                            <td style="text-align: center; vertical-align: middle;"><?php echo $idx + 1; ?></td>
                            <td>
                                <select name="item_id[]" class="ns-select" onchange="adjFetchItem(this)" required>
                                    <option value="">Select item...</option>
                                    <?php foreach ($all_items as $i): ?>
                                        <option value="<?php echo $i['id']; ?>" <?php echo $i['id'] == $selItem ? 'selected' : ''; ?>><?php echo htmlspecialchars($i['item_name']); ?> (<?php echo $i['sku']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" class="ns-input stock-input ns-input-num ns-input-stock" value="" readonly style="background: #f8fafc; color: #475569; font-weight: 600;"></td>
                            <td><input type="number" name="qty[]" class="ns-input qty-input ns-input-num" value="<?php echo $qty; ?>" step="any" onfocus="this.select()" oninput="adjCalcRow(this)" onkeydown="adjCheckEnter(event)" placeholder="e.g. -5 or 10" required></td>
                            <td><input type="text" class="ns-input new-stock-input ns-input-num ns-input-readonly" value="" readonly style="background: #eef2ff; color: #312e81; font-weight: 700;" tabindex="-1"></td>
                            <td><input type="text" name="unit[]" class="ns-input unit-input" style="text-align: center;" value="<?php echo htmlspecialchars($unit); ?>" readonly tabindex="-1"></td>
                            <td><input type="number" class="ns-input current-cost-input ns-input-num ns-input-readonly" value="<?php echo $currentCost; ?>" readonly></td>
                            <td><input type="number" name="rate[]" class="ns-input new-cost-input ns-input-num" value="<?php echo $newCost; ?>" min="0" step="any" onfocus="this.select()" oninput="adjCalcRow(this)" onkeydown="adjCheckEnter(event)" required></td>
                            <td><input type="number" name="amount[]" class="ns-input amount-input ns-input-num ns-input-subtotal" value="<?php echo $adjustedVal; ?>" readonly></td>
                            <td style="text-align: center;">
                                <span class="ns-line-btn ns-remove-line" onclick="nsRemoveLine(this)" title="Remove Line"><i class="fas fa-trash-alt"></i></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ns-grid-actions">
            <button type="button" class="ns-btn" onclick="nsAddLine('adjustment-items-table')"><i class="fas fa-plus-circle"></i> Add Line</button>
            <button type="button" class="ns-btn" onclick="nsClearLines('adjustment-items-table')" style="color: var(--ns-danger);"><i class="fas fa-eraser"></i> Clear All</button>
        </div>

        <div class="ns-total-box" style="margin-top: 20px;">
            <div class="ns-total-row" style="border-top: 2px solid var(--ns-primary); padding-top: 8px;">
                <span style="color: var(--ns-primary); font-weight: bold; font-size: 13px;">NET ADJUSTED VALUE</span>
                <span id="adjustment-grand-total" style="font-size: 20px; color: var(--ns-primary); font-weight: bold;">Rs. 0.00</span>
            </div>
        </div>
        <div style="clear: both; margin-bottom: 50px;"></div>
    </form>
</div>

<script>
    document.getElementById('adjustment-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        
        let hasValidItems = false;
        let validationError = '';
        const rows = form.querySelectorAll('#adjustment-items-table tbody tr');
        rows.forEach(row => {
            const itemId = row.querySelector('select[name="item_id[]"]')?.value;
            const qty = parseFloat(row.querySelector('input[name="qty[]"]')?.value);
            if (itemId) {
                if (isNaN(qty) || qty === 0) {
                    validationError = 'Adjustment Quantity cannot be zero for all selected items.';
                } else {
                    hasValidItems = true;
                }
            }
        });

        if (!hasValidItems && !validationError) validationError = 'Please add at least one item to adjust.';
        if (validationError) {
            nsNotify(validationError, 'error');
            return;
        }

        const submitBtn = document.querySelector('button[form="adjustment-form"]');
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
                nsNotify(data.message || 'Adjustment saved successfully.');
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

    function adjCalcRow(el) {
        const row = el.closest('tr');
        const stockInput = row.querySelector('.stock-input');
        const stockVal = stockInput ? stockInput.value : '';
        const stock = parseFloat(stockVal);
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const newCost = parseFloat(row.querySelector('.new-cost-input').value) || 0;

        const newStockEl = row.querySelector('.new-stock-input');
        if (newStockEl) {
            if (stockVal === '' || isNaN(stock)) {
                newStockEl.value = '';
            } else {
                newStockEl.value = (stock + qty).toFixed(2);
            }
        }

        const amount = qty * newCost;
        row.querySelector('.amount-input').value = amount.toFixed(2);

        adjCalcTotals();
    }

    function adjCalcTotals() {
        let netTotal = 0;
        document.querySelectorAll('#adjustment-items-table tbody tr').forEach(row => {
            netTotal += parseFloat(row.querySelector('.amount-input')?.value) || 0;
        });
        document.getElementById('adjustment-grand-total').innerText = 'Rs. ' + Math.abs(netTotal).toLocaleString(undefined, {minimumFractionDigits: 2});
    }

    function adjFetchItem(select) {
        const itemId = select.value;
        const row = select.closest('tr');
        if (!itemId) {
            row.querySelector('.stock-input').value = '';
            const newStockEl = row.querySelector('.new-stock-input');
            if (newStockEl) newStockEl.value = '';
            row.querySelector('.unit-input').value = '';
            row.querySelector('.current-cost-input').value = '0.00';
            row.querySelector('.new-cost-input').value = '0.00';
            row.querySelector('.amount-input').value = '0.00';
            return;
        }

        fetch('api/get_item_details.php?id=' + itemId)
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                const currentStock = (data.current_stock !== undefined && data.current_stock !== null) ? parseFloat(data.current_stock) : 0;
                row.querySelector('.stock-input').value = currentStock.toFixed(2);
                row.querySelector('.unit-input').value = data.unit_name || data.unit_type || '';
                row.querySelector('.current-cost-input').value = parseFloat(data.cost_price || 0).toFixed(2);
                row.querySelector('.new-cost-input').value = parseFloat(data.cost_price || 0).toFixed(2);
                
                adjCalcRow(row.querySelector('.qty-input'));
            });
    }

    function adjCheckEnter(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            nsAddLine('adjustment-items-table');
            setTimeout(() => {
                const rows = document.querySelectorAll('#adjustment-items-table tbody tr');
                const lastRow = rows[rows.length - 1];
                const sel = lastRow.querySelector('select');
                if (sel) sel.focus();
            }, 10);
        }
    }

    // Auto-fetch details on load for editing
    window.addEventListener('load', function() {
        document.querySelectorAll('#adjustment-items-table tbody tr').forEach(row => {
            const sel = row.querySelector('select');
            if (sel && sel.value) {
                adjFetchItem(sel);
            }
        });
        adjCalcTotals();
    });
</script>
