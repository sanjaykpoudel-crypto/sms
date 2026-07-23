<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$txn_items = [];
if ($id) {
    // Fetch from header joined with vendor_bills sub-table
    $data = $db->fetchOne("SELECT t.*, vb.vendor_id as party_id, vb.due_date, vb.vendor_invoice_number as ref_number, vb.discount_amount
                          FROM transaction_headers t 
                          INNER JOIN vendor_bills vb ON t.id = vb.header_id 
                          WHERE t.id = ?", [$id]);
    $txn_items = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$id]);
} else {
    $data = [
        'txn_number' => getNextTransactionNumber('vendor_bill'),
        'txn_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+30 days')),
        'party_id' => '',
        'net_amount' => 0,
        'discount_amount' => 0,
        'status' => 'open',
        'ref_number' => '',
        'memo' => ''
    ];
}

$all_items = $db->fetchAll("SELECT id, item_name, sku FROM items WHERE is_active = 1 AND is_deleted = 0 ORDER BY item_name ASC");
$all_vendors = $db->fetchAll("SELECT id, company_name, phone, email, pan_number, vat_number FROM vendors WHERE is_active = 1 AND is_deleted = 0 ORDER BY company_name ASC");
$all_accounts = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><i class="fas fa-file-invoice" style="margin-right: 10px; color: var(--ns-accent);"></i>
        <?php echo $id ? 'Edit' : 'Enter'; ?> Bill</div>
    <div class="ns-page-actions">
        <button type="submit" form="bill-form" class="ns-btn ns-btn-primary"><i class="fas fa-save"></i> Save</button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/bill')"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <a href="?page=transactions/bill" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="bill-form" method="POST" action="api/save_bill.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="vendor_bill">

        <div class="ns-section-title">Primary Information</div>
        <div id="vendor-info-box" style="display: none; margin-bottom: 16px; padding: 10px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid var(--ns-accent); border-radius: 6px; font-size: 13px; color: #334155;">
            <div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap;">
                <span style="font-weight: 700; color: var(--ns-primary); font-size: 13px;">
                    <i class="fas fa-building" style="margin-right: 6px; color: var(--ns-accent);"></i><span id="vendor-name-display">-</span>
                </span>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-phone-alt" style="color: #0284c7;"></i>
                    <span style="color: #64748b;">Phone:</span>
                    <a id="vendor-phone-link" href="#" style="color: #0369a1; text-decoration: none; font-weight: 600;">-</a>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-envelope" style="color: #16a34a;"></i>
                    <span style="color: #64748b;">Email:</span>
                    <a id="vendor-email-link" href="#" style="color: #15803d; text-decoration: none; font-weight: 600;">-</a>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-file-invoice" style="color: #d97706;"></i>
                    <span style="color: #64748b;">VAT/PAN #:</span>
                    <span id="vendor-vat" style="font-weight: 700; background: #ffffff; padding: 2px 8px; border-radius: 4px; border: 1px solid #cbd5e1; color: #1e293b;">-</span>
                </div>
            </div>
        </div>
        <div class="ns-form-row">
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Vendor <span class="ns-required">*</span></label>
                    <select name="party_id" class="ns-select" onchange="updateVendorInfo(this)" required>
                        <option value="">Select Vendor</option>
                        <?php foreach($all_vendors as $v): 
                            $vVat = !empty($v['vat_number']) ? $v['vat_number'] : ($v['pan_number'] ?? '');
                        ?>
                            <option value="<?php echo $v['id']; ?>" 
                                    data-phone="<?php echo htmlspecialchars($v['phone'] ?? ''); ?>" 
                                    data-email="<?php echo htmlspecialchars($v['email'] ?? ''); ?>" 
                                    data-vat="<?php echo htmlspecialchars($vVat); ?>" 
                                    <?php echo ($data['party_id'] ?? '') == $v['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($v['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Bill #</label>
                    <input type="text" name="txn_number" class="ns-input" value="<?php echo $data['txn_number'] ?? ''; ?>" readonly style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Reference #</label>
                    <input type="text" name="ref_number" class="ns-input"
                        value="<?php echo $data['ref_number'] ?? ''; ?>" placeholder="Vendor Invoice #">
                </div>
            </div>
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Date <span class="ns-required">*</span></label>
                    <input type="date" name="txn_date" class="ns-input" value="<?php echo $data['txn_date']; ?>"
                        required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Due Date</label>
                    <input type="date" name="due_date" class="ns-input" value="<?php echo $data['due_date'] ?? ''; ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Memo</label>
                    <input type="text" name="memo" class="ns-input" value="<?php echo $data['memo'] ?? ''; ?>"
                        placeholder="Notes about this bill...">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Status</label>
                    <select name="status" class="ns-select" <?php echo $id ? 'disabled' : ''; ?>>
                        <option value="open" <?php echo ($data['status'] ?? '') == 'open' ? 'selected' : ''; ?>>Open</option>
                        <option value="paid" <?php echo ($data['status'] ?? '') == 'paid' ? 'selected' : ''; ?>>Paid in Full</option>
                        <option value="partial" <?php echo ($data['status'] ?? '') == 'partial' ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="voided" <?php echo ($data['status'] ?? '') == 'voided' ? 'selected' : ''; ?>>Voided</option>
                    </select>
                    <?php if($id): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($data['status'] ?? 'open'); ?>">
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Expense & Items</div>
        <div style="overflow-x: auto;">
            <table class="ns-item-table" id="bill-items-table">
                <thead>
                    <tr>
                        <th width="36" style="text-align: center;">#</th>
                        <th width="210">Item Name <span class="ns-required">*</span></th>
                        <th width="75" style="text-align: right;">Stock</th>
                        <th width="95" style="text-align: right;">Qty <span class="ns-required">*</span></th>
                        <th width="95" style="text-align: right; color: var(--ns-primary);">New Stock</th>
                        <th width="80" style="text-align: center;">Unit</th>
                        <th width="115" style="text-align: right;">Rate <span class="ns-required">*</span></th>
                        <th width="120" style="text-align: right;">Amount</th>
                        <th width="85" style="text-align: right;">Tax %</th>
                        <th width="110" style="text-align: right;">Tax Amt</th>
                        <th width="130" style="text-align: right; color: var(--ns-primary);">Gross Amount</th>
                        <th width="55" style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = empty($txn_items) ? [null] : $txn_items;
                    foreach ($rows as $idx => $ti):
                        $isNew = ($ti === null);
                        $qty = $isNew ? 1 : (int)$ti['quantity'];
                        $rate = $isNew ? '0.00' : number_format((float)$ti['unit_price'], 2, '.', '');
                        $amount = $isNew ? '0.00' : number_format((float)($ti['line_total'] - $ti['tax_amount']), 2, '.', '');
                        $taxPct = $isNew ? 0 : $ti['tax_rate'];
                        $taxAmt = $isNew ? '0.00' : $ti['tax_amount'];
                        $grossAmt = $isNew ? '0.00' : $ti['line_total'];
                        $unit = $isNew ? '' : ($ti['unit'] ?? '');
                        $selItem = $isNew ? '' : $ti['item_id'];
                        ?>
                        <tr>
                            <td style="text-align: center; vertical-align: middle;"><?php echo $idx + 1; ?></td>
                            <td>
                                <select name="item_id[]" class="ns-select" onchange="billFetchItem(this)" required>
                                    <option value="">Select item...</option>
                                    <?php foreach ($all_items as $i): ?>
                                        <option value="<?php echo $i['id']; ?>" <?php echo $i['id'] == $selItem ? 'selected' : ''; ?>><?php echo htmlspecialchars($i['item_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" class="ns-input stock-input ns-input-num ns-input-stock" value="" readonly></td>
                            <td><input type="number" name="qty[]" class="ns-input qty-input ns-input-num" value="<?php echo $qty; ?>" min="0" step="1" onfocus="this.select()" oninput="billCalcFromRate(this)" onblur="this.value = Math.round(parseFloat(this.value || 0)); billCalcFromRate(this)" onkeydown="billCheckEnter(event)" required></td>
                            <td><input type="text" class="ns-input new-stock-input ns-input-num" style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);" value="" readonly tabindex="-1"></td>
                            <td><input type="text" name="unit[]" class="ns-input unit-input" style="text-align: center;" value="<?php echo htmlspecialchars($unit); ?>" readonly tabindex="-1"></td>
                            <td><input type="number" name="rate[]" class="ns-input rate-input ns-input-num" value="<?php echo $rate; ?>" min="0" step="any" onfocus="this.select()" oninput="billCalcFromRate(this)" onblur="this.value = parseFloat(this.value || 0).toFixed(2); billCalcFromRate(this)" onkeydown="billCheckEnter(event)" required></td>
                            <td><input type="number" name="amount[]"
                                    class="ns-input amount-input ns-input-num ns-input-subtotal"
                                    value="<?php echo $amount; ?>" min="0" step="any" onfocus="this.select()"
                                    oninput="billCalcFromAmount(this)" onblur="this.value = parseFloat(this.value || 0).toFixed(2); billCalcFromAmount(this)" onkeydown="billCheckEnter(event)"></td>
                            <td><input type="number" name="tax_pct[]" class="ns-input tax-pct-input ns-input-num" value="<?php echo $taxPct; ?>" min="0" step="any" onfocus="this.select()" oninput="billCalcFromRate(this)"></td>
                            <td><input type="number" name="tax_amt[]" class="ns-input tax-amt-input ns-input-num ns-input-tax" value="<?php echo $taxAmt; ?>" readonly></td>
                            <td><input type="number" name="gross_amount[]" class="ns-input gross-amount-input ns-input-num ns-input-gross" value="<?php echo $grossAmt; ?>" min="0" step="any" onfocus="this.select()" onblur="billCalcFromGross(this)" onkeydown="billCheckEnter(event)"></td>
                            <td style="text-align: center;">
                                <span class="ns-line-btn ns-remove-line" onclick="nsRemoveLine(this)" title="Remove Line"><i class="fas fa-trash-alt"></i></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ns-grid-actions">
            <button type="button" class="ns-btn" onclick="nsAddLine('bill-items-table')"><i class="fas fa-plus-circle"></i> Add Line</button>
            <button type="button" class="ns-btn" onclick="nsClearLines('bill-items-table')" style="color: var(--ns-danger);"><i class="fas fa-eraser"></i> Clear All</button>
        </div>

        <div class="ns-total-box">
            <div class="ns-total-row">
                <span>Subtotal (ex-tax)</span>
                <span id="bill-subtotal">0.00</span>
            </div>
            <div class="ns-total-row">
                <span>Tax Total</span>
                <span id="bill-tax-total">0.00</span>
            </div>
            <div class="ns-total-row">
                <span>Discount</span>
                <input type="number" name="discount_amount" class="ns-input" value="<?php echo $data['discount_amount'] ?? 0; ?>" min="0" step="any" style="width: 110px; text-align: right; height: 26px;" oninput="billCalcTotals()">
            </div>
            <div class="ns-total-row" style="border-top: 2px solid var(--ns-primary); margin-top: 8px; padding-top: 8px;">
                <span style="color: var(--ns-primary); font-weight: bold; font-size: 14px;">TOTAL BILL</span>
                <span id="bill-grand-total" style="font-size: 22px; color: var(--ns-primary); font-weight: bold;">0.00</span>
            </div>
        </div>
        <div style="clear: both; margin-bottom: 50px;"></div>
    </form>
</div>

<script>
    document.getElementById('bill-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        
        // Validation: At least one item, qty > 0
        let hasValidItems = false;
        let validationError = '';
        const rows = form.querySelectorAll('#bill-items-table tbody tr');
        rows.forEach(row => {
            const itemId = row.querySelector('select[name="item_id[]"]')?.value;
            const qty = parseFloat(row.querySelector('input[name="qty[]"]')?.value) || 0;
            if (itemId) {
                if (qty <= 0) {
                    validationError = 'Quantity must be greater than 0 for all selected items.';
                } else {
                    hasValidItems = true;
                }
            }
        });

        if (!hasValidItems && !validationError) validationError = 'Please add at least one item to the bill.';
        if (validationError) {
            nsNotify(validationError, 'error');
            return;
        }

        const submitBtn = document.querySelector('button[form="bill-form"]');
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
                nsNotify(data.message || 'Record has been saved successfully.');
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

    function billCalcFromRate(el) {
        const row = el.closest('tr');
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
        const taxPct = parseFloat(row.querySelector('.tax-pct-input').value) || 0;

        const currentStock = parseFloat(row.querySelector('.stock-input').value) || 0;
        const newStock = currentStock + qty;
        row.querySelector('.new-stock-input').value = newStock.toFixed(2);

        const amount = qty * rate;
        const taxAmt = amount * (taxPct / 100);
        const grossAmt = amount + taxAmt;

        row.querySelector('.amount-input').value = amount.toFixed(2);
        row.querySelector('.tax-amt-input').value = taxAmt.toFixed(2);
        row.querySelector('.gross-amount-input').value = grossAmt.toFixed(2);

        billCalcTotals();
    }

    function billCalcFromAmount(el) {
        const row = el.closest('tr');
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const amount = parseFloat(row.querySelector('.amount-input').value) || 0;
        const taxPct = parseFloat(row.querySelector('.tax-pct-input').value) || 0;

        let rate = qty > 0 ? amount / qty : 0;
        const taxAmt = amount * (taxPct / 100);
        const grossAmt = amount + taxAmt;

        const currentStock = parseFloat(row.querySelector('.stock-input').value) || 0;
        const newStock = currentStock + qty;
        row.querySelector('.new-stock-input').value = newStock.toFixed(2);

        row.querySelector('.rate-input').value = rate.toFixed(2);
        row.querySelector('.tax-amt-input').value = taxAmt.toFixed(2);
        row.querySelector('.gross-amount-input').value = grossAmt.toFixed(2);

        billCalcTotals();
    }

    function billCalcFromGross(el) {
        const row = el.closest('tr');
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const taxPct = parseFloat(row.querySelector('.tax-pct-input').value) || 0;
        const grossAmt = parseFloat(row.querySelector('.gross-amount-input').value) || 0;

        let amount, taxAmt, rate;
        if (taxPct === 0) {
            amount = grossAmt;
            taxAmt = 0;
            rate = qty > 0 ? grossAmt / qty : 0;
        } else {
            amount = grossAmt / (1 + taxPct / 100);
            taxAmt = grossAmt - amount;
            rate = qty > 0 ? amount / qty : 0;
        }

        const currentStock = parseFloat(row.querySelector('.stock-input').value) || 0;
        const newStock = currentStock + qty;
        row.querySelector('.new-stock-input').value = newStock.toFixed(2);

        row.querySelector('.rate-input').value = rate.toFixed(2);
        row.querySelector('.amount-input').value = amount.toFixed(2);
        row.querySelector('.tax-amt-input').value = taxAmt.toFixed(2);

        billCalcTotals();
    }

    function billCalcTotals() {
        let subtotal = 0;
        let taxTotal = 0;
        document.querySelectorAll('#bill-items-table tbody tr').forEach(row => {
            subtotal += parseFloat(row.querySelector('.amount-input')?.value) || 0;
            taxTotal += parseFloat(row.querySelector('.tax-amt-input')?.value) || 0;
        });
        const discount = parseFloat(document.querySelector('input[name="discount_amount"]')?.value) || 0;
        const grandTotal = subtotal + taxTotal - discount;

        document.getElementById('bill-subtotal').innerText = subtotal.toFixed(2);
        document.getElementById('bill-tax-total').innerText = taxTotal.toFixed(2);
        document.getElementById('bill-grand-total').innerText = grandTotal.toFixed(2);
    }

    function billFetchItem(select) {
        const itemId = select.value;
        const row = select.closest('tr');
        if (!itemId) {
            row.querySelector('.stock-input').value = '';
            row.querySelector('.unit-input').value = '';
            row.querySelector('.rate-input').value = '0.00';
            row.querySelector('.tax-pct-input').value = '0';
            row.querySelector('.amount-input').value = '0.00';
            row.querySelector('.tax-amt-input').value = '0.00';
            row.querySelector('.gross-amount-input').value = '0.00';
            return;
        }

        fetch('api/get_item_details.php?id=' + itemId)
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                row.querySelector('.stock-input').value = parseFloat(data.current_stock || 0).toFixed(2);
                row.querySelector('.unit-input').value = data.unit_name || data.unit_type || '';
                row.querySelector('.rate-input').value = data.cost_price;
                row.querySelector('.tax-pct-input').value = data.tax_rate || 0;
                
                billCalcFromRate(row.querySelector('.qty-input'));
            });
    }

    function billCheckEnter(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            nsAddLine('bill-items-table');
            setTimeout(() => {
                const rows = document.querySelectorAll('#bill-items-table tbody tr');
                const lastRow = rows[rows.length - 1];
                const sel = lastRow.querySelector('select');
                if (sel) sel.focus();
            }, 10);
        }
    }

    function updateVendorInfo(select) {
        const opt = select.options[select.selectedIndex];
        const infoBox = document.getElementById('vendor-info-box');
        if (!opt || !opt.value) {
            if (infoBox) infoBox.style.display = 'none';
            return;
        }
        const vendorName = opt.text || '';
        const phone = opt.getAttribute('data-phone');
        const email = opt.getAttribute('data-email');
        const vat = opt.getAttribute('data-vat');

        const nameDisplay = document.getElementById('vendor-name-display');
        if (nameDisplay) nameDisplay.textContent = vendorName;

        const phoneLink = document.getElementById('vendor-phone-link');
        if (phone && phone.trim() !== '') {
            phoneLink.textContent = phone;
            phoneLink.href = 'tel:' + phone;
            phoneLink.style.fontStyle = 'normal';
            phoneLink.style.color = '#0369a1';
        } else {
            phoneLink.textContent = 'Not Provided';
            phoneLink.removeAttribute('href');
            phoneLink.style.fontStyle = 'italic';
            phoneLink.style.color = '#94a3b8';
        }

        const emailLink = document.getElementById('vendor-email-link');
        if (email && email.trim() !== '') {
            emailLink.textContent = email;
            emailLink.href = 'mailto:' + email;
            emailLink.style.fontStyle = 'normal';
            emailLink.style.color = '#15803d';
        } else {
            emailLink.textContent = 'Not Provided';
            emailLink.removeAttribute('href');
            emailLink.style.fontStyle = 'italic';
            emailLink.style.color = '#94a3b8';
        }

        const vatSpan = document.getElementById('vendor-vat');
        if (vat && vat.trim() !== '') {
            vatSpan.textContent = vat;
            vatSpan.style.fontStyle = 'normal';
            vatSpan.style.color = '#1e293b';
        } else {
            vatSpan.textContent = 'Not Registered';
            vatSpan.style.fontStyle = 'italic';
            vatSpan.style.color = '#94a3b8';
        }

        if (infoBox) infoBox.style.display = 'block';
    }

    window.addEventListener('load', function () {
        const vendorSel = document.querySelector('select[name="party_id"]');
        if (vendorSel) updateVendorInfo(vendorSel);

        billCalcTotals();
        document.querySelectorAll('#bill-items-table tbody tr').forEach(row => {
            const sel = row.querySelector('select[name="item_id[]"]');
            if (!sel || !sel.value) return;
            fetch('api/get_item_details.php?id=' + sel.value)
                .then(r => r.json())
                .then(data => {
                    if (data.error) return;
                    row.querySelector('.stock-input').value = parseFloat(data.current_stock || 0).toFixed(2);
                    row.querySelector('.unit-input').value = data.unit_name || data.unit_type || '';
                    // Do not auto-calculate on load to preserve precisely saved amounts
                });
        });
    });
</script>