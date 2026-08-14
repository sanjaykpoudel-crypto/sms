<?php
/**
 * forms/modules/sales/promotions/promotion_manage.php
 * Promotional Discount Form (Create / Edit Promotion)
 */

require_once 'database/DBConnection.php';
$db = db();

$edit_id = $_GET['id'] ?? null;
$promo = null;
$promo_items = [];
$promo_locations = [];

$all_locations = $db->fetchAll("SELECT id, name FROM locations WHERE is_deleted = 0 AND is_active = 1 ORDER BY name ASC");

if ($edit_id) {
    $promo = $db->fetchOne("SELECT * FROM promotions WHERE id = ? AND is_deleted = 0", [$edit_id]);
    if ($promo) {
        // Fetch selected items
        $promo_items = $db->fetchAll("
            SELECT 
                pi.*, 
                i.item_name, i.sku,
                CAST(COALESCE(i.mrp, 0) AS DECIMAL(12,2)) as mrp,
                CAST(COALESCE(i.selling_price, 0) AS DECIMAL(12,2)) as selling_price
            FROM promotion_items pi
            JOIN items i ON pi.item_id = i.id
            WHERE pi.promotion_id = ?
        ", [$edit_id]);

        // Fetch selected locations
        $loc_rows = $db->fetchAll("SELECT location_id FROM promotion_locations WHERE promotion_id = ?", [$edit_id]);
        $promo_locations = array_column($loc_rows, 'location_id');
    }
}

$start_dt = $promo ? date('Y-m-d', strtotime($promo['start_datetime'])) : date('Y-m-d');
$start_tm = $promo ? date('H:i', strtotime($promo['start_datetime'])) : '00:00';
$end_dt   = $promo ? date('Y-m-d', strtotime($promo['end_datetime'])) : date('Y-m-d', strtotime('+30 days'));
$end_tm   = $promo ? date('H:i', strtotime($promo['end_datetime'])) : '23:59';
$promo_code = $promo ? $promo['promo_code'] : ('PROMO-' . strtoupper(substr(md5(uniqid()), 0, 6)));
?>

<style>
.promo-card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.03); }
.promo-section-title { font-size: 15px; font-weight: 800; color: #0f172a; margin: 0 0 15px 0; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 12px; font-weight: 700; color: #475569; margin: 0; }
.form-control { width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; transition: 0.2s; box-sizing: border-box; }
.form-control:focus { border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1); }
</style>

<div class="ns-page-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
    <div>
        <h1 class="ns-page-title" style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a;">
            <i class="fas fa-tags" style="color: #0284c7; margin-right: 10px;"></i> <?php echo $promo ? 'Edit Promotion: ' . htmlspecialchars($promo['promo_code']) : 'New Promotional Discount'; ?>
        </h1>
        <p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b;">Configure promotion rules, MRP/Selling price discount, location scope, and item selection.</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="?page=sales/promotions" class="ns-btn" style="padding: 8px 16px; font-size: 13px; font-weight: 600; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; color: #475569;">
            <i class="fas fa-arrow-left"></i> Back to Register
        </a>
    </div>
</div>

