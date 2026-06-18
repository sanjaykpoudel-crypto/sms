<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$txn_items = [];
if ($id) {
    $data = $db->fetchOne("SELECT t.*, ci.customer_id as party_id, ci.due_date, ci.discount_amount
                          FROM transaction_headers t 
                          INNER JOIN customer_invoices ci ON t.id = ci.header_id 
                          WHERE t.id = ?", [$id]);
    $txn_items = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$id]);
} else {
    $data = [
        'txn_number' => getNextTransactionNumber('customer_invoice'),
        'txn_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+15 days')),
        'party_id' => '',
        'net_amount' => 0,
        'discount_amount' => 0,
        'status' => 'open',
        'memo' => ''
    ];
}

$all_items = $db->fetchAll("SELECT id, item_name, sku FROM items WHERE is_active = 1 AND is_deleted = 0 ORDER BY item_name ASC");
$all_customers = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
$all_accounts = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_code ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><i class="fas fa-file-invoice-dollar"
            style="margin-right: 10px; color: var(--ns-accent);"></i>
        <?php echo $id ? 'Edit' : 'New'; ?> Sales Invoice</div>
    <div class="ns-page-actions">
        <button type="submit" form="invoice-form" class="ns-btn ns-btn-primary"><i class="fas fa-save"></i>
            <?php echo $id ? 'Edit' : 'Save'; ?> Invoice</button>
        <button class="ns-btn" type="button"><i class="fas fa-print"></i> Print</button>
        <button type="button" onclick="history.back()" class="ns-btn"><i class="fas fa-times"></i> Cancel</button>
    </div>
</div>

