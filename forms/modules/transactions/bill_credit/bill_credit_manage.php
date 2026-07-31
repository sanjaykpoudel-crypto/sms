<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$txn_items = [];

if ($id) {
    $data = $db->fetchOne("
        SELECT t.*, vc.vendor_id as party_id, vc.bill_id, vc.deduct_from_stock, vc.tax_amount, vc.subtotal
        FROM transaction_headers t 
        INNER JOIN vendor_credits vc ON t.id = vc.header_id 
        WHERE t.id = ?
    ", [$id]);
    
    $txn_items = $db->fetchAll("
        SELECT tl.*, i.current_stock, i.item_name, i.sku
        FROM transaction_lines tl
        LEFT JOIN items i ON tl.item_id = i.id
        WHERE tl.header_id = ?
    ", [$id]);
} else {
    $data = [
        'txn_number'        => getNextTransactionNumber('vendor_credit'),
        'txn_date'          => date('Y-m-d'),
        'party_id'          => $_GET['vendor_id'] ?? '',
        'bill_id'           => $_GET['bill_id'] ?? '',
        'location_id'       => get_user_default_location_id(),
        'deduct_from_stock' => 1,
        'net_amount'        => 0,
        'status'            => 'open',
        'memo'              => ''
    ];
}

$all_items   = $db->fetchAll("SELECT id, item_name, sku, cost_price, selling_price FROM items WHERE is_active = 1 AND is_deleted = 0 ORDER BY item_name ASC");
$all_vendors = $db->fetchAll("
    SELECT v.id, v.company_name, v.phone, v.email, v.pan_number, v.payment_terms_days,
           COALESCE(SUM(vb.balance_due), 0) as current_balance
    FROM vendors v
    LEFT JOIN vendor_bills vb ON vb.vendor_id = v.id
    LEFT JOIN transaction_headers h ON vb.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('voided', 'draft')
    WHERE v.is_active = 1 AND v.is_deleted = 0
    GROUP BY v.id
    ORDER BY v.company_name ASC
");

// Fetch ALL vendor bills (open, paid, closed, partial) for reference dropdown
$all_bills = $db->fetchAll("
    SELECT h.id, h.txn_number, h.txn_date, h.net_amount, h.status, vb.vendor_id
    FROM transaction_headers h
    JOIN vendor_bills vb ON h.id = vb.header_id
    WHERE h.is_deleted = 0 AND h.txn_type = 'vendor_bill' AND h.status NOT IN ('voided', 'draft')
    ORDER BY h.txn_date DESC
");

// Fetch bill lines mapping for auto-population when vendor bill is selected
$bill_lines_by_header = [];
$raw_bill_lines = $db->fetchAll("
    SELECT tl.header_id, tl.item_id, tl.quantity, tl.unit_price, tl.cost_price, tl.tax_rate, tl.unit,
           i.item_name, i.current_stock
    FROM transaction_lines tl
    JOIN transaction_headers h ON tl.header_id = h.id
    JOIN vendor_bills vb ON h.id = vb.header_id
    LEFT JOIN items i ON tl.item_id = i.id
    WHERE h.is_deleted = 0 AND h.txn_type = 'vendor_bill' AND h.status NOT IN ('voided', 'draft')
    ORDER BY tl.line_number ASC
");

foreach ($raw_bill_lines as $rl) {
    $bill_lines_by_header[$rl['header_id']][] = [
        'item_id'       => $rl['item_id'],
        'item_name'     => $rl['item_name'],
        'quantity'      => (float)$rl['quantity'],
        'unit_price'    => (float)$rl['unit_price'],
        'tax_rate'      => (float)$rl['tax_rate'],
        'unit'          => $rl['unit'] ?: 'pcs',
        'current_stock' => (float)($rl['current_stock'] ?? 0),
        'cost_price'    => (float)($rl['cost_price'] ?? $rl['unit_price'])
    ];
}
?>
<div class="ns-form-header">
    <div class="ns-form-title">
        <i class="fas fa-file-invoice-dollar" style="margin-right: 10px; color: #e11d48;"></i>
        <?php echo $id ? 'Edit' : 'New'; ?> Vendor Credit
    </div>
    <div class="ns-page-actions">
        <button type="submit" form="vendor-credit-form" class="ns-btn ns-btn-primary">
            <i class="fas fa-save"></i> Save
        </button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/bill_credit')">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        <?php endif; ?>
        <a href="?page=transactions/bill_credit" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="vendor-credit-form" method="POST" action="api/save_vendor_credit.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="txn_type" value="vendor_credit">

        <!-- Vendor Summary Card -->
        <div id="vendor-info-box" style="display: none; margin-bottom: 16px; padding: 12px 16px; background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #e11d48; border-radius: 6px; font-size: 13px; color: #334155;">
            <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                <span style="font-weight: 700; color: var(--ns-primary); font-size: 13px;">
                    <i class="fas fa-user-tie" style="margin-right: 6px; color: #e11d48;"></i><span id="vendor-name-display">-</span>
                </span>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-phone-alt" style="color: #0284c7;"></i>
                    <span style="color: #64748b;">Phone:</span>
                    <span id="vendor-phone-display" style="font-weight: 600; color: #0369a1;">-</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-file-invoice" style="color: #d97706;"></i>
                    <span style="color: #64748b;">VAT/PAN #:</span>
                    <span id="vendor-vat-display" style="font-weight: 700; background: #ffffff; padding: 2px 8px; border-radius: 4px; border: 1px solid #cbd5e1;">-</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-wallet" style="color: #e11d48;"></i>
                    <span style="color: #64748b;">Current AP Balance:</span>
                    <span id="vendor-balance-display" style="font-weight: 700; color: #be123c;">-</span>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Vendor <span class="ns-required">*</span></label>
                    <select name="party_id" id="party-id-select" class="ns-select" onchange="updateVendorInfo(this)" required>
                        <option value="">-- Select Vendor --</option>
                        <?php foreach ($all_vendors as $v): ?>
                            <option value="<?php echo $v['id']; ?>"
                                    data-phone="<?php echo htmlspecialchars($v['phone'] ?? ''); ?>"
                                    data-vat="<?php echo htmlspecialchars($v['pan_number'] ?? ''); ?>"
                                    data-balance="<?php echo (float)($v['current_balance'] ?? 0); ?>"
                                    <?php echo ($data['party_id'] ?? '') == $v['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($v['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ns-form-group">
                    <label class="ns-label">Vendor Credit #</label>
                    <input type="text" name="txn_number" class="ns-input" value="<?php echo htmlspecialchars($data['txn_number']); ?>" readonly style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);">
                </div>

                <div class="ns-form-group">
                    <label class="ns-label">Ref. Original Vendor Bill (Optional)</label>
                    <select name="bill_id" id="bill-id-select" class="ns-select" onchange="onBillSelected(this)">
                        <option value="">-- None / Standalone Vendor Credit --</option>
                        <?php foreach ($all_bills as $b): ?>
                            <option value="<?php echo $b['id']; ?>" data-vendor="<?php echo $b['vendor_id']; ?>" <?php echo ($data['bill_id'] ?? '') == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['txn_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Credit Date <span class="ns-required">*</span></label>
                    <input type="date" name="txn_date" class="ns-input" value="<?php echo $data['txn_date']; ?>" required>
                </div>

                <div class="ns-form-group">
                    <label class="ns-label">Location</label>
                    <select name="location_id" class="ns-select">
                        <option value="">-- Select Location --</option>
                        <?php foreach (get_active_locations() as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['id']); ?>" <?php echo ($data['location_id'] == $loc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['name']); ?><?php echo !empty($loc['is_default']) ? ' (Default)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ns-form-group" style="margin-top: 10px;">
                    <label class="ns-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; color: #1e293b;">
                        <input type="checkbox" name="deduct_from_stock" value="1" <?php echo ($data['deduct_from_stock'] ?? 1) ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: #e11d48;">
                        <span><i class="fas fa-boxes" style="color: #e11d48; margin-right: 4px;"></i> Deduct Returned Items from Inventory Stock</span>
                    </label>
                    <span style="font-size: 11.5px; color: #64748b; margin-left: 26px; display: block;">If checked, returning items to vendor will reduce physical inventory stock levels.</span>
                </div>

                <div class="ns-form-group">
                    <label class="ns-label">Reason / Remarks</label>
                    <input type="text" name="memo" class="ns-input" value="<?php echo htmlspecialchars($data['memo'] ?? ''); ?>" placeholder="Enter reason for vendor credit...">
                </div>
            </div>
        </div>

        <div class="ns-section-title">Credit Line Items</div>
        <div style="overflow-x: auto;">
            <table class="ns-item-table" id="vc-items-table">
                <thead>
                    <tr>
                        <th width="36" style="text-align: center;">#</th>
                        <th width="240">Item Name <span class="ns-required">*</span></th>
                        <th width="85" style="text-align: right;">Stock</th>
                        <th width="90" style="text-align: right;">Cost</th>
                        <th width="95" style="text-align: right;">Return Qty <span class="ns-required">*</span></th>
                        <th width="80" style="text-align: center;">Unit</th>
                        <th width="120" style="text-align: right;">Credit Rate <span class="ns-required">*</span></th>
                        <th width="125" style="text-align: right;">Subtotal</th>
                        <th width="85" style="text-align: right;">Tax %</th>
                        <th width="110" style="text-align: right;">Tax Amt</th>
                        <th width="135" style="text-align: right; color: #e11d48;">Gross Credit</th>
                        <th width="50" style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = empty($txn_items) ? [null] : $txn_items;
                    $init_subtotal = 0;
                    $init_tax_total = 0;
                    foreach ($rows as $idx => $ti):
                        $isNew    = ($ti === null);
                        $qty      = $isNew ? 1 : (float)$ti['quantity'];
                        $rate     = $isNew ? '0.00' : number_format((float)$ti['unit_price'], 2, '.', '');
                        $subtotal = $isNew ? '0.00' : number_format((float)($ti['line_total'] - $ti['tax_amount']), 2, '.', '');
                        $taxPct   = $isNew ? 0 : (float)$ti['tax_rate'];
                        $taxAmt   = $isNew ? '0.00' : number_format((float)$ti['tax_amount'], 2, '.', '');
                        $grossAmt = $isNew ? '0.00' : number_format((float)$ti['line_total'], 2, '.', '');
                        $selItem  = $isNew ? '' : $ti['item_id'];
                        $cost     = $isNew ? '0.00' : number_format((float)($ti['cost_price'] ?? 0), 2, '.', '');

                        if (!$isNew) {
                            $init_subtotal += (float)($ti['line_total'] - $ti['tax_amount']);
                            $init_tax_total += (float)$ti['tax_amount'];
                        }
                    ?>
                        <tr>
                            <td style="text-align: center; vertical-align: middle;"><?php echo $idx + 1; ?></td>
                            <td>
                                <select name="item_id[]" class="ns-select" onchange="vcFetchItem(this)" required>
                                    <option value="">Select item...</option>
                                    <?php foreach ($all_items as $i): ?>
                                        <option value="<?php echo $i['id']; ?>" data-cost="<?php echo (float)$i['cost_price']; ?>" data-price="<?php echo (float)$i['cost_price']; ?>" <?php echo $i['id'] == $selItem ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($i['item_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" class="ns-input stock-input ns-input-num ns-input-stock" value="<?php echo $isNew ? '' : ($ti['current_stock'] ?? ''); ?>" readonly tabindex="-1"></td>
                            <td><input type="text" class="ns-input cost-input ns-input-num" style="background: #f8fafc; color: #64748b;" value="<?php echo $cost; ?>" readonly tabindex="-1"></td>
                            <td>
                                <input type="number" name="qty[]" class="ns-input qty-input ns-input-num" value="<?php echo $qty; ?>" min="0.01" step="any" onfocus="this.select()" oninput="vcCalcRow(this)" required>
                            </td>
                            <td><input type="text" name="unit[]" class="ns-input unit-input" style="text-align: center;" value="<?php echo htmlspecialchars($ti['unit'] ?? 'pcs'); ?>" readonly tabindex="-1"></td>
                            <td>
                                <input type="number" name="rate[]" class="ns-input rate-input ns-input-num" value="<?php echo $rate; ?>" min="0" step="any" onfocus="this.select()" oninput="vcCalcRow(this)" required>
                            </td>
                            <td><input type="number" name="amount[]" class="ns-input amount-input ns-input-num" value="<?php echo $subtotal; ?>" readonly tabindex="-1"></td>
                            <td><input type="number" name="tax_pct[]" class="ns-input tax-pct-input ns-input-num" value="<?php echo $taxPct; ?>" min="0" step="any" onfocus="this.select()" oninput="vcCalcRow(this)"></td>
                            <td><input type="number" name="tax_amt[]" class="ns-input tax-amt-input ns-input-num" value="<?php echo $taxAmt; ?>" readonly tabindex="-1"></td>
                            <td><input type="number" name="gross_amount[]" class="ns-input gross-amount-input ns-input-num ns-input-gross" value="<?php echo $grossAmt; ?>" readonly tabindex="-1"></td>
                            <td style="text-align: center;">
                                <span class="ns-line-btn ns-remove-line" onclick="nsRemoveLine(this)" title="Remove Line"><i class="fas fa-trash-alt"></i></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ns-grid-actions">
            <button type="button" class="ns-btn" onclick="nsAddLine('vc-items-table')"><i class="fas fa-plus-circle"></i> Add Line</button>
            <button type="button" class="ns-btn" onclick="nsClearLines('vc-items-table')" style="color: var(--ns-danger);"><i class="fas fa-eraser"></i> Clear All</button>
        </div>

        <?php
        $init_grand = max(0, $init_subtotal + $init_tax_total);
        ?>
        <div class="ns-total-box">
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Subtotal (Excl. Tax)</span>
                <span id="vc-subtotal"><?php echo number_format($init_subtotal, 2, '.', ''); ?></span>
            </div>
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Tax Total</span>
                <span id="vc-tax-total"><?php echo number_format($init_tax_total, 2, '.', ''); ?></span>
            </div>
            <div class="ns-total-row" style="border-top: 2px solid #e11d48; margin-top: 8px; padding-top: 8px;">
                <span style="color: #e11d48; font-weight: bold; font-size: 14px;">TOTAL VENDOR CREDIT</span>
                <span id="vc-grand-total" style="font-size: 22px; color: #e11d48; font-weight: bold;"><?php echo number_format($init_grand, 2, '.', ''); ?></span>
            </div>
        </div>
        <div style="clear: both; margin-bottom: 40px;"></div>
    </form>
</div>

<script>
    window.billLinesMap = <?php echo json_encode($bill_lines_by_header); ?>;
    const isEditMode = <?php echo $id ? 'true' : 'false'; ?>;

    function updateVendorInfo(select) {
        const box = document.getElementById('vendor-info-box');
        const opt = select.options[select.selectedIndex];

        if (select.value && opt) {
            document.getElementById('vendor-name-display').innerText = opt.text.trim();
            document.getElementById('vendor-phone-display').innerText = opt.dataset.phone || '-';
            document.getElementById('vendor-vat-display').innerText = opt.dataset.vat || '-';
            
            const bal = parseFloat(opt.dataset.balance || 0);
            document.getElementById('vendor-balance-display').innerText = 'Rs. ' + bal.toLocaleString('en-US', {minimumFractionDigits: 2});
            box.style.display = 'block';
        } else {
            box.style.display = 'none';
        }

        // Filter Ref Vendor Bill dropdown to only show bills for selected vendor
        filterBillsByVendor(select.value);
    }

    function filterBillsByVendor(vendorId) {
        const billSelect = document.getElementById('bill-id-select');
        if (!billSelect) return;

        let hasSelectedValid = false;

        Array.from(billSelect.options).forEach(opt => {
            if (!opt.value) {
                opt.hidden = false;
                opt.disabled = false;
                return;
            }

            const optVendor = opt.dataset.vendor;
            if (!vendorId || optVendor === vendorId) {
                opt.hidden = false;
                opt.disabled = false;
                if (billSelect.value === opt.value) {
                    hasSelectedValid = true;
                }
            } else {
                opt.hidden = true;
                opt.disabled = true;
            }
        });

        if (!hasSelectedValid && !isEditMode) {
            billSelect.value = '';
        }
    }

    // Auto-populate ALL line items with exact quantity and billed rate from selected vendor bill
    function onBillSelected(select) {
        const headerId = select.value;
        if (!headerId) return;

        const lines = window.billLinesMap ? window.billLinesMap[headerId] : null;
        if (!lines || lines.length === 0) return;

        const tbody = document.querySelector('#vc-items-table tbody');
        if (!tbody) return;

        // Clear existing rows
        tbody.innerHTML = '';

        lines.forEach((line, index) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="text-align: center; vertical-align: middle;">${index + 1}</td>
                <td>
                    <select name="item_id[]" class="ns-select" onchange="vcFetchItem(this)" required>
                        <option value="">Select item...</option>
                        <?php foreach ($all_items as $i): ?>
                            <option value="<?php echo $i['id']; ?>" data-cost="<?php echo (float)$i['cost_price']; ?>" data-price="<?php echo (float)$i['cost_price']; ?>"><?php echo htmlspecialchars($i['item_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" class="ns-input stock-input ns-input-num ns-input-stock" value="${line.current_stock}" readonly tabindex="-1"></td>
                <td><input type="text" class="ns-input cost-input ns-input-num" style="background: #f8fafc; color: #64748b;" value="${line.cost_price.toFixed(2)}" readonly tabindex="-1"></td>
                <td><input type="number" name="qty[]" class="ns-input qty-input ns-input-num" value="${line.quantity}" min="0.01" step="any" onfocus="this.select()" oninput="vcCalcRow(this)" required></td>
                <td><input type="text" name="unit[]" class="ns-input unit-input" style="text-align: center;" value="${escapeHtml(line.unit || 'pcs')}" readonly tabindex="-1"></td>
                <td><input type="number" name="rate[]" class="ns-input rate-input ns-input-num" value="${line.unit_price.toFixed(2)}" min="0" step="any" onfocus="this.select()" oninput="vcCalcRow(this)" required></td>
                <td><input type="number" name="amount[]" class="ns-input amount-input ns-input-num" value="0.00" readonly tabindex="-1"></td>
                <td><input type="number" name="tax_pct[]" class="ns-input tax-pct-input ns-input-num" value="${line.tax_rate}" min="0" step="any" onfocus="this.select()" oninput="vcCalcRow(this)"></td>
                <td><input type="number" name="tax_amt[]" class="ns-input tax-amt-input ns-input-num" value="0.00" readonly tabindex="-1"></td>
                <td><input type="number" name="gross_amount[]" class="ns-input gross-amount-input ns-input-num ns-input-gross" value="0.00" readonly tabindex="-1"></td>
                <td style="text-align: center;">
                    <span class="ns-line-btn ns-remove-line" onclick="nsRemoveLine(this)" title="Remove Line"><i class="fas fa-trash-alt"></i></span>
                </td>
            `;

            tbody.appendChild(tr);

            const itemSelect = tr.querySelector('select[name="item_id[]"]');
            if (itemSelect) {
                itemSelect.value = line.item_id;
            }

            vcCalcRow(tr.querySelector('.qty-input'));
        });

        if (typeof nsNotify === 'function') {
            nsNotify('Auto-filled ' + lines.length + ' item line(s) from vendor bill.', 'success');
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function vcFetchItem(select) {
        const tr = select.closest('tr');
        const opt = select.options[select.selectedIndex];

        if (!opt || !select.value) return;

        const rateInput = tr.querySelector('.rate-input');
        const costInput = tr.querySelector('.cost-input');

        if (rateInput && (!parseFloat(rateInput.value) || parseFloat(rateInput.value) === 0)) {
            rateInput.value = parseFloat(opt.dataset.cost || 0).toFixed(2);
        }
        if (costInput) {
            costInput.value = parseFloat(opt.dataset.cost || 0).toFixed(2);
        }

        fetch('api/transaction_handler.php?action=get_item_stock&item_id=' + select.value)
            .then(r => r.json())
            .then(data => {
                if (data && data.stock !== undefined) {
                    const stockIn = tr.querySelector('.stock-input');
                    if (stockIn) stockIn.value = data.stock;
                }
            })
            .catch(err => {});

        vcCalcRow(select);
    }

    function vcCalcRow(element) {
        const tr = element.closest('tr');
        const qty = parseFloat(tr.querySelector('.qty-input')?.value || 0);
        const rate = parseFloat(tr.querySelector('.rate-input')?.value || 0);
        const taxPct = parseFloat(tr.querySelector('.tax-pct-input')?.value || 0);

        const subtotal = round2(qty * rate);
        const taxAmt   = round2((subtotal * taxPct) / 100);
        const gross    = subtotal + taxAmt;

        if (tr.querySelector('.amount-input')) tr.querySelector('.amount-input').value = subtotal.toFixed(2);
        if (tr.querySelector('.tax-amt-input')) tr.querySelector('.tax-amt-input').value = taxAmt.toFixed(2);
        if (tr.querySelector('.gross-amount-input')) tr.querySelector('.gross-amount-input').value = gross.toFixed(2);

        vcCalcTotals();
    }

    function vcCalcTotals() {
        let subtotal = 0;
        let taxTotal = 0;

        document.querySelectorAll('#vc-items-table tbody tr').forEach(tr => {
            subtotal += parseFloat(tr.querySelector('.amount-input')?.value || 0);
            taxTotal += parseFloat(tr.querySelector('.tax-amt-input')?.value || 0);
        });

        const grand = subtotal + taxTotal;

        document.getElementById('vc-subtotal').innerText = subtotal.toFixed(2);
        document.getElementById('vc-tax-total').innerText = taxTotal.toFixed(2);
        document.getElementById('vc-grand-total').innerText = grand.toFixed(2);
    }

    function round2(val) {
        return Math.round((val + Number.EPSILON) * 100) / 100;
    }

    // Init vendor info box & bill filtering on load
    document.addEventListener('DOMContentLoaded', function() {
        const partySel = document.querySelector('select[name="party_id"]');
        if (partySel && partySel.value) {
            updateVendorInfo(partySel);
        }

        const billSel = document.getElementById('bill-id-select');
        if (billSel && billSel.value && !isEditMode) {
            onBillSelected(billSel);
        }
    });

    document.getElementById('vendor-credit-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = this;

        let hasValidItems = false;
        const rows = form.querySelectorAll('#vc-items-table tbody tr');
        rows.forEach(row => {
            const itemId = row.querySelector('select[name="item_id[]"]')?.value;
            const qty = parseFloat(row.querySelector('.qty-input')?.value || 0);
            if (itemId && qty > 0) hasValidItems = true;
        });

        if (!hasValidItems) {
            nsNotify('Please add at least one item line with quantity greater than 0.', 'error');
            return;
        }

        const submitBtn = document.querySelector('button[form="' + form.id + '"]') || form.querySelector('button[type="submit"]');
        const origText  = submitBtn ? submitBtn.innerHTML : 'Save';
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
        }

        const formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                nsNotify(data.message || 'Vendor Bill Credit saved successfully.', 'success');
                setTimeout(() => {
                    window.location.href = '?page=transactions/bill_credit';
                }, 1200);
            } else {
                nsNotify(data.message || 'Error saving Vendor Credit.', 'error');
                if (submitBtn) {
                    submitBtn.innerHTML = origText;
                    submitBtn.disabled = false;
                }
            }
        })
        .catch(err => {
            nsNotify('Network error or server error occurred.', 'error');
            if (submitBtn) {
                submitBtn.innerHTML = origText;
                submitBtn.disabled = false;
            }
        });
    });
</script>
