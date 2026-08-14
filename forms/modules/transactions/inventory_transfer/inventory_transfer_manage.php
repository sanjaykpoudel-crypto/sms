<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
require_once 'api/ui_helpers.php';

$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$txn_items = [];

if ($id) {
    $data = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ? AND txn_type = 'inventory_transfer'", [$id]);
    $transfer_meta = $db->fetchOne("SELECT * FROM inventory_transfers WHERE header_id = ?", [$id]);
    if ($transfer_meta) {
        $data['from_location_id'] = $transfer_meta['from_location_id'];
        $data['to_location_id']   = $transfer_meta['to_location_id'];
    } else {
        $data['from_location_id'] = $data['location_id'] ?? '';
        $data['to_location_id']   = $data['party_id'] ?? '';
    }
    $txn_items = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$id]);
} else {
    $data = [
        'txn_number'       => getNextTransactionNumber('inventory_transfer'),
        'txn_date'         => date('Y-m-d'),
        'from_location_id' => get_user_default_location_id(),
        'to_location_id'   => '',
        'memo'             => ''
    ];
}

$all_items = $db->fetchAll("
    SELECT i.id, i.item_name, i.sku, i.cost_price, i.mrp
    FROM items i 
    WHERE i.is_active = 1 AND i.is_deleted = 0 
    ORDER BY i.item_name ASC
");
?>

<div class="ns-form-header">
    <div class="ns-form-title">
        <i class="fas fa-exchange-alt" style="margin-right: 10px; color: var(--ns-primary);"></i>
        <?php echo $id ? 'Edit' : 'New'; ?> Inventory Transfer
    </div>
    <div class="ns-page-actions">
        <button type="button" class="ns-btn ns-btn-primary" onclick="saveInventoryTransfer(event)">
            <i class="fas fa-save"></i> Save Transfer
        </button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;"
                onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/inventory_transfer')">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        <?php endif; ?>
        <a href="?page=transactions/inventory_transfer" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="transfer-form">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id ?? ''); ?>">


        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Transfer #</label>
                    <input type="text" name="txn_number" class="ns-input"
                        value="<?php echo htmlspecialchars($data['txn_number']); ?>" readonly
                        style="background: #f9f9f9; font-weight: bold; color: var(--ns-primary);">
                </div>
            </div>
            <div style="flex: 1; min-width: 300px;">
                <div class="ns-form-group">
                    <label class="ns-label">Date <span class="ns-required">*</span></label>
                    <input type="date" name="txn_date" class="ns-input"
                        value="<?php echo htmlspecialchars($data['txn_date']); ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">From Location (Source) <span class="ns-required">*</span></label>
                    <?php echo render_select_dropdown('from_location_id', 'from_location', $data['from_location_id'] ?? '', null, 'class="ns-select" required onchange="handleFromLocationChange(this)"'); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">To Location (Destination) <span class="ns-required">*</span></label>
                    <?php echo render_select_dropdown('to_location_id', 'to_location', $data['to_location_id'] ?? '', null, 'class="ns-select" required onchange="validateLocations()"'); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Memo / Remarks</label>
                    <input type="text" name="memo" class="ns-input"
                        value="<?php echo htmlspecialchars($data['memo'] ?? ''); ?>"
                        placeholder="Enter details, vehicle number, or reason for transfer...">
                </div>
            </div>
        </div>

        <div class="ns-section-title">Line Items</div>
        <div style="overflow-x: auto;">
            <table class="ns-item-table" id="transfer-items-table">
                <thead>
                    <tr>
                        <th width="40" style="text-align: center;">#</th>
                        <th>Item Name <span class="ns-required">*</span></th>
                        <th width="120" style="text-align: right;">Current Stock</th>
                        <th width="120" style="text-align: right;">Transfer Qty <span class="ns-required">*</span></th>
                        <th width="90" style="text-align: center;">Unit</th>
                        <th width="130" style="text-align: right;">Unit Cost (Rs)</th>
                        <th width="130" style="text-align: right;">MRP (Rs)</th>
                        <th width="140" style="text-align: right;">Line Total (Rs)</th>
                        <th width="55" style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="items-tbody">
                    <?php if (!empty($txn_items)): ?>
                        <?php foreach ($txn_items as $idx => $line): ?>
                            <tr>
                                <td style="text-align: center; vertical-align: middle;"><?php echo $idx + 1; ?></td>
                                <td>
                                    <select name="item_id[]" class="ns-select item-select" required onchange="onItemChange(this)">
                                        <option value="" selected disabled hidden>Select Item...</option>
                                        <?php foreach ($all_items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>"
                                                data-cost="<?php echo $item['cost_price']; ?>"
                                                data-stock="<?php echo $item['current_stock']; ?>"
                                                data-mrp="<?php echo $item['mrp'] ?? 0; ?>"
                                                <?php echo ($line['item_id'] == $item['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($item['item_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" class="ns-input stock-input ns-input-num" value="0" readonly style="text-align:right;"></td>
                                <td><input type="number" step="any" name="quantity[]" class="ns-input qty-input ns-input-num" value="<?php echo $line['quantity']; ?>" required min="0.01" style="text-align:right; font-weight:700;" oninput="calcLine(this)"></td>
                                <td><input type="number" step="any" name="unit_cost[]" class="ns-input cost-input ns-input-num" value="<?php echo $line['cost_price']; ?>" required style="text-align:right;" oninput="calcLine(this)"></td>
                                <td><input type="number" step="any" name="mrp[]" class="ns-input mrp-input ns-input-num" value="0.00" min="0" style="text-align:right; color:#0284c7; font-weight:600;" placeholder="0.00" title="MRP will update item master on save"></td>
                                <td><input type="text" class="ns-input line-total ns-input-num" value="0.00" readonly style="text-align:right; font-weight:700; color:var(--ns-primary);"></td>
                                <td style="text-align: center;">
                                    <span class="ns-line-btn ns-remove-line" onclick="removeRow(this)" title="Remove Line"><i class="fas fa-trash-alt"></i></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="ns-grid-actions">
            <button type="button" class="ns-btn" onclick="addRow()"><i class="fas fa-plus-circle"></i> Add Line</button>
        </div>

        <div class="ns-total-box">
            <div class="ns-total-row">
                <span style="color: var(--ns-text-muted);">Total Quantity</span>
                <span id="lbl-total-qty">0.00</span>
            </div>
            <div class="ns-total-row" style="font-weight: 700; font-size: 15px;">
                <span>Total Transfer Value</span>
                <span id="lbl-total-val">Rs 0.00</span>
            </div>
        </div>
    </form>
</div>


<script>
const itemsData = <?php echo json_encode($all_items); ?>;
let previousFromLocation = '';

function validateLocations() {
    const fromLoc = document.getElementById('from_location_id').value;
    const toLoc = document.getElementById('to_location_id').value;
    if (fromLoc && toLoc && fromLoc === toLoc) {
        if (typeof nsNotify === 'function') {
            nsNotify('Source and Destination location cannot be the same.', 'error');
        } else {
            alert('Source and Destination location cannot be the same.');
        }
        document.getElementById('to_location_id').value = '';
    }
}

function handleFromLocationChange(select) {
    const newFromLoc = select.value;
    if (newFromLoc === previousFromLocation) {
        return;
    }

    const rows = document.querySelectorAll('#items-tbody tr');
    let hasItems = false;
    rows.forEach(tr => {
        const itemSel = tr.querySelector('.item-select');
        if (itemSel && itemSel.value) {
            hasItems = true;
        }
    });

    if (hasItems) {
        const warningMsg = "Warning: Changing the From Location will clear all line items. All saved/added items on the lines will be lost. Do you want to proceed?";
        const performChange = (confirmed) => {
            if (confirmed) {
                previousFromLocation = newFromLoc;
                clearAllLines();
                validateLocations();
            } else {
                select.value = previousFromLocation;
            }
        };

        if (typeof nsConfirm === 'function') {
            nsConfirm(warningMsg, () => performChange(true), () => performChange(false));
        } else if (confirm(warningMsg)) {
            performChange(true);
        } else {
            performChange(false);
        }
    } else {
        previousFromLocation = newFromLoc;
        validateLocations();
        document.querySelectorAll('#items-tbody .item-select').forEach(sel => {
            if (sel.value) onItemChange(sel);
        });
    }
}

function clearAllLines() {
    const tbody = document.getElementById('items-tbody');
    tbody.innerHTML = '';
    addRow();
    calcTotals();
}

function addRow() {
    const tbody = document.getElementById('items-tbody');
    const rowCount = tbody.children.length;
    const tr = document.createElement('tr');

    let optionsHtml = '<option value="" selected disabled hidden>Select Item...</option>';
    itemsData.forEach(item => {
        optionsHtml += `<option value="${item.id}" data-cost="${item.cost_price}" data-stock="${item.current_stock}" data-mrp="${item.mrp || 0}">${item.item_name}</option>`;
    });

    tr.innerHTML = `
        <td style="text-align: center; vertical-align: middle;">${rowCount + 1}</td>
        <td>
            <select name="item_id[]" class="ns-select item-select" required onchange="onItemChange(this)">
                ${optionsHtml}
            </select>
        </td>
        <td><input type="text" class="ns-input stock-input ns-input-num" value="0.00" readonly style="text-align:right;"></td>
        <td><input type="number" step="any" name="quantity[]" class="ns-input qty-input ns-input-num" value="1" required min="0.01" style="text-align:right; font-weight:700;" oninput="calcLine(this)"></td>
        <td><input type="text" name="unit[]" class="ns-input unit-input" style="text-align: center;" value="PCS" readonly tabindex="-1"></td>
        <td><input type="number" step="any" name="unit_cost[]" class="ns-input cost-input ns-input-num" value="0.00" required style="text-align:right;" oninput="calcLine(this)"></td>
        <td><input type="number" step="any" name="mrp[]" class="ns-input mrp-input ns-input-num" value="0.00" min="0" style="text-align:right; color:#0284c7; font-weight:600;" placeholder="0.00" title="MRP will update item master on save"></td>
        <td><input type="text" class="ns-input line-total ns-input-num" value="0.00" readonly style="text-align:right; font-weight:700; color:var(--ns-primary);"></td>
        <td style="text-align: center;">
            <span class="ns-line-btn ns-remove-line" onclick="removeRow(this)" title="Remove Line"><i class="fas fa-trash-alt"></i></span>
        </td>
    `;
    tbody.appendChild(tr);
    updateRowNumbers();
}

function removeRow(btn) {
    const tbody = document.getElementById('items-tbody');
    if (tbody.children.length <= 1) {
        if (typeof nsNotify === 'function') nsNotify('Transfer must have at least one item line.', 'error');
        return;
    }
    btn.closest('tr').remove();
    updateRowNumbers();
    calcTotals();
}

function updateRowNumbers() {
    const rows = document.querySelectorAll('#items-tbody tr');
    rows.forEach((row, idx) => {
        row.cells[0].textContent = idx + 1;
    });
}

function onItemChange(select) {
    const tr = select.closest('tr');
    if (!tr) return;
    const itemId = select.value;
    const fromLocId = document.getElementById('from_location_id')?.value || '';

    if (!itemId) {
        tr.querySelector('.stock-input').value = '0.00';
        tr.querySelector('.cost-input').value = '0.00';
        calcLine(select);
        return;
    }

    fetch('api/get_item_details.php?id=' + encodeURIComponent(itemId) + '&location_id=' + encodeURIComponent(fromLocId))
        .then(r => r.json())
        .then(data => {
            if (data.error) return;
            tr.dataset.itemData = JSON.stringify(data);
            
            const cost = parseFloat(data.cost_price || 0);
            const mrp  = parseFloat(data.mrp || 0);
            tr.querySelector('.cost-input').value = cost.toFixed(2);
            tr.querySelector('.mrp-input').value = mrp.toFixed(2);

            const unitTd = tr.querySelector('.unit-input').closest('td');
            const conv = parseInt(data.units_per_case || 1);
            const baseUnit = data.unit_name || data.unit_type || 'PCS';
            const caseUnit = data.case_unit_name || 'CASE';

            if (conv > 1) {
                unitTd.innerHTML = `
                    <select name="unit[]" class="ns-select unit-input" style="padding: 2px 4px; font-size: 11px; font-weight: 700; color: #0369a1; background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 4px;" onchange="transferUnitChanged(this)">
                        <option value="${baseUnit}">${baseUnit}</option>
                        <option value="${caseUnit}">${caseUnit} (${conv} PCS)</option>
                    </select>
                `;
            } else {
                unitTd.innerHTML = `<input type="text" name="unit[]" class="ns-input unit-input" style="text-align: center;" value="${baseUnit}" readonly tabindex="-1">`;
            }

            updateTransferStockDisplay(tr);
            calcLine(select);
        });
}

function transferUnitChanged(select) {
    const tr = select.closest('tr');
    if (!tr || !tr.dataset.itemData) return;
    const data = JSON.parse(tr.dataset.itemData);
    const selectedUnit = select.value;
    const conv = parseInt(data.units_per_case || 1);
    const caseUnit = data.case_unit_name || 'CASE';

    let cost = parseFloat(data.cost_price || 0);
    if (selectedUnit === caseUnit || selectedUnit === 'CASE') {
        const casePrice = parseFloat(data.case_purchase_price || 0);
        cost = casePrice > 0 ? casePrice : Math.round(cost * conv * 100) / 100;
    }

    tr.querySelector('.cost-input').value = cost.toFixed(2);
    updateTransferStockDisplay(tr);
    calcLine(select);
}

function updateTransferStockDisplay(tr) {
    if (!tr.dataset.itemData) return;
    const data = JSON.parse(tr.dataset.itemData);
    const unitEl = tr.querySelector('.unit-input');
    const selectedUnit = unitEl ? unitEl.value : '';
    const conv = parseInt(data.units_per_case || 1);
    const caseUnit = data.case_unit_name || 'CASE';
    const isCase = (selectedUnit === caseUnit || selectedUnit === 'CASE' || selectedUnit === 'BOX');

    const baseStock = parseFloat(data.location_stock !== undefined ? data.location_stock : (data.current_stock || 0));

    if (isCase && conv > 1) {
        tr.querySelector('.stock-input').value = (baseStock / conv).toFixed(2);
    } else {
        tr.querySelector('.stock-input').value = baseStock.toFixed(2);
    }
}

function calcLine(element) {
    const tr = element.closest('tr');
    const qty = parseFloat(tr.querySelector('.qty-input').value || 0);
    const cost = parseFloat(tr.querySelector('.cost-input').value || 0);
    const total = qty * cost;
    tr.querySelector('.line-total').value = total.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    calcTotals();
}

function calcTotals() {
    let totQty = 0;
    let totVal = 0;
    document.querySelectorAll('#items-tbody tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.qty-input').value || 0);
        const cost = parseFloat(tr.querySelector('.cost-input').value || 0);
        totQty += qty;
        totVal += (qty * cost);
    });
    document.getElementById('lbl-total-qty').textContent = totQty.toFixed(2);
    document.getElementById('lbl-total-val').textContent = 'Rs ' + totVal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function saveInventoryTransfer(event) {
    const form = document.getElementById('transfer-form');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const btn = event.target;
    const origText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;

    const formData = new FormData(form);
    fetch('api/save_inventory_transfer.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            if (typeof nsNotify === 'function') nsNotify(res.message, 'success');
            setTimeout(() => {
                window.location.href = '?page=transactions/inventory_transfer/view&id=' + res.id;
            }, 800);
        } else {
            if (typeof nsNotify === 'function') nsNotify(res.message || 'Failed to save inventory transfer.', 'error');
            else alert(res.message);
            btn.innerHTML = origText;
            btn.disabled = false;
        }
    })
    .catch(err => {
        console.error(err);
        if (typeof nsNotify === 'function') nsNotify('Server error occurred.', 'error');
        btn.innerHTML = origText;
        btn.disabled = false;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const fromLocSelect = document.getElementById('from_location_id');
    if (fromLocSelect) {
        previousFromLocation = fromLocSelect.value;
    }

    const tbody = document.getElementById('items-tbody');
    if (tbody.children.length === 0) {
        addRow();
    } else {
        document.querySelectorAll('#items-tbody .item-select').forEach(sel => onItemChange(sel));
    }
});
</script>
