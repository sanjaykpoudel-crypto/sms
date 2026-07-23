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
    $txn_items = $db->fetchAll(
        "SELECT tl.*, i.current_stock FROM transaction_lines tl
         LEFT JOIN items i ON tl.item_id = i.id
         WHERE tl.header_id = ?", [$id]);
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
$all_customers = $db->fetchAll("
    SELECT c.id, c.full_name, c.phone, c.email, c.pan_number,
           COALESCE(c.credit_limit, 0) as credit_limit,
           COALESCE(SUM(ci.balance_due), 0) as current_balance
    FROM customers c
    LEFT JOIN customer_invoices ci ON ci.customer_id = c.id
    LEFT JOIN transaction_headers h ON ci.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('voided', 'draft')" . ($id ? " AND h.id != '$id'" : "") . "
    WHERE c.is_active = 1 AND c.is_deleted = 0
    GROUP BY c.id
    ORDER BY c.full_name ASC
");
$all_accounts = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><i class="fas fa-file-invoice-dollar"
            style="margin-right: 10px; color: var(--ns-accent);"></i>
        <?php echo $id ? 'Edit' : 'New'; ?> Sales Invoice</div>
    <div class="ns-page-actions">
        <button type="submit" form="invoice-form" class="ns-btn ns-btn-primary"><i class="fas fa-save"></i>
            <?php echo $id ? 'Edit' : 'Save'; ?> Invoice</button>
        <button class="ns-btn" type="button"><i class="fas fa-print"></i> Print</button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/invoice')"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <a href="?page=transactions/invoice" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="invoice-form" method="POST" action="api/save_invoice.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="customer_invoice">

        <!-- Top Credit Limit Exceeded Warning Banner -->
        <div id="credit-limit-warning-banner" style="display: none; margin-bottom: 20px; padding: 14px 20px; background: #fff1f2; border: 1px solid #fecdd3; border-left: 6px solid #e11d48; border-radius: 8px; color: #9f1239; font-size: 13px; box-shadow: 0 4px 14px rgba(225, 29, 72, 0.12); transition: opacity 0.4s ease, transform 0.4s ease;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 20px; color: #e11d48; margin-top: 2px;"></i>
                    <div>
                        <div style="font-weight: 800; font-size: 14px; color: #be123c; display: flex; align-items: center; gap: 8px;">
                            <span>WARNING: Credit Limit Exceeded!</span>
                        </div>
                        <div id="credit-limit-warning-details" style="font-weight: 500; font-size: 12.5px; margin-top: 4px; color: #9f1239; line-height: 1.4;"></div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="background: #e11d48; color: #ffffff; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Limit Exceeded</span>
                    <button type="button" onclick="hideCreditWarningManual()" style="background: none; border: none; font-size: 20px; color: #be123c; cursor: pointer; padding: 0 4px; line-height: 1; font-weight: bold;" title="Close Warning">&times;</button>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Primary Information</div>
        <div id="customer-info-box" style="display: none; margin-bottom: 16px; padding: 12px 16px; background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid var(--ns-accent); border-radius: 6px; font-size: 13px; color: #334155; transition: all 0.2s ease;">
            <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                <span style="font-weight: 700; color: var(--ns-primary); font-size: 13px;">
                    <i class="fas fa-user" style="margin-right: 6px; color: var(--ns-accent);"></i><span id="customer-name-display">-</span>
                </span>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-phone-alt" style="color: #0284c7;"></i>
                    <span style="color: #64748b;">Phone:</span>
                    <a id="customer-phone-link" href="#" style="color: #0369a1; text-decoration: none; font-weight: 600;">-</a>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-file-invoice" style="color: #d97706;"></i>
                    <span style="color: #64748b;">VAT/PAN #:</span>
                    <span id="customer-vat" style="font-weight: 700; background: #ffffff; padding: 2px 8px; border-radius: 4px; border: 1px solid #cbd5e1; color: #1e293b;">-</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-credit-card" style="color: #8b5cf6;"></i>
                    <span style="color: #64748b;">Credit Limit:</span>
                    <span id="customer-credit-limit" style="font-weight: 700; color: #4c1d95;">-</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-wallet" style="color: #0284c7;"></i>
                    <span style="color: #64748b;">Current Balance:</span>
                    <span id="customer-current-balance" style="font-weight: 700; color: #0369a1;">-</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-calculator" style="color: #16a34a;"></i>
                    <span style="color: #64748b;">Available Credit:</span>
                    <span id="customer-available-credit" style="font-weight: 700; color: #15803d;">-</span>
                </div>
            </div>
        </div>
        <div class="ns-form-row">
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Customer <span class="ns-required">*</span></label>
                    <select name="party_id" class="ns-select" onchange="updateCustomerInfo(this)" required>
                        <option value="">Select Customer</option>
                        <?php foreach ($all_customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" 
                                    data-phone="<?php echo htmlspecialchars($c['phone'] ?? ''); ?>" 
                                    data-email="<?php echo htmlspecialchars($c['email'] ?? ''); ?>" 
                                    data-vat="<?php echo htmlspecialchars($c['pan_number'] ?? ''); ?>" 
                                    data-credit-limit="<?php echo (float)($c['credit_limit'] ?? 0); ?>" 
                                    data-current-balance="<?php echo (float)($c['current_balance'] ?? 0); ?>" 
                                    <?php echo ($data['party_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['full_name']); ?>
                            </option>
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
                        <th width="100" style="text-align: right; color: #1a7f37;">Profit</th>
                        <th width="85" style="text-align: right;">Tax %</th>
                        <th width="110" style="text-align: right;">Tax Amt</th>
                        <th width="130" style="text-align: right; color: var(--ns-primary);">Gross Amount</th>
                        <th width="55" style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = empty($txn_items) ? [null] : $txn_items;
                    $init_subtotal = 0;
                    $init_tax_total = 0;
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
                        $profit = $isNew ? '0.00' : (($ti['quantity'] * $ti['unit_price']) - ($ti['quantity'] * ($ti['cost_price'] ?? 0)));

                        if (!$isNew) {
                            $init_subtotal += (float)($ti['line_total'] - $ti['tax_amount']);
                            $init_tax_total += (float)$ti['tax_amount'];
                        }
                        ?>
                        <tr>
                            <td style="text-align: center; vertical-align: middle;"><?php echo $idx + 1; ?></td>
                            <td>
                                <select name="item_id[]" class="ns-select" onchange="invoiceFetchItem(this)" required>
                                    <option value="">Select item...</option>
                                    <?php foreach ($all_items as $i): ?>
                                        <option value="<?php echo $i['id']; ?>" <?php echo $i['id'] == $selItem ? 'selected' : ''; ?>><?php echo htmlspecialchars($i['item_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" class="ns-input stock-input ns-input-num ns-input-stock" value="<?php echo $isNew ? '' : ($ti['current_stock'] ?? ''); ?>"
                    readonly></td>
                            <td><input type="text" class="ns-input cost-input ns-input-num"
                                    style="background: #fff8f8; color: #a00;"
                                    value="<?php echo $isNew ? '0.00' : ($ti['cost_price'] ?? '0.00'); ?>" readonly
                                    tabindex="-1"></td>
                            <td><input type="number" name="qty[]" class="ns-input qty-input ns-input-num"
                                     value="<?php echo $qty; ?>" min="0" step="1" onfocus="this.select()"
                                     oninput="invoiceCalcFromRate(this)" onblur="this.value = Math.round(parseFloat(this.value || 0)); invoiceCalcFromRate(this)" onkeydown="invoiceCheckEnter(event)" required></td>
                            <td><input type="text" name="unit[]" class="ns-input unit-input" style="text-align: center;"
                                    value="<?php echo htmlspecialchars($unit); ?>" readonly tabindex="-1"></td>
                            <td><input type="number" name="rate[]" class="ns-input rate-input ns-input-num"
                                    value="<?php echo $rate; ?>" min="0" step="any" onfocus="this.select()"
                                    oninput="invoiceCalcFromRate(this)" onblur="this.value = parseFloat(this.value || 0).toFixed(2); invoiceCalcFromRate(this)" onkeydown="invoiceCheckEnter(event)" required></td>
                            <td><input type="number" name="amount[]"
                                    class="ns-input amount-input ns-input-num ns-input-subtotal"
                                    value="<?php echo $amount; ?>" min="0" step="any" onfocus="this.select()"
                                    oninput="invoiceCalcFromAmount(this)" onblur="this.value = parseFloat(this.value || 0).toFixed(2); invoiceCalcFromAmount(this)" onkeydown="invoiceCheckEnter(event)"></td>
                            <td><input type="number"
                                    class="ns-input profit-input ns-input-num" style="background: #f4fff4; color: #1a7f37; font-weight: bold;"
                                    value="<?php echo number_format($profit, 2, '.', ''); ?>" readonly tabindex="-1"></td>
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

        <?php
        $init_discount = (float)($data['discount_amount'] ?? 0);
        $init_grand_total = $init_subtotal + $init_tax_total - $init_discount;
        ?>
        <div class="ns-total-box">
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Subtotal (ex-tax)</span>
                <span id="invoice-subtotal"><?php echo number_format($init_subtotal, 2, '.', ''); ?></span>
            </div>
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Tax Total</span>
                <span id="invoice-tax-total"><?php echo number_format($init_tax_total, 2, '.', ''); ?></span>
            </div>
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Discount</span>
                <input type="number" name="discount_amount" class="ns-input"
                    value="<?php echo number_format($init_discount, 2, '.', ''); ?>" min="0" step="any"
                    style="width: 110px; text-align: right; height: 26px;" oninput="invoiceCalcTotals()">
            </div>
            <div class="ns-total-row"
                style="border-top: 2px solid var(--ns-primary); margin-top: 8px; padding-top: 8px;">
                <span style="color: var(--ns-primary); font-weight: bold; font-size: 14px;">TOTAL INVOICE</span>
                <span id="invoice-grand-total"
                    style="font-size: 22px; color: var(--ns-primary); font-weight: bold;"><?php echo number_format($init_grand_total, 2, '.', ''); ?></span>
            </div>
            <div class="ns-total-row" id="credit-status-total-row" style="display: none; margin-top: 6px; padding-top: 6px; border-top: 1px dashed #cbd5e1;">
                <span style="color: #64748b; font-size: 12px; font-weight: 600;">Expected Customer Total</span>
                <span id="invoice-new-total-balance" style="font-size: 13px; font-weight: bold; color: #0284c7;">0.00</span>
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
        const cost = parseFloat(row.querySelector('.cost-input').value) || 0;

        const amount = qty * rate;
        const taxAmt = amount * (taxPct / 100);
        const grossAmt = amount + taxAmt;
        const profit = amount - (qty * cost);

        row.querySelector('.amount-input').value = amount.toFixed(2);
        row.querySelector('.profit-input').value = profit.toFixed(2);
        row.querySelector('.tax-amt-input').value = taxAmt.toFixed(2);
        row.querySelector('.gross-amount-input').value = grossAmt.toFixed(2);

        invoiceCalcTotals();
    }

    function invoiceCalcFromAmount(el) {
        const row = el.closest('tr');
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const amount = parseFloat(row.querySelector('.amount-input').value) || 0;
        const taxPct = parseFloat(row.querySelector('.tax-pct-input').value) || 0;
        const cost = parseFloat(row.querySelector('.cost-input').value) || 0;

        let rate = qty > 0 ? amount / qty : 0;
        const taxAmt = amount * (taxPct / 100);
        const grossAmt = amount + taxAmt;
        const profit = amount - (qty * cost);

        row.querySelector('.rate-input').value = rate.toFixed(2);
        row.querySelector('.profit-input').value = profit.toFixed(2);
        row.querySelector('.tax-amt-input').value = taxAmt.toFixed(2);
        row.querySelector('.gross-amount-input').value = grossAmt.toFixed(2);

        invoiceCalcTotals();
    }

    function invoiceCalcFromGross(el) {
        const row = el.closest('tr');
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const taxPct = parseFloat(row.querySelector('.tax-pct-input').value) || 0;
        const grossAmt = parseFloat(row.querySelector('.gross-amount-input').value) || 0;
        const cost = parseFloat(row.querySelector('.cost-input').value) || 0;

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

        const profit = amount - (qty * cost);

        row.querySelector('.rate-input').value = rate.toFixed(2);
        row.querySelector('.amount-input').value = amount.toFixed(2);
        row.querySelector('.profit-input').value = profit.toFixed(2);
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

        checkCreditLimit(grandTotal);
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

    function updateCustomerInfo(select) {
        const opt = select.options[select.selectedIndex];
        const infoBox = document.getElementById('customer-info-box');
        if (!opt || !opt.value) {
            if (infoBox) infoBox.style.display = 'none';
            return;
        }
        const customerName = opt.text || '';
        const phone = opt.getAttribute('data-phone');
        const email = opt.getAttribute('data-email');
        const vat = opt.getAttribute('data-vat');

        const nameDisplay = document.getElementById('customer-name-display');
        if (nameDisplay) nameDisplay.textContent = customerName;

        const phoneLink = document.getElementById('customer-phone-link');
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

        const emailLink = document.getElementById('customer-email-link');
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

        const vatSpan = document.getElementById('customer-vat');
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

        // Trigger real-time credit check for selected customer
        invoiceCalcTotals();
    }

    let creditWarningTimer = null;

    function checkCreditLimit(grandTotal) {
        const customerSel = document.querySelector('select[name="party_id"]');
        if (!customerSel) return;
        const opt = customerSel.options[customerSel.selectedIndex];
        if (!opt || !opt.value) {
            hideCreditWarning();
            return;
        }

        const creditLimit = parseFloat(opt.getAttribute('data-credit-limit')) || 0;
        const currentBalance = parseFloat(opt.getAttribute('data-current-balance')) || 0;

        const limitEl = document.getElementById('customer-credit-limit');
        const balEl = document.getElementById('customer-current-balance');
        const availEl = document.getElementById('customer-available-credit');
        const warningBanner = document.getElementById('credit-limit-warning-banner');
        const warningDetails = document.getElementById('credit-limit-warning-details');
        const infoBox = document.getElementById('customer-info-box');
        const newTotalBalEl = document.getElementById('invoice-new-total-balance');
        const creditRow = document.getElementById('credit-status-total-row');

        const fmtVal = (v) => 'Rs ' + parseFloat(v || 0).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        if (limitEl) limitEl.textContent = creditLimit > 0 ? fmtVal(creditLimit) : 'No Limit';
        if (balEl) balEl.textContent = fmtVal(currentBalance);

        const newBalance = currentBalance + (parseFloat(grandTotal) || 0);
        const availableCredit = creditLimit > 0 ? (creditLimit - currentBalance) : null;

        if (availEl) {
            if (creditLimit > 0) {
                availEl.textContent = fmtVal(availableCredit);
                availEl.style.color = availableCredit < 0 ? '#e11d48' : '#15803d';
            } else {
                availEl.textContent = 'Unlimited';
                availEl.style.color = '#15803d';
            }
        }

        if (newTotalBalEl) newTotalBalEl.textContent = fmtVal(newBalance);
        if (creditRow) creditRow.style.display = 'flex';

        if (creditLimit > 0 && newBalance > creditLimit) {
            const exceededAmt = newBalance - creditLimit;
            if (warningDetails) {
                warningDetails.innerHTML = `Customer Credit Limit: <strong>${fmtVal(creditLimit)}</strong> | Current Outstanding: <strong>${fmtVal(currentBalance)}</strong><br>Invoice Total: <strong>${fmtVal(grandTotal)}</strong> → New Expected Balance: <strong>${fmtVal(newBalance)}</strong> (<strong style="color: #e11d48;">Exceeds limit by ${fmtVal(exceededAmt)}</strong>)`;
            }
            if (infoBox) {
                infoBox.style.background = '#fff1f2';
                infoBox.style.borderColor = '#fecdd3';
                infoBox.style.borderLeftColor = '#e11d48';
            }
            if (newTotalBalEl) newTotalBalEl.style.color = '#e11d48';

            // Show top warning banner with smooth animation & 10-second auto-disappear
            if (warningBanner) {
                warningBanner.style.display = 'block';
                setTimeout(() => {
                    warningBanner.style.opacity = '1';
                    warningBanner.style.transform = 'translateY(0)';
                }, 10);

                if (creditWarningTimer) clearTimeout(creditWarningTimer);

                // Auto-disappear after 10 seconds (10000ms)
                creditWarningTimer = setTimeout(() => {
                    hideCreditWarningManual();
                }, 10000);
            }
        } else {
            hideCreditWarning();
            if (creditWarningTimer) clearTimeout(creditWarningTimer);
        }
    }

    function hideCreditWarningManual() {
        const warningBanner = document.getElementById('credit-limit-warning-banner');
        if (warningBanner) {
            warningBanner.style.opacity = '0';
            warningBanner.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                warningBanner.style.display = 'none';
            }, 350);
        }
        if (creditWarningTimer) clearTimeout(creditWarningTimer);
    }

    function hideCreditWarning() {
        hideCreditWarningManual();
        const infoBox = document.getElementById('customer-info-box');
        if (infoBox) {
            infoBox.style.background = '#f8fafc';
            infoBox.style.borderColor = '#cbd5e1';
            infoBox.style.borderLeftColor = 'var(--ns-accent)';
        }
        const newTotalBalEl = document.getElementById('invoice-new-total-balance');
        if (newTotalBalEl) newTotalBalEl.style.color = '#0284c7';
    }

    function initInvoiceForm() {
        const customerSel = document.querySelector('select[name="party_id"]');
        if (customerSel) updateCustomerInfo(customerSel);

        invoiceCalcTotals();
        document.querySelectorAll('#invoice-items-table tbody tr').forEach(row => {
            const sel = row.querySelector('select[name="item_id[]"]');
            if (!sel || !sel.value) return;
            fetch('api/get_item_details.php?id=' + sel.value)
                .then(r => r.json())
                .then(data => {
                    if (data.error) return;
                    row.querySelector('.stock-input').value = parseFloat(data.current_stock || 0).toFixed(2);
                    row.querySelector('.unit-input').value = data.unit_name || data.unit_type || '';
                    const costEl = row.querySelector('.cost-input');
                    if (!costEl.value || parseFloat(costEl.value) === 0) {
                        costEl.value = parseFloat(data.cost_price || 0).toFixed(2);
                    }
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInvoiceForm);
    } else {
        initInvoiceForm();
    }
    window.addEventListener('load', initInvoiceForm);

</script>