<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
if ($id) {
    $data = $db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);
}
$accounts = $db->fetchAll("SELECT id, account_name, account_code, account_subtype FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Item</div>
    <div class="ns-page-actions">
        <button type="submit" form="item-form" class="ns-btn ns-btn-primary"><?php echo $id ? 'Edit' : 'Save'; ?></button>
        <button type="button" onclick="history.back()" class="ns-btn">Cancel</button>
    </div>
</div>

<div class="ns-form-container">
    <form id="item-form" method="POST" action="api/save_item.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        
        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Item Name <span class="ns-required">*</span></label>
                    <input type="text" name="item_name" class="ns-input" value="<?php echo htmlspecialchars($data['item_name'] ?? ''); ?>" required>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Category</label>
                    <div style="display: flex; gap: 8px;">
                        <select name="item_category" id="item-category-select" class="ns-select" style="flex: 1;">
                            <option value="">Select Category</option>
                            <?php 
                            $categories = $db->fetchAll("SELECT id, name, code FROM reference_codes WHERE type = 'category' AND is_active = 1 ORDER BY name ASC");
                            foreach($categories as $cat): 
                            ?>
                                <option value="<?php echo htmlspecialchars($cat['id']); ?>" <?php echo ($data['item_category'] ?? '') == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="ns-btn ns-btn-outline" onclick="openQuickAdd('category')" title="Add New Category" style="padding: 0 12px; height: 38px;">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Brand</label>
                    <input type="text" name="brand" class="ns-input" value="<?php echo htmlspecialchars($data['brand'] ?? ''); ?>" placeholder="e.g. Johnnie Walker, Carlsberg">
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Unit Type</label>
                    <div style="display: flex; gap: 8px;">
                        <select name="unit_type" id="item-unit-select" class="ns-select" style="flex: 1;">
                            <option value="">Select Unit</option>
                            <?php 
                            $units = $db->fetchAll("SELECT id, name, code FROM reference_codes WHERE type IN ('unit', 'units') AND is_active = 1 ORDER BY name ASC");
                            foreach($units as $u): 
                            ?>
                                <option value="<?php echo htmlspecialchars($u['id']); ?>" <?php echo ($data['unit_type'] ?? '') == $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="ns-btn ns-btn-outline" onclick="openQuickAdd('units')" title="Add New Unit" style="padding: 0 12px; height: 38px;">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div style="display: flex; gap: 15px;">
                    <div class="ns-form-group" style="flex: 1;">
                        <label class="ns-label">Bottle Size (ML)</label>
                        <input type="number" step="0.01" name="bottle_size_ml" class="ns-input" value="<?php echo $data['bottle_size_ml'] ?? ''; ?>" placeholder="e.g. 750">
                    </div>
                    <div class="ns-form-group" style="flex: 1;">
                        <label class="ns-label">Units per Case</label>
                        <input type="number" name="units_per_case" class="ns-input" value="<?php echo $data['units_per_case'] ?? ''; ?>" placeholder="e.g. 12">
                    </div>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Barcode / UPC</label>
                    <input type="text" name="barcode" class="ns-input" value="<?php echo htmlspecialchars($data['barcode'] ?? ''); ?>" placeholder="Scan or enter barcode">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Status</label>
                    <select name="is_active" class="ns-select">
                        <option value="1" <?php echo ($data['is_active'] ?? 1) ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo (isset($data['is_active']) && $data['is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Pricing & Inventory Control</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Cost Price <span class="ns-required">*</span></label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 10px; top: 10px; color: #999;">Rs</span>
                        <input type="number" step="0.01" name="cost_price" class="ns-input" style="padding-left: 35px;" value="<?php echo $data['cost_price'] ?? '0.00'; ?>" required>
                    </div>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Selling Price <span class="ns-required">*</span></label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 10px; top: 10px; color: #999;">Rs</span>
                        <input type="number" step="0.01" name="selling_price" class="ns-input" style="padding-left: 35px;" value="<?php echo $data['selling_price'] ?? '0.00'; ?>" required>
                    </div>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Tax Code <span class="ns-required">*</span></label>
                    <div style="display: flex; gap: 8px;">
                        <select name="tax_id" id="item-tax-select" class="ns-select" style="flex: 1;" onchange="syncTaxRate(this)" required>
                            <option value="">Select Tax Code</option>
                            <?php 
                            $tax_codes = $db->fetchAll("SELECT id, name, value FROM reference_codes WHERE type = 'tax_code' AND is_active = 1 ORDER BY value ASC");
                            foreach($tax_codes as $tc): 
                            ?>
                                <option value="<?php echo htmlspecialchars($tc['id']); ?>" data-rate="<?php echo $tc['value']; ?>" <?php echo ($data['tax_id'] ?? '') == $tc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="ns-btn ns-btn-outline" onclick="openQuickAdd('tax_code')" title="Add New Tax Code" style="padding: 0 12px; height: 38px;">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <input type="hidden" name="tax_rate" id="item-tax-rate-hidden" value="<?php echo $data['tax_rate'] ?? '0.00'; ?>">
                </div>
            </div>
            <div style="flex: 1;">
                <div style="display: flex; gap: 15px;">
                    <div class="ns-form-group" style="flex: 1;">
                        <label class="ns-label">Reorder Level</label>
                        <input type="number" name="reorder_level" class="ns-input" value="<?php echo $data['reorder_level'] ?? '10'; ?>">
                    </div>
                    <div class="ns-form-group" style="flex: 1;">
                        <label class="ns-label">Reorder Quantity</label>
                        <input type="number" name="reorder_qty" class="ns-input" value="<?php echo $data['reorder_qty'] ?? ''; ?>" placeholder="Default restock amount">
                    </div>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Description / Notes</label>
                    <textarea name="description" class="ns-input" rows="4" style="height: 105px;"><?php echo htmlspecialchars($data['description'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Accounting Configuration</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Inventory Account <span class="ns-required">*</span></label>
                    <select name="inventory_account_id" class="ns-select" required>
                        <option value="">Select Account</option>
                        <?php foreach($accounts as $acc): if(in_array($acc['account_subtype'], ['inventory'])): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($data['inventory_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['account_name']); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">COGS Account <span class="ns-required">*</span></label>
                    <select name="cogs_account_id" class="ns-select" required>
                        <option value="">Select Account</option>
                        <?php foreach($accounts as $acc): if(in_array($acc['account_subtype'], ['cogs', 'expense'])): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($data['cogs_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['account_name']); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Income Account <span class="ns-required">*</span></label>
                    <select name="income_account_id" class="ns-select" required>
                        <option value="">Select Account</option>
                        <?php foreach($accounts as $acc): if(in_array($acc['account_subtype'], ['sales', 'income', 'other'])): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($data['income_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['account_name']); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Quick Add Modal -->
<div id="quick-add-modal" class="ns-modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
    <div style="background:#fff; width:400px; margin: 100px auto; border-radius:8px; overflow:hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        <div style="padding:15px 20px; background:#f8f9fa; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
            <h4 id="modal-title" style="margin:0; font-size:16px;">Quick Add</h4>
            <span onclick="closeQuickAdd()" style="cursor:pointer; font-size:20px;">&times;</span>
        </div>
        <div style="padding:20px;">
            <input type="hidden" id="quick-add-type">
            <div class="ns-form-group">
                <label class="ns-label">Name / Label</label>
                <input type="text" id="quick-add-name" class="ns-input" placeholder="Enter name...">
            </div>
            <div class="ns-form-group">
                <label class="ns-label">Code (Optional)</label>
                <input type="text" id="quick-add-code" class="ns-input" placeholder="e.g. CAT, BTL">
            </div>
            <div class="ns-form-group" id="quick-add-value-group" style="display:none;">
                <label class="ns-label">Rate (%)</label>
                <input type="number" step="0.01" id="quick-add-value" class="ns-input" placeholder="e.g. 13">
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="ns-btn" onclick="closeQuickAdd()">Cancel</button>
                <button type="button" class="ns-btn ns-btn-primary" onclick="saveQuickReference()">Add Entry</button>
            </div>
        </div>
    </div>
</div>

<script>
    function syncTaxRate(select) {
        const rate = select.options[select.selectedIndex]?.dataset?.rate ?? '0';
        document.getElementById('item-tax-rate-hidden').value = rate;
    }

    // Init on load
    document.addEventListener('DOMContentLoaded', function() {
        const taxSelect = document.getElementById('item-tax-select');
        if (taxSelect) syncTaxRate(taxSelect);
    });

    function openQuickAdd(type) {
        document.getElementById('quick-add-type').value = type;
        const labels = { category: 'Category', units: 'Unit', tax_code: 'Tax Code' };
        document.getElementById('modal-title').innerText = 'Add New ' + (labels[type] || type);
        document.getElementById('quick-add-name').value = '';
        document.getElementById('quick-add-code').value = '';
        document.getElementById('quick-add-value').value = '';
        // Show rate field only for tax_code
        document.getElementById('quick-add-value-group').style.display = (type === 'tax_code') ? 'block' : 'none';
        document.getElementById('quick-add-modal').style.display = 'block';
        document.getElementById('quick-add-name').focus();
    }

    function closeQuickAdd() {
        document.getElementById('quick-add-modal').style.display = 'none';
    }

    function saveQuickReference() {
        const type = document.getElementById('quick-add-type').value;
        const name = document.getElementById('quick-add-name').value;
        let code = document.getElementById('quick-add-code').value;
        
        if (!name) {
            nsNotify('Name is required', 'error');
            return;
        }
        
        if (!code) {
            code = name.substring(0, 3).toUpperCase();
        }

        const payload = new FormData();
        payload.append('type', type);
        payload.append('name', name);
        payload.append('override_code', code);
        payload.append('is_active', 1);
        if (type === 'tax_code') {
            payload.append('value', document.getElementById('quick-add-value').value || 0);
        }

        fetch('api/setup_manage.php', {
            method: 'POST',
            body: payload
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                nsNotify('Added successfully');
                
                // Refresh the correct dropdown
                let selectId = 'item-category-select';
                if (type === 'units') selectId = 'item-unit-select';
                if (type === 'tax_code') selectId = 'item-tax-select';
                const select = document.getElementById(selectId);
                const option = document.createElement('option');
                option.value = data.id;
                option.text = name;
                option.selected = true;
                if (type === 'tax_code') {
                    option.dataset.rate = document.getElementById('quick-add-value').value || '0';
                    syncTaxRate(select);
                }
                select.add(option);
                
                closeQuickAdd();
            } else {
                nsNotify(data.message || 'Error adding entry', 'error');
            }
        })
        .catch(err => {
            nsNotify('Network error', 'error');
        });
    }

    document.getElementById('item-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const submitBtn = document.querySelector('button[form="item-form"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;

        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            data[key] = value;
        });

        const payload = {
            action: data.id ? 'update' : 'save',
            table: 'items',
            primary_key: 'id',
            primary_value: data.id || null,
            data: data
        };

        fetch('api/transaction_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                nsNotify(data.message);
                setTimeout(() => {
                    window.location.href = '?page=master/item/view&id=' + data.id;
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
