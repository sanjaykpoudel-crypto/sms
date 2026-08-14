<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
if ($id) {
    $data = $db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);
}
$accounts = $db->fetchAll("SELECT id, account_name, account_subtype FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
$locations = $db->fetchAll("SELECT * FROM locations WHERE is_active = 1 AND is_deleted = 0 ORDER BY name ASC");
$loc_prices = [];
$audit_logs = [];
if ($id) {
    $rows = $db->fetchAll("SELECT location_id, cost_price, selling_price, mrp FROM inventory_balances WHERE item_id = ?", [$id]);
    foreach ($rows as $r) {
        $loc_prices[$r['location_id']] = $r;
    }
    $audit_logs = $db->fetchAll("
        SELECT al.*, COALESCE(u.full_name, al.user_id) as updated_by_name
        FROM audit_logs al
        LEFT JOIN users u ON (al.user_id = CAST(u.id AS CHAR) OR al.user_id = u.username)
        WHERE al.record_id = :id AND al.table_name = 'items'
        ORDER BY al.created_at DESC
    ", ['id' => $id]);
}

if (!function_exists('getItemAuditDiff')) {
    function getItemAuditDiff($oldJson, $newJson) {
        $old = json_decode($oldJson, true) ?: [];
        $new = json_decode($newJson, true) ?: [];
        if (!$old && $new) return array_map(function($v) { return ['old' => '', 'new' => $v]; }, $new);
        if (!$new) return [];
        $diff = [];
        foreach ($new as $key => $val) {
            $oldVal = $old[$key] ?? '';
            if (in_array($key, ['updated_at', 'created_at', 'id'])) continue;
            if ((string)$oldVal !== (string)$val) {
                $diff[$key] = ['old' => $oldVal, 'new' => $val];
            }
        }
        return $diff;
    }
}
?>
<style>
    /* Clean Top-Aligned Field Group Styling for Item Manage */
    #item-form .ns-form-group {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        margin-bottom: 18px !important;
        width: 100% !important;
    }
    #item-form .ns-label {
        display: block !important;
        width: auto !important;
        text-align: left !important;
        padding-right: 0 !important;
        margin-bottom: 6px !important;
        font-size: 12px !important;
        font-weight: 700 !important;
        color: #475569 !important;
        letter-spacing: 0.2px;
    }
    #item-form .ns-input, 
    #item-form .ns-select {
        width: 100% !important;
        box-sizing: border-box !important;
        height: 38px !important;
        border-radius: 6px !important;
        border: 1px solid #cbd5e1 !important;
        padding: 6px 12px !important;
        font-size: 13.5px !important;
    }
    #item-form textarea.ns-input {
        height: auto !important;
    }
    .ns-tabs {
        display: flex;
        gap: 6px;
        border-bottom: 2px solid #cbd5e1;
        margin-bottom: 24px;
        background: #f8fafc;
        padding: 8px 12px 0 12px;
        border-radius: 8px 8px 0 0;
        overflow-x: auto;
    }
    .ns-tab {
        padding: 10px 20px;
        font-weight: 600;
        font-size: 13px;
        color: #64748b;
        cursor: pointer;
        border: 1px solid transparent;
        border-bottom: none;
        border-radius: 6px 6px 0 0;
        transition: all 0.2s ease-in-out;
        display: flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
    }
    .ns-tab:hover {
        color: #0284c7;
        background: #f1f5f9;
    }
    .ns-tab.active {
        color: #0284c7;
        background: #ffffff;
        border-color: #cbd5e1;
        border-bottom: 2px solid #ffffff;
        margin-bottom: -2px;
        font-weight: 700;
        box-shadow: 0 -2px 4px rgba(0,0,0,0.03);
    }
    .ns-tab-content {
        display: none;
        background: #ffffff;
        padding: 28px;
        border-radius: 0 0 8px 8px;
        border: 1px solid #cbd5e1;
        border-top: none;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .ns-tab-content.active {
        display: block;
    }

    .ns-form-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 28px;
        align-items: start;
    }
    @media (max-width: 768px) {
        .ns-form-grid-2 {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Item</div>
    <div class="ns-page-actions">
        <button type="button" class="ns-btn ns-btn-primary" onclick="saveItemForm()"><i class="fas fa-save"></i> Save Item</button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;"
                onclick="nsDelete('items', '<?php echo $id; ?>', function() { window.location.href = '?page=master/item'; })"><i
                    class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <button type="button" onclick="history.back()" class="ns-btn">Cancel</button>
    </div>
</div>

<div class="ns-form-container" style="padding: 0; background: transparent; border: none; box-shadow: none;">
    <form id="item-form" method="POST" action="api/save_item.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <!-- Standardized 5 Tabs Header -->
        <div class="ns-tabs">
            <div class="ns-tab active" onclick="nsOpenItemTab('tab-primary', this)"><i class="fas fa-box"></i> Primary Information</div>
            <div class="ns-tab" onclick="nsOpenItemTab('tab-pricing', this)"><i class="fas fa-tags"></i> Pricing & Inventory</div>
            <div class="ns-tab" onclick="nsOpenItemTab('tab-location', this)"><i class="fas fa-map-marker-alt"></i> Location-Specific Pricing</div>
            <div class="ns-tab" onclick="nsOpenItemTab('tab-accounting', this)"><i class="fas fa-file-invoice-dollar"></i> Accounting Configuration</div>
            <div class="ns-tab" onclick="nsOpenItemTab('tab-system', this)"><i class="fas fa-info-circle"></i> System Information</div>
        </div>

        <!-- Tab 1: Primary Information -->
        <div id="tab-primary" class="ns-tab-content active">
            <div class="ns-form-grid-2">
                <!-- Column 1: Basic Identifiers -->
                <div>
                    <div class="ns-form-group">
                        <label class="ns-label">Item Name <span class="ns-required">*</span></label>
                        <input type="text" name="item_name" class="ns-input"
                            value="<?php echo htmlspecialchars($data['item_name'] ?? ''); ?>" required placeholder="e.g. 8848 Full 750ml">
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label">Category</label>
                        <div style="display: flex; gap: 8px; width: 100%;">
                            <select name="item_category" id="item-category-select" class="ns-select" style="flex: 1;">
                                <option value="">Select Category</option>
                                <?php echo render_dropdown_options(get_dropdown_options('categories', $db), $data['item_category'] ?? ''); ?>
                            </select>
                            <button type="button" class="ns-btn ns-btn-outline" onclick="openQuickAdd('category')"
                                title="Add New Category" style="padding: 0 12px; height: 38px;">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label">Brand</label>
                        <input type="text" name="brand" class="ns-input"
                            value="<?php echo htmlspecialchars($data['brand'] ?? ''); ?>"
                            placeholder="e.g. Johnnie Walker, Carlsberg">
                    </div>
                </div>

                <!-- Column 2: Packaging & Barcodes -->
                <div>
                    <div class="ns-form-group">
                        <label class="ns-label">Unit Type (Base Unit)</label>
                        <div style="display: flex; gap: 8px; width: 100%;">
                            <select name="unit_type" id="item-unit-select" class="ns-select" style="flex: 1;">
                                <option value="">Select Unit</option>
                                <?php echo render_dropdown_options(get_dropdown_options('units', $db), $data['unit_type'] ?? ''); ?>
                            </select>
                            <button type="button" class="ns-btn ns-btn-outline" onclick="openQuickAdd('units')"
                                title="Add New Unit" style="padding: 0 12px; height: 38px;">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label">Bottle Size (ML)</label>
                        <input type="number" step="0.01" name="bottle_size_ml" class="ns-input"
                            value="<?php echo $data['bottle_size_ml'] ?? ''; ?>" placeholder="e.g. 750">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; width: 100%;">
                        <div class="ns-form-group">
                            <label class="ns-label">Case Unit Label</label>
                            <input type="text" name="case_unit_name" class="ns-input"
                                value="<?php echo htmlspecialchars($data['case_unit_name'] ?? 'CASE'); ?>" placeholder="e.g. CASE, PACK">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Units per Case</label>
                            <input type="number" name="units_per_case" class="ns-input"
                                value="<?php echo $data['units_per_case'] ?? ''; ?>" placeholder="e.g. 12">
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; width: 100%;">
                        <div class="ns-form-group">
                            <label class="ns-label">PCS Barcode / UPC</label>
                            <input type="text" name="barcode" class="ns-input"
                                value="<?php echo htmlspecialchars($data['barcode'] ?? ''); ?>"
                                placeholder="Scan or enter PCS barcode">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">CASE Barcode</label>
                            <input type="text" name="case_barcode" class="ns-input"
                                value="<?php echo htmlspecialchars($data['case_barcode'] ?? ''); ?>"
                                placeholder="Scan or enter CASE barcode">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: Pricing & Inventory Control -->
        <div id="tab-pricing" class="ns-tab-content">
            <div class="ns-form-grid-2">
                <!-- Column 1: Pricing -->
                <div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; width: 100%;">
                        <div class="ns-form-group">
                            <label class="ns-label">PCS Cost Price <span class="ns-required">*</span></label>
                            <input type="number" step="0.01" name="cost_price" class="ns-input" required
                                value="<?php echo $data['cost_price'] ?? '0.00'; ?>">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">CASE Purchase Price</label>
                            <input type="number" step="0.01" name="case_purchase_price" class="ns-input"
                                value="<?php echo $data['case_purchase_price'] ?? ''; ?>" placeholder="Auto if empty">
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; width: 100%;">
                        <div class="ns-form-group">
                            <label class="ns-label">PCS Selling Price <span class="ns-required">*</span></label>
                            <input type="number" step="0.01" name="selling_price" class="ns-input" required
                                value="<?php echo $data['selling_price'] ?? '0.00'; ?>">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">CASE Selling Price</label>
                            <input type="number" step="0.01" name="case_selling_price" class="ns-input"
                                value="<?php echo $data['case_selling_price'] ?? ''; ?>" placeholder="Auto if empty">
                        </div>
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label">MRP (Max Retail Price)</label>
                        <input type="number" step="0.01" name="mrp" class="ns-input"
                            value="<?php echo $data['mrp'] ?? '0.00'; ?>" placeholder="0.00">
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label">Tax Code <span class="ns-required">*</span></label>
                        <div style="display: flex; gap: 8px; width: 100%;">
                            <select name="tax_id" id="item-tax-select" class="ns-select" style="flex: 1;"
                                onchange="syncTaxRate(this)" required>
                                <option value="">Select Tax Code</option>
                                <?php
                                $tax_codes = get_accounting_tax_list();
                                foreach ($tax_codes as $tc):
                                    ?>
                                    <option value="<?php echo htmlspecialchars($tc['id']); ?>"
                                        data-rate="<?php echo $tc['value']; ?>" <?php echo ($data['tax_id'] ?? '') == $tc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="ns-btn ns-btn-outline" onclick="openQuickAdd('tax_code')"
                                title="Add New Tax Code" style="padding: 0 12px; height: 38px;">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <input type="hidden" name="tax_rate" id="item-tax-rate-hidden"
                            value="<?php echo $data['tax_rate'] ?? '0.00'; ?>">
                    </div>
                </div>

                <!-- Column 2: Inventory Controls & Description -->
                <div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; width: 100%;">
                        <div class="ns-form-group">
                            <label class="ns-label">Reorder Level</label>
                            <input type="number" name="reorder_level" class="ns-input"
                                value="<?php echo $data['reorder_level'] ?? '10'; ?>">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Reorder Quantity</label>
                            <input type="number" name="reorder_qty" class="ns-input"
                                value="<?php echo $data['reorder_qty'] ?? ''; ?>" placeholder="Default restock amount">
                        </div>
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label">Description / Notes</label>
                        <textarea name="description" class="ns-input" rows="5"
                            style="height: 128px;"><?php echo htmlspecialchars($data['description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 3: Location-Specific Pricing -->
        <div id="tab-location" class="ns-tab-content">
            <p style="font-size: 12px; color: #64748b; margin-top: 0; margin-bottom: 15px;">Leave empty to automatically use the default global base prices set above for that location.</p>
            <div style="margin-bottom: 15px; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; background: #fff;">
                <table class="ns-form-table" style="margin: 0; width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #cbd5e1;">
                            <th style="padding: 10px 15px; text-align: left; font-size: 12px; font-weight: 700; color: #475569; width: 30%;">LOCATION</th>
                            <th style="padding: 10px 15px; text-align: right; font-size: 12px; font-weight: 700; color: #475569; width: 23%;">COST PRICE (RS)</th>
                            <th style="padding: 10px 15px; text-align: right; font-size: 12px; font-weight: 700; color: #475569; width: 23%;">SELLING PRICE (RS)</th>
                            <th style="padding: 10px 15px; text-align: right; font-size: 12px; font-weight: 700; color: #475569; width: 24%;">MRP (RS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($locations as $loc): 
                            $lp = $loc_prices[$loc['id']] ?? [];
                            $c_val = isset($lp['cost_price']) && $lp['cost_price'] !== null ? number_format((float)$lp['cost_price'], 2, '.', '') : '';
                            $s_val = isset($lp['selling_price']) && $lp['selling_price'] !== null ? number_format((float)$lp['selling_price'], 2, '.', '') : '';
                            $m_val = isset($lp['mrp']) && $lp['mrp'] !== null ? number_format((float)$lp['mrp'], 2, '.', '') : '';
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 10px 15px; vertical-align: middle;">
                                <strong style="color: #1e293b; font-size: 13px;"><?php echo htmlspecialchars($loc['name']); ?></strong>
                                <span style="font-size: 11px; color: #64748b; display: block;"><?php echo htmlspecialchars($loc['type'] ?? 'Location'); ?></span>
                            </td>
                            <td style="padding: 8px 15px;">
                                <input type="number" step="0.01" name="loc_cost_price[<?php echo $loc['id']; ?>]" class="ns-input" style="text-align: right; width: 100%;" value="<?php echo $c_val; ?>" placeholder="Global Base">
                            </td>
                            <td style="padding: 8px 15px;">
                                <input type="number" step="0.01" name="loc_selling_price[<?php echo $loc['id']; ?>]" class="ns-input" style="text-align: right; width: 100%;" value="<?php echo $s_val; ?>" placeholder="Global Base">
                            </td>
                            <td style="padding: 8px 15px;">
                                <input type="number" step="0.01" name="loc_mrp[<?php echo $loc['id']; ?>]" class="ns-input" style="text-align: right; width: 100%;" value="<?php echo $m_val; ?>" placeholder="Global Base">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 4: Accounting Configuration -->
        <div id="tab-accounting" class="ns-tab-content">
            <div class="ns-form-grid-2">
                <div>
                    <div class="ns-form-group">
                        <label class="ns-label">Inventory Account <span class="ns-required">*</span></label>
                        <select name="inventory_account_id" class="ns-select" required>
                            <option value="">Select Account</option>
                            <?php foreach ($accounts as $acc):
                                if (in_array($acc['account_subtype'], ['Inventory Asset'])): ?>
                                    <option value="<?php echo $acc['id']; ?>" <?php echo ($data['inventory_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['account_name']); ?>
                                    </option>
                                <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label">COGS Account <span class="ns-required">*</span></label>
                        <select name="cogs_account_id" class="ns-select" required>
                            <option value="">Select Account</option>
                            <?php foreach ($accounts as $acc):
                                if (in_array($acc['account_subtype'], ['Cost of Goods Sold', 'Operating Expense', 'Other Expense'])): ?>
                                    <option value="<?php echo $acc['id']; ?>" <?php echo ($data['cogs_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['account_name']); ?>
                                    </option>
                                <?php endif; endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <div class="ns-form-group">
                        <label class="ns-label">Income Account <span class="ns-required">*</span></label>
                        <select name="income_account_id" class="ns-select" required>
                            <option value="">Select Account</option>
                            <?php foreach ($accounts as $acc):
                                if (in_array($acc['account_subtype'], ['Sales Income', 'Other Income'])): ?>
                                    <option value="<?php echo $acc['id']; ?>" <?php echo ($data['income_account_id'] ?? '') == $acc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['account_name']); ?>
                                    </option>
                                <?php endif; endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 5: System Information -->
        <div id="tab-system" class="ns-tab-content">
            <div style="max-width: 450px;">
                <div class="ns-form-group" style="flex-direction: row !important; align-items: center !important; gap: 8px; margin-bottom: 18px;">
                    <input type="checkbox" id="is_inactive" name="is_inactive" <?php echo (isset($data['is_active']) && $data['is_active'] == 0) ? 'checked' : ''; ?> style="width: 18px !important; height: 18px !important; cursor: pointer; accent-color: #e11d48;">
                    <label for="is_inactive" class="ns-label" style="margin: 0 !important; cursor: pointer; font-weight: 600; color: #475569;">Inactive</label>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Item ID / Key</label>
                    <input type="text" class="ns-input" value="<?php echo htmlspecialchars($data['id'] ?? 'Will be generated on save'); ?>" readonly style="background: #f8fafc; font-family: monospace;">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">SKU</label>
                    <input type="text" class="ns-input" value="<?php echo htmlspecialchars($data['sku'] ?? 'Auto-generated'); ?>" readonly style="background: #f8fafc;">
                </div>
            </div>

            <?php if ($id): ?>
                <div style="margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                    <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: 12px;">Audit Log / Change History</h3>
                    <?php if(count($audit_logs) == 0): ?>
                        <p style="color: #888; font-style: italic;">No audit changes recorded yet.</p>
                    <?php else: ?>
                        <table class="ns-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                    <th style="padding: 10px;" width="18%">Date</th>
                                    <th style="padding: 10px;" width="18%">User</th>
                                    <th style="padding: 10px;" width="20%">Field</th>
                                    <th style="padding: 10px;" width="22%">Old Value</th>
                                    <th style="padding: 10px;" width="22%">New Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($audit_logs as $log): 
                                    $diffs = getItemAuditDiff($log['old_values'] ?? '', $log['new_values'] ?? '');
                                    if ($log['action'] == 'update' && empty($diffs)) continue;
                                    if (($log['action'] == 'save' || $log['action'] == 'delete' || $log['action'] == 'create') && empty($diffs)):
                                ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 10px;"><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                                        <td style="padding: 10px;"><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                                        <td style="padding: 10px; color: #64748b; font-style: italic;">Record <?php echo ucfirst($log['action']); ?>d</td>
                                        <td style="padding: 10px;"></td>
                                        <td style="padding: 10px;"></td>
                                    </tr>
                                <?php else: foreach($diffs as $field => $changes): ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 10px;"><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                                        <td style="padding: 10px;"><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                                        <td style="padding: 10px; font-weight: 500;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $field))); ?></td>
                                        <td style="padding: 10px; color: #e74c3c; background: #fff5f5;"><del><?php echo htmlspecialchars((string)$changes['old']); ?></del></td>
                                        <td style="padding: 10px; color: #2ecc71; background: #f0fff4; font-weight: 600;"><?php echo htmlspecialchars((string)$changes['new']); ?></td>
                                    </tr>
                                <?php endforeach; endif; endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Quick Add Modal -->
<div id="quick-add-modal" class="ns-modal"
    style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
    <div
        style="background:#fff; width:400px; margin: 100px auto; border-radius:8px; overflow:hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        <div
            style="padding:15px 20px; background:#f8f9fa; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
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
    function nsOpenItemTab(tabId, element) {
        document.querySelectorAll('.ns-tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.ns-tab').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        element.classList.add('active');
    }

    function syncTaxRate(select) {
        const rate = select.options[select.selectedIndex]?.dataset?.rate ?? '0';
        document.getElementById('item-tax-rate-hidden').value = rate;
    }

    // Init on load
    document.addEventListener('DOMContentLoaded', function () {
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

    function saveItemForm() {
        const form = document.getElementById('item-form');
        if (!form) return;
        
        // Auto-switch tab if any hidden required field is invalid
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            const invalidInput = form.querySelector(':invalid');
            if (invalidInput) {
                const tabContent = invalidInput.closest('.ns-tab-content');
                if (tabContent && !tabContent.classList.contains('active')) {
                    const tabId = tabContent.id;
                    const tabHeader = document.querySelector(`.ns-tab[onclick*="${tabId}"]`);
                    if (tabHeader) nsOpenItemTab(tabId, tabHeader);
                }
                setTimeout(() => {
                    if (typeof invalidInput.reportValidity === 'function') {
                        invalidInput.reportValidity();
                    }
                    invalidInput.focus();
                }, 100);
            }
            return;
        }

        const submitBtn = document.querySelector('.ns-page-actions .ns-btn-primary');
        const originalText = submitBtn ? submitBtn.innerHTML : '';

        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
        }

        const formData = new FormData(form);
        const inactiveCheck = form.querySelector('[name="is_inactive"]');
        formData.set('is_active', (inactiveCheck && inactiveCheck.checked) ? 0 : 1);

        fetch('api/save_item.php', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    if (typeof nsNotify === 'function') {
                        nsNotify(data.message || 'Item saved successfully.');
                    } else {
                        alert(data.message || 'Item saved successfully.');
                    }
                    setTimeout(() => {
                        window.location.href = '?page=master/item/view&id=' + data.id;
                    }, 800);
                } else {
                    if (typeof nsNotify === 'function') {
                        nsNotify(data.message || 'Error occurred while saving.', 'error');
                    } else {
                        alert(data.message || 'Error occurred while saving.');
                    }
                    if (submitBtn) {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                }
            })
            .catch(err => {
                console.error('Save item error:', err);
                if (typeof nsNotify === 'function') {
                    nsNotify('Network error or server failed.', 'error');
                } else {
                    alert('Network error or server failed.');
                }
                if (submitBtn) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            });
    }

    document.getElementById('item-form').addEventListener('submit', function (e) {
        e.preventDefault();
        saveItemForm();
    });
</script>