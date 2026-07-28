<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

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
    SELECT i.id, i.item_name, i.sku, i.cost_price,
        COALESCE((
            SELECT SUM(CASE 
                WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
                WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
                WHEN h.txn_type = 'inventory_transfer' AND h.party_id = i.id THEN l.quantity
                WHEN h.txn_type = 'inventory_transfer' AND h.location_id = i.id THEN -l.quantity
                ELSE 0 
            END)
            FROM transaction_lines l
            JOIN transaction_headers h ON l.header_id = h.id
            WHERE l.item_id = i.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ), 0) as current_stock
    FROM items i 
    WHERE i.is_active = 1 AND i.is_deleted = 0 
    ORDER BY i.item_name ASC
");

$locations = get_active_locations();
?>

<style>
.ns-form-header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; margin-bottom: 20px; }
.ns-form-title { font-size: 18px; font-weight: 700; color: #1e293b; display: flex; align-items: center; }
.ns-page-actions { display: flex; gap: 10px; }
.ns-form-container { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.ns-section-title { font-size: 14px; font-weight: 700; color: #0284c7; margin: 15px 0 12px; padding-bottom: 6px; border-bottom: 2px solid #e0f2fe; text-transform: uppercase; letter-spacing: 0.5px; }
.ns-form-row { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 15px; }
.ns-form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.ns-label { font-size: 13px; font-weight: 600; color: #475569; }
.ns-required { color: #ef4444; }
.ns-input, .ns-select { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; outline: none; transition: all 0.2s; width: 100%; box-sizing: border-box; }
.ns-input:focus, .ns-select:focus { border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2,132,199,0.15); }
.ns-btn { padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid #cbd5e1; background: #fff; color: #475569; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; text-decoration: none; }
.ns-btn:hover { background: #f8fafc; border-color: #94a3b8; }
.ns-btn-primary { background: #0284c7; color: #fff; border-color: #0284c7; }
.ns-btn-primary:hover { background: #0369a1; border-color: #0369a1; }
.ns-item-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
.ns-item-table th { background: #f8fafc; color: #475569; padding: 10px 12px; font-weight: 700; text-align: left; border: 1px solid #e2e8f0; }
.ns-item-table td { padding: 8px 12px; border: 1px solid #e2e8f0; background: #fff; }
.ns-summary-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-top: 20px; display: flex; justify-content: flex-end; gap: 30px; font-size: 14px; font-weight: 600; color: #1e293b; }
.ns-summary-item span { color: #0284c7; font-weight: 700; font-size: 16px; }
</style>

<div class="ns-form-header">
    <div class="ns-form-title">
        <i class="fas fa-boxes-packing" style="margin-right: 10px; color: #0284c7;"></i>
        <?php echo $id ? 'Edit' : 'New'; ?> Inventory Transfer
    </div>
    <div class="ns-page-actions">
        <button type="button" class="ns-btn ns-btn-primary" onclick="saveInventoryTransfer(event)">
            <i class="fas fa-save"></i> Save Transfer
        </button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #ef4444; border-color: #fca5a5; background: #fef2f2;"
                onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/inventory_transfer')">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        <?php endif; ?>
        <a href="?page=transactions/inventory_transfer" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="transfer-form" method="POST">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id ?? ''); ?>">

        <div class="ns-section-title">Transfer Locations & Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1; min-width: 250px;">
                <div class="ns-form-group">
                    <label class="ns-label">Transfer #</label>
                    <input type="text" name="txn_number" class="ns-input"
                        value="<?php echo htmlspecialchars($data['txn_number']); ?>" readonly
                        style="background: #f8fafc; font-weight: bold; color: #0284c7;">
                </div>
            </div>
            <div style="flex: 1; min-width: 250px;">
                <div class="ns-form-group">
                    <label class="ns-label">Date <span class="ns-required">*</span></label>
                    <input type="date" name="txn_date" class="ns-input" value="<?php echo htmlspecialchars($data['txn_date']); ?>" required>
                </div>
            </div>
        </div>

        <div class="ns-form-row">
            <div style="flex: 1; min-width: 280px;">
                <div class="ns-form-group">
                    <label class="ns-label">From Location (Source) <span class="ns-required">*</span></label>
                    <select name="from_location_id" id="from_location_id" class="ns-select" required onchange="validateLocations()">
                        <option value="">-- Select Source Location --</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['id']); ?>" <?php echo ($data['from_location_id'] == $loc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['name']); ?><?php echo !empty($loc['is_default']) ? ' (Default)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex: 1; min-width: 280px;">
                <div class="ns-form-group">
                    <label class="ns-label">To Location (Destination) <span class="ns-required">*</span></label>
                    <select name="to_location_id" id="to_location_id" class="ns-select" required onchange="validateLocations()">
                        <option value="">-- Select Destination Location --</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['id']); ?>" <?php echo ($data['to_location_id'] == $loc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="ns-form-group">
            <label class="ns-label">Memo / Remarks</label>
            <input type="text" name="memo" class="ns-input" value="<?php echo htmlspecialchars($data['memo'] ?? ''); ?>"
                placeholder="Enter details, vehicle number, or reason for transfer...">
        </div>

        <div class="ns-section-title">Transfer Items</div>
        <div style="overflow-x: auto;">
            <table class="ns-item-table" id="items-table">
                <thead>
                    <tr>
                        <th width="40" style="text-align: center;">#</th>
                        <th width="300">Item Name <span class="ns-required">*</span></th>
                        <th width="120" style="text-align: right;">Current Stock</th>
                        <th width="130" style="text-align: right;">Transfer Qty <span class="ns-required">*</span></th>
                        <th width="130" style="text-align: right;">Unit Cost (Rs)</th>
                        <th width="140" style="text-align: right;">Line Total (Rs)</th>
                        <th width="60" style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="items-tbody">
                    <?php if (!empty($txn_items)): ?>
                        <?php foreach ($txn_items as $idx => $line): ?>
                            <tr>
                                <td style="text-align: center; font-weight: 600;"><?php echo $idx + 1; ?></td>
                                <td>
                                    <select name="item_id[]" class="ns-select item-select" required onchange="onItemChange(this)">
                                        <option value="">Select Item...</option>
                                        <?php foreach ($all_items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>"
                                                data-cost="<?php echo $item['cost_price']; ?>"
                                                data-stock="<?php echo $item['current_stock']; ?>"
                                                <?php echo ($line['item_id'] == $item['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($item['item_name']); ?> (<?php echo htmlspecialchars($item['sku']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" class="ns-input stock-input" value="0" readonly style="text-align: right; background: #f8fafc;"></td>
                                <td><input type="number" step="any" name="quantity[]" class="ns-input qty-input" value="<?php echo $line['quantity']; ?>" required min="0.01" style="text-align: right; font-weight: 700;" oninput="calcLine(this)"></td>
                                <td><input type="number" step="any" name="unit_cost[]" class="ns-input cost-input" value="<?php echo $line['cost_price']; ?>" required style="text-align: right;" oninput="calcLine(this)"></td>
                                <td><input type="text" class="ns-input line-total" value="0.00" readonly style="text-align: right; font-weight: 700; color: #0284c7; background: #f8fafc;"></td>
                                <td style="text-align: center;">
                                    <button type="button" class="ns-btn" style="padding: 4px 8px; color: #ef4444; border-color: #fca5a5;" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 15px;">
            <button type="button" class="ns-btn" onclick="addRow()"><i class="fas fa-plus"></i> Add Item Line</button>
        </div>

        <div class="ns-summary-box">
            <div class="ns-summary-item">Total Quantity: <span id="lbl-total-qty">0.00</span></div>
            <div class="ns-summary-item">Total Transfer Value: <span id="lbl-total-val">Rs 0.00</span></div>
        </div>
    </form>
</div>

<script>
const itemsData = <?php echo json_encode($all_items); ?>;

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

function addRow() {
    const tbody = document.getElementById('items-tbody');
    const rowCount = tbody.children.length;
    const tr = document.createElement('tr');

    let optionsHtml = '<option value="">Select Item...</option>';
    itemsData.forEach(item => {
        optionsHtml += `<option value="${item.id}" data-cost="${item.cost_price}" data-stock="${item.current_stock}">${item.item_name} (${item.sku})</option>`;
    });

    tr.innerHTML = `
        <td style="text-align: center; font-weight: 600;">${rowCount + 1}</td>
        <td>
            <select name="item_id[]" class="ns-select item-select" required onchange="onItemChange(this)">
                ${optionsHtml}
            </select>
        </td>
        <td><input type="text" class="ns-input stock-input" value="0" readonly style="text-align: right; background: #f8fafc;"></td>
        <td><input type="number" step="any" name="quantity[]" class="ns-input qty-input" value="1" required min="0.01" style="text-align: right; font-weight: 700;" oninput="calcLine(this)"></td>
        <td><input type="number" step="any" name="unit_cost[]" class="ns-input cost-input" value="0.00" required style="text-align: right;" oninput="calcLine(this)"></td>
        <td><input type="text" class="ns-input line-total" value="0.00" readonly style="text-align: right; font-weight: 700; color: #0284c7; background: #f8fafc;"></td>
        <td style="text-align: center;">
            <button type="button" class="ns-btn" style="padding: 4px 8px; color: #ef4444; border-color: #fca5a5;" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
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
    const opt = select.options[select.selectedIndex];
    const tr = select.closest('tr');
    const cost = opt ? parseFloat(opt.dataset.cost || 0) : 0;
    const stock = opt ? parseFloat(opt.dataset.stock || 0) : 0;

    tr.querySelector('.cost-input').value = cost.toFixed(2);
    tr.querySelector('.stock-input').value = stock;
    calcLine(select);
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
    const tbody = document.getElementById('items-tbody');
    if (tbody.children.length === 0) {
        addRow();
    } else {
        document.querySelectorAll('#items-tbody .item-select').forEach(sel => onItemChange(sel));
    }
});
</script>