<div class="ns-form-container">
    <form id="invoice-form" method="POST" action="api/save_invoice.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="customer_invoice">

        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Customer <span class="ns-required">*</span></label>
                    <select name="party_id" class="ns-select" required>
                        <option value="">Select Customer</option>
                        <?php foreach ($all_customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($data['party_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Invoice #</label>
                    <input type="text" name="txn_number" class="ns-input" value="<?php echo $data['txn_number']; ?>"
                        readonly style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);">
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
                    <input type="date" name="due_date" class="ns-input" value="<?php echo $data['due_date']; ?>">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Memo / Remarks</label>
                    <input type="text" name="memo" class="ns-input" value="<?php echo $data['memo'] ?? ''; ?>"
                        placeholder="Enter any notes here...">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Status</label>
                    <select name="status" class="ns-select" <?php echo $id ? 'disabled' : ''; ?>>
                        <option value="open" <?php echo ($data['status'] ?? '') == 'open' ? 'selected' : ''; ?>>Open
                        </option>
                        <option value="paid" <?php echo ($data['status'] ?? '') == 'paid' ? 'selected' : ''; ?>>Paid in
                            Full</option>
                        <option value="partial" <?php echo ($data['status'] ?? '') == 'partial' ? 'selected' : ''; ?>>
                            Partially Paid</option>
                        <option value="voided" <?php echo ($data['status'] ?? '') == 'voided' ? 'selected' : ''; ?>>Voided
                        </option>
                    </select>
                    <?php if ($id): ?>
                        <input type="hidden" name="status"
                            value="<?php echo htmlspecialchars($data['status'] ?? 'open'); ?>">
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Line Items</div>
        <div style="overflow-x: auto;">
            <table class="ns-item-table" id="invoice-items-table">
                <thead>
                    <tr>
                        <th width="36" style="text-align: center;">#</th>
                        <th width="210">Item Name <span class="ns-required">*</span></th>
                        <th width="75" style="text-align: right;">Stock</th>
                        <th width="85" style="text-align: right;">Cost</th>
                        <th width="95" style="text-align: right;">Qty <span class="ns-required">*</span></th>
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
                        $qty = $isNew ? 1 : $ti['quantity'];
                        $rate = $isNew ? '0.00' : $ti['unit_price'];
                        $amount = $isNew ? '0.00' : ($ti['quantity'] * $ti['unit_price']);
                        $taxPct = $isNew ? 0 : $ti['tax_rate'];
                        $taxAmt = $isNew ? '0.00' : $ti['tax_amount'];
                        $grossAmt = $isNew ? '0.00' : $ti['line_total'];
                        $unit = $isNew ? '' : ($ti['unit'] ?? '');
                        $selItem = $isNew ? '' : $ti['item_id'];
                        ?>
                        <tr>
                            <td style="text-align: center; vertical-align: middle;"><?php echo $idx + 1; ?></td>
                            <td>
                                <select name="item_id[]" class="ns-select" onchange="invoiceFetchItem(this)" required>
                                    <option value="">Select item...</option>
                                    <?php foreach ($all_items as $i): ?>
                                        <option value="<?php echo $i['id']; ?>" <?php echo $i['id'] == $selItem ? 'selected' : ''; ?>><?php echo htmlspecialchars($i['item_name']); ?> (<?php echo $i['sku']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" class="ns-input stock-input ns-input-num ns-input-stock" value=""
                                    readonly></td>
                            <td><input type="text" class="ns-input cost-input ns-input-num"
                                    style="background: #fff8f8; color: #a00;"
                                    value="<?php echo $isNew ? '0.00' : ($ti['cost_price'] ?? '0.00'); ?>" readonly
                                    tabindex="-1"></td>
                            <td><input type="number" name="qty[]" class="ns-input qty-input ns-input-num"
                                    value="<?php echo $qty; ?>" min="0" step="any" onfocus="this.select()"
                                    oninput="invoiceCalcFromRate(this)" onkeydown="invoiceCheckEnter(event)" required></td>
                            <td><input type="text" name="unit[]" class="ns-input unit-input" style="text-align: center;"
                                    value="<?php echo htmlspecialchars($unit); ?>" readonly tabindex="-1"></td>
                            <td><input type="number" name="rate[]" class="ns-input rate-input ns-input-num"
                                    value="<?php echo $rate; ?>" min="0" step="any" onfocus="this.select()"
                                    oninput="invoiceCalcFromRate(this)" onkeydown="invoiceCheckEnter(event)" required></td>
                            <td><input type="number" name="amount[]"
                                    class="ns-input amount-input ns-input-num ns-input-subtotal"
                                    value="<?php echo $amount; ?>" readonly></td>
                            <td><input type="number" name="tax_pct[]" class="ns-input tax-pct-input ns-input-num"
                                    value="<?php echo $taxPct; ?>" min="0" step="any" onfocus="this.select()"
                                    oninput="invoiceCalcFromRate(this)"></td>
                            <td><input type="number" name="tax_amt[]"
                                    class="ns-input tax-amt-input ns-input-num ns-input-tax" value="<?php echo $taxAmt; ?>"
                                    readonly></td>
                            <td><input type="number" name="gross_amount[]"
                                    class="ns-input gross-amount-input ns-input-num ns-input-gross"
                                    value="<?php echo $grossAmt; ?>" min="0" step="any" onfocus="this.select()"
                                    onblur="invoiceCalcFromGross(this)" onkeydown="invoiceCheckEnter(event)"></td>
                            <td style="text-align: center;">
                                <span class="ns-line-btn ns-remove-line" onclick="nsRemoveLine(this)" title="Remove Line"><i
                                        class="fas fa-trash-alt"></i></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ns-grid-actions">
            <button type="button" class="ns-btn" onclick="nsAddLine('invoice-items-table')"><i
                    class="fas fa-plus-circle"></i> Add Line</button>
            <button type="button" class="ns-btn" onclick="nsClearLines('invoice-items-table')"
                style="color: var(--ns-danger);"><i class="fas fa-eraser"></i> Clear All</button>
        </div>

        <div class="ns-total-box">
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Subtotal (ex-tax)</span>
                <span id="invoice-subtotal">0.00</span>
            </div>
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Tax Total</span>
                <span id="invoice-tax-total">0.00</span>
            </div>
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Discount</span>
                <input type="number" name="discount_amount" class="ns-input"
                    value="<?php echo $data['discount_amount'] ?? 0; ?>" min="0" step="any"
                    style="width: 110px; text-align: right; height: 26px;" oninput="invoiceCalcTotals()">
            </div>
            <div class="ns-total-row"
                style="border-top: 2px solid var(--ns-primary); margin-top: 8px; padding-top: 8px;">
                <span style="color: var(--ns-primary); font-weight: bold; font-size: 14px;">TOTAL INVOICE</span>
                <span id="invoice-grand-total"
                    style="font-size: 22px; color: var(--ns-primary); font-weight: bold;">0.00</span>
            </div>
        </div>
        <div style="clear: both; margin-bottom: 50px;"></div>
    </form>
</div>

<script>
    document.getElementById('invoice-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = this;

        // Validation: At least one item, qty > 0
        let hasValidItems = false;
        let validationError = '';
        const rows = form.querySelectorAll('#invoice-items-table tbody tr');
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

        if (!hasValidItems && !validationError) validationError = 'Please add at least one item to the invoice.';
        if (validationError) {
            nsNotify(validationError, 'error');
            return;
        }

        const submitBtn = document.querySelector('button[form="invoice-form"]');
        const originalText = submitBtn.innerHTML;

        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;

        const formData = new FormData(form);

        function submitInvoice(fData) {
            fetch(form.action, {
                method: 'POST',
                body: fData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        nsNotify(data.message || 'Record has been saved successfully.');
                        setTimeout(() => {
                            window.location.href = '?page=transactions/view&id=' + data.id;
                        }, 1500);
                    } else if (data.status === 'stock_warning') {
                        // Show premium modal dialog
                        nsConfirm(data.message,
                            function () { // OK clicked - force save
                                fData.append('force_save', '1');
                                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                                submitBtn.disabled = true;
                                submitInvoice(fData);
                            },
                            function () { // Cancel clicked - restore button
                                submitBtn.innerHTML = originalText;
                                submitBtn.disabled = false;
                            }
                        );
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
        }

        submitInvoice(formData);
    });

    function invoiceCalcFromRate(el) {
        const row = el.closest('tr');
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
        const taxPct = parseFloat(row.querySelector('.tax-pct-input').value) || 0;

        const amount = qty * rate;
        const taxAmt = amount * (taxPct / 100);
        const grossAmt = amount + taxAmt;

        row.querySelector('.amount-input').value = amount.toFixed(2);
        row.querySelector('.tax-amt-input').value = taxAmt.toFixed(2);
        row.querySelector('.gross-amount-input').value = grossAmt.toFixed(2);

        invoiceCalcTotals();
    }

    function invoiceCalcFromGross(el) {
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

        row.querySelector('.rate-input').value = rate.toFixed(4);
        row.querySelector('.amount-input').value = amount.toFixed(2);
        row.querySelector('.tax-amt-input').value = taxAmt.toFixed(2);

        invoiceCalcTotals();
    }

    function invoiceCalcTotals() {
        let subtotal = 0;
        let taxTotal = 0;
        document.querySelectorAll('#invoice-items-table tbody tr').forEach(row => {
            subtotal += parseFloat(row.querySelector('.amount-input')?.value) || 0;
            taxTotal += parseFloat(row.querySelector('.tax-amt-input')?.value) || 0;
        });
        const discount = parseFloat(document.querySelector('input[name="discount_amount"]')?.value) || 0;
        const grandTotal = subtotal + taxTotal - discount;

        document.getElementById('invoice-subtotal').innerText = subtotal.toFixed(2);
        document.getElementById('invoice-tax-total').innerText = taxTotal.toFixed(2);
        document.getElementById('invoice-grand-total').innerText = grandTotal.toFixed(2);
    }

    function invoiceFetchItem(select) {
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
                row.querySelector('.cost-input').value = parseFloat(data.cost_price || 0).toFixed(2);
                row.querySelector('.unit-input').value = data.unit_name || data.unit_type || '';
                row.querySelector('.rate-input').value = data.selling_price;
                row.querySelector('.tax-pct-input').value = data.tax_rate || 0;


                invoiceCalcFromRate(row.querySelector('.qty-input'));
            });
    }

    function invoiceCheckEnter(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            nsAddLine('invoice-items-table');
            setTimeout(() => {
                const rows = document.querySelectorAll('#invoice-items-table tbody tr');
                const lastRow = rows[rows.length - 1];
                const sel = lastRow.querySelector('select');
                if (sel) sel.focus();
            }, 10);
        }
    }

    window.addEventListener('load', invoiceCalcTotals);
</script>