<form id="promo-form" onsubmit="savePromotion(event)">
    <input type="hidden" name="id" value="<?php echo htmlspecialchars($promo['id'] ?? ''); ?>">

    <!-- 1. Promotion Header & Info -->
    <div class="promo-card">
        <div class="promo-section-title"><i class="fas fa-info-circle" style="color: #0284c7;"></i> Promotion Information</div>
        <div class="form-grid">
            <div class="form-group">
                <label>Promotion Code <span style="color:#ef4444">*</span></label>
                <input type="text" name="promo_code" class="form-control" value="<?php echo htmlspecialchars($promo_code); ?>" required style="font-weight: 700; color: #0284c7;">
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Promotion Name <span style="color:#ef4444">*</span></label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Dashain Festival Special 10% Off" value="<?php echo htmlspecialchars($promo['name'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control" style="font-weight: 700;">
                    <option value="active" <?php echo ($promo['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="draft" <?php echo ($promo['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="inactive" <?php echo ($promo['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="form-group" style="grid-column: span 4;">
                <label>Description / Notes</label>
                <input type="text" name="description" class="form-control" placeholder="Optional internal memo or customer terms" value="<?php echo htmlspecialchars($promo['description'] ?? ''); ?>">
            </div>
        </div>
    </div>

    <!-- 2. Validity Date & Time Range -->
    <div class="promo-card">
        <div class="promo-section-title"><i class="far fa-clock" style="color: #8b5cf6;"></i> Validity Period (Server Time Authorization)</div>
        <div class="form-grid">
            <div class="form-group">
                <label>Start Date <span style="color:#ef4444">*</span></label>
                <input type="date" name="start_date" class="form-control" value="<?php echo $start_dt; ?>" required>
            </div>
            <div class="form-group">
                <label>Start Time</label>
                <input type="time" name="start_time" class="form-control" value="<?php echo $start_tm; ?>" required>
            </div>
            <div class="form-group">
                <label>End Date <span style="color:#ef4444">*</span></label>
                <input type="date" name="end_date" class="form-control" value="<?php echo $end_dt; ?>" required>
            </div>
            <div class="form-group">
                <label>End Time</label>
                <input type="time" name="end_time" class="form-control" value="<?php echo $end_tm; ?>" required>
            </div>
        </div>
    </div>

    <!-- 3. Discount Configuration -->
    <div class="promo-card">
        <div class="promo-section-title"><i class="fas fa-calculator" style="color: #10b981;"></i> Discount Calculation Setup</div>
        <div class="form-grid">
            <div class="form-group">
                <label>Discount Basis <span style="color:#ef4444">*</span></label>
                <select name="discount_basis" id="discount-basis" class="form-control" style="font-weight: 700;" onchange="updatePreview()">
                    <option value="mrp" <?php echo ($promo['discount_basis'] ?? 'mrp') === 'mrp' ? 'selected' : ''; ?>>MRP (Maximum Retail Price)</option>
                    <option value="selling_price" <?php echo ($promo['discount_basis'] ?? '') === 'selling_price' ? 'selected' : ''; ?>>Normal Selling Price</option>
                </select>
                <small style="font-size: 11px; color: #64748b;">Note: Master MRP is NEVER altered. Promotion derives transactional selling price.</small>
            </div>
            <div class="form-group">
                <label>Discount Type <span style="color:#ef4444">*</span></label>
                <select name="discount_type" id="discount-type" class="form-control" style="font-weight: 700;" onchange="updatePreview()">
                    <option value="percentage" <?php echo ($promo['discount_type'] ?? 'percentage') === 'percentage' ? 'selected' : ''; ?>>Percentage (%)</option>
                    <option value="fixed" <?php echo ($promo['discount_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Fixed Amount (Rs Off)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Discount Value <span style="color:#ef4444">*</span></label>
                <input type="number" step="0.01" min="0.01" name="discount_value" id="discount-value" class="form-control" value="<?php echo htmlspecialchars($promo['discount_value'] ?? '10'); ?>" required style="font-weight: 700; color: #10b981;" oninput="updatePreview()">
            </div>
            <div class="form-group">
                <label>Priority Rule</label>
                <input type="number" min="1" max="100" name="priority" class="form-control" value="<?php echo htmlspecialchars($promo['priority'] ?? '1'); ?>" required>
                <small style="font-size: 11px; color: #64748b;">Higher priority applies first if multiple promos exist.</small>
            </div>
        </div>
    </div>

    <!-- 4. Location Applicability -->
    <div class="promo-card">
        <div class="promo-section-title"><i class="fas fa-map-marker-alt" style="color: #f59e0b;"></i> Location Applicability</div>
        <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 15px;">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; cursor: pointer;">
                <input type="radio" name="applies_to_locations" value="all" <?php echo ($promo['applies_to_locations'] ?? 'all') === 'all' ? 'checked' : ''; ?> onchange="toggleLocationSelect(false)">
                All Locations / Branches
            </label>
            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; cursor: pointer;">
                <input type="radio" name="applies_to_locations" value="selected" <?php echo ($promo['applies_to_locations'] ?? '') === 'selected' ? 'checked' : ''; ?> onchange="toggleLocationSelect(true)">
                Specific Selected Locations
            </label>
        </div>

        <div id="location-picker-wrap" style="display: <?php echo ($promo['applies_to_locations'] ?? 'all') === 'selected' ? 'flex' : 'none'; ?>; gap: 15px; flex-wrap: wrap; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <?php foreach ($all_locations as $loc): ?>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer; background: #fff; padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    <input type="checkbox" name="location_ids[]" value="<?php echo $loc['id']; ?>" <?php echo in_array($loc['id'], $promo_locations) ? 'checked' : ''; ?>>
                    <?php echo htmlspecialchars($loc['name']); ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 5. Covered Item Selection Grid -->
    <div class="promo-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
            <div class="promo-section-title" style="margin: 0; border: none; padding: 0;"><i class="fas fa-cubes" style="color: #0284c7;"></i> Selected Covered Items</div>
            <button type="button" class="ns-btn ns-btn-primary" onclick="openItemSearchModal()" style="padding: 6px 14px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fas fa-search"></i> Add Items from Master
            </button>
        </div>

        <table class="ns-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 12px; text-transform: uppercase; color: #475569; text-align: left;">
                    <th style="padding: 10px 12px;">Item Name</th>
                    <th style="padding: 10px 12px; text-align: right;">Master MRP</th>
                    <th style="padding: 10px 12px; text-align: right;">Normal Selling Price</th>
                    <th style="padding: 10px 12px; text-align: right;">Promo Selling Price (Preview)</th>
                    <th style="padding: 10px 12px; text-align: right;">Discount Savings</th>
                    <th style="padding: 10px 12px; text-align: center; width: 60px;">Action</th>
                </tr>
            </thead>
            <tbody id="promo-item-list">
                <!-- Rendered dynamically -->
            </tbody>
        </table>
    </div>

    <!-- Submit Button Bar -->
    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
        <a href="?page=sales/promotions" class="ns-btn" style="padding: 12px 24px; font-size: 14px; font-weight: 700; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; color: #475569;">Cancel</a>
        <button type="submit" id="btn-save-promo" class="ns-btn ns-btn-primary" style="padding: 12px 28px; font-size: 14px; font-weight: 800; border-radius: 8px; background: #0284c7; border: none; color: #fff; cursor: pointer;">
            <i class="fas fa-check-circle"></i> Save Promotion
        </button>
    </div>
</form>

<!-- Item Search Modal -->
<div id="item-search-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(3px); z-index: 99999; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 16px; width: 650px; max-width: 92%; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden;">
        <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;"><i class="fas fa-boxes" style="color: #0284c7;"></i> Select Items for Promotion</h3>
            <button type="button" onclick="closeItemSearchModal()" style="border: none; background: transparent; color: #64748b; cursor: pointer; font-size: 18px;"><i class="fas fa-times"></i></button>
        </div>
        <div style="padding: 15px; border-bottom: 1px solid #e2e8f0;">
            <input type="text" id="modal-item-search-input" class="form-control" placeholder="Search item name, SKU or barcode..." oninput="debounceSearchItems()">
        </div>
        <div id="modal-item-results" style="flex: 1; overflow-y: auto; padding: 15px;">
            <!-- Search results rendered here -->
        </div>
        <div style="padding: 15px; background: #f8fafc; border-top: 1px solid #e2e8f0; text-align: right;">
            <button type="button" onclick="closeItemSearchModal()" class="ns-btn" style="padding: 8px 18px; font-size: 13px; font-weight: 700; border-radius: 8px;">Done</button>
        </div>
    </div>
</div>

<script>
let selectedItems = <?php echo json_encode($promo_items); ?>;

function toggleLocationSelect(show) {
    document.getElementById('location-picker-wrap').style.display = show ? 'flex' : 'none';
}

function openItemSearchModal() {
    document.getElementById('item-search-modal').style.display = 'flex';
    document.getElementById('modal-item-search-input').focus();
    fetchSearchItems();
}

function closeItemSearchModal() {
    document.getElementById('item-search-modal').style.display = 'none';
}

let promoSearchTimer;
function debounceSearchItems() {
    clearTimeout(promoSearchTimer);
    promoSearchTimer = setTimeout(fetchSearchItems, 250);
}

function fetchSearchItems() {
    const q = document.getElementById('modal-item-search-input').value;
    fetch('api/save_promotion.php?action=search_items&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                renderModalResults(res.items);
            }
        });
}

function renderModalResults(items) {
    const container = document.getElementById('modal-item-results');
    if (!items || items.length === 0) {
        container.innerHTML = '<div style="text-align: center; color: #94a3b8; padding: 20px;">No items found.</div>';
        return;
    }

    container.innerHTML = items.map(item => {
        const isSelected = selectedItems.some(i => String(i.item_id || i.id) === String(item.id));
        return `
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-bottom: 1px solid #f1f5f9;">
                <div>
                    <div style="font-weight: 700; color: #0f172a; font-size: 14px;">${item.item_name}</div>
                    <div style="font-size: 11px; color: #64748b;">SKU: ${item.sku || 'N/A'} | Category: ${item.category_name || 'Other'}</div>
                </div>
                <div style="text-align: right; display: flex; gap: 15px; align-items: center;">
                    <div style="font-size: 12px;">
                        <span style="color: #64748b;">MRP:</span> <strong style="color: #7c3aed;">Rs ${parseFloat(item.mrp).toFixed(2)}</strong><br>
                        <span style="color: #64748b;">Sell:</span> <strong style="color: #16a34a;">Rs ${parseFloat(item.selling_price).toFixed(2)}</strong>
                    </div>
                    <button type="button" class="ns-btn" style="padding: 4px 12px; font-size: 12px; border-radius: 6px; ${isSelected ? 'background: #e2e8f0; color: #64748b;' : 'background: #0284c7; color: #fff;'}" ${isSelected ? 'disabled' : ''} onclick="addItemToPromo(${JSON.stringify(item).replace(/"/g, '&quot;')})">
                        ${isSelected ? 'Selected' : '<i class="fas fa-plus"></i> Select'}
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function addItemToPromo(item) {
    if (selectedItems.some(i => String(i.item_id || i.id) === String(item.id))) return;
    selectedItems.push({
        item_id: item.id,
        id: item.id,
        item_name: item.item_name,
        mrp: parseFloat(item.mrp || 0),
        selling_price: parseFloat(item.selling_price || 0)
    });
    renderSelectedItemsGrid();
    fetchSearchItems();
}

function removeItemFromPromo(itemId) {
    selectedItems = selectedItems.filter(i => String(i.item_id || i.id) !== String(itemId));
    renderSelectedItemsGrid();
}

function updatePreview() {
    renderSelectedItemsGrid();
}

function renderSelectedItemsGrid() {
    const tbody = document.getElementById('promo-item-list');
    if (selectedItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 25px;">No items selected yet. Click "Add Items from Master" to select items for this promotion.</td></tr>';
        return;
    }

    const basis = document.getElementById('discount-basis').value;
    const type = document.getElementById('discount-type').value;
    const val = parseFloat(document.getElementById('discount-value').value) || 0;

    tbody.innerHTML = selectedItems.map((item, idx) => {
        const itemId = item.item_id || item.id;
        const mrp = parseFloat(item.mrp || 0);
        const sellPrice = parseFloat(item.selling_price || 0);
        const basePrice = (basis === 'mrp' && mrp > 0) ? mrp : sellPrice;

        let discAmt = 0;
        if (type === 'percentage') {
            discAmt = basePrice * (val / 100);
        } else {
            discAmt = val;
        }
        discAmt = Math.min(discAmt, basePrice);
        const promoPrice = Math.max(0, basePrice - discAmt);
        const savings = sellPrice - promoPrice;

        return `
            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                <td style="padding: 10px 12px; font-weight: 700; color: #0f172a;">${item.item_name}</td>
                <td style="padding: 10px 12px; text-align: right; font-weight: 700; color: #7c3aed;">Rs ${mrp.toFixed(2)}</td>
                <td style="padding: 10px 12px; text-align: right; font-weight: 700; color: #475569;">Rs ${sellPrice.toFixed(2)}</td>
                <td style="padding: 10px 12px; text-align: right; font-weight: 800; color: #16a34a;">Rs ${promoPrice.toFixed(2)}</td>
                <td style="padding: 10px 12px; text-align: right; font-weight: 700; color: #ea580c;">Rs ${savings.toFixed(2)}</td>
                <td style="padding: 10px 12px; text-align: center;">
                    <button type="button" class="ns-btn" style="padding: 2px 8px; font-size: 11px; background: #fef2f2; color: #ef4444; border: 1px solid #fca5a5; border-radius: 4px;" onclick="removeItemFromPromo(${itemId})"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
    }).join('');
}

function savePromotion(e) {
    e.preventDefault();
    if (selectedItems.length === 0) {
        alert("Please select at least one item for this promotion.");
        return;
    }

    const form = document.getElementById('promo-form');
    const formData = new FormData(form);

    selectedItems.forEach(item => {
        formData.append('items[]', item.item_id || item.id);
    });

    const btn = document.getElementById('btn-save-promo');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    fetch('api/save_promotion.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                nsNotify(res.message);
                setTimeout(() => location.href = '?page=sales/promotions', 500);
            } else {
                alert(res.message || 'Error saving promotion');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Save Promotion';
            }
        })
        .catch(err => {
            alert(err.message || 'Network error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Save Promotion';
        });
}

// Initial render
renderSelectedItemsGrid();
</script>
