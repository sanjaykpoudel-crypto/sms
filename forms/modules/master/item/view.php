<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
$db = db();
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div class='alert alert-danger'>No item ID provided.</div>";
    exit;
}

$item = $db->fetchOne("
    SELECT i.*, 
        a1.account_name as inventory_account,
        a2.account_name as cogs_account,
        a3.account_name as income_account,
        r.name as category_name,
        r2.name as unit_name,
        (
            SELECT COALESCE(SUM(quantity_on_hand), 0)
            FROM inventory_balances
            WHERE item_id = i.id
        ) as current_stock
    FROM items i
    LEFT JOIN accounts a1 ON i.inventory_account_id = a1.id
    LEFT JOIN accounts a2 ON i.cogs_account_id = a2.id
    LEFT JOIN accounts a3 ON i.income_account_id = a3.id
    LEFT JOIN reference_codes r ON (i.item_category = CAST(r.id AS CHAR) OR i.item_category = r.name OR i.item_category = r.code) AND r.type = 'category'
    LEFT JOIN reference_codes r2 ON (i.unit_type = CAST(r2.id AS CHAR) OR i.unit_type = r2.name OR i.unit_type = r2.code) AND r2.type IN ('unit', 'units')
    WHERE i.id = ?
", [$id]);

if (!$item) {
    echo "<div class='alert alert-danger'>Item not found.</div>";
    exit;
}

// Fetch Inventory Balances per Location
$inv_balances = sync_and_get_item_inventory_balances($db, $id);

// Sum quantity on hand across all locations
$total_stock_all_locations = 0;
foreach ($inv_balances as $ib) {
    $total_stock_all_locations += (float)($ib['quantity_on_hand'] ?? 0);
}
$item['current_stock'] = $total_stock_all_locations;

// Fetch related records (Stock Movements)
$movements = $db->fetchAll("
    SELECT h.id, h.txn_date, h.txn_number, h.txn_type, l.quantity, l.unit, l.conversion_factor, l.unit_price, l.line_total,
           COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1)) as base_quantity,
           COALESCE(NULLIF(l.base_unit_price, 0), CASE WHEN COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1)) > 0 THEN l.line_total / COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1)) ELSE l.unit_price END) as base_unit_price,
           COALESCE(loc.name, loc_h.name, 'Gokarna') as location_name
    FROM transaction_lines l 
    JOIN transaction_headers h ON l.header_id = h.id 
    LEFT JOIN locations loc ON l.location_id = loc.id
    LEFT JOIN locations loc_h ON h.location_id = loc_h.id
    WHERE l.item_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ORDER BY h.txn_date DESC, h.created_at DESC LIMIT 50
", [$id]);

// Fetch Audit Logs
$audit_logs = $db->fetchAll("
    SELECT al.*, COALESCE(u.full_name, al.user_id) as updated_by_name
    FROM audit_logs al
    LEFT JOIN users u ON (al.user_id = CAST(u.id AS CHAR) OR al.user_id = u.username)
    WHERE al.record_id = :id AND al.table_name = 'items'
    ORDER BY al.created_at DESC
", ['id' => $id]);

if (!function_exists('getDiff')) {
    function getDiff($oldJson, $newJson) {
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
    .view-header {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .view-title h1 {
        margin: 0;
        font-size: 22px;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .view-actions {
        display: flex;
        gap: 10px;
    }
    
    /* Standardized Tabs System */
    .ns-tabs {
        display: flex;
        gap: 6px;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 20px;
        background: #f8fafc;
        padding: 6px 10px 0 10px;
        border-radius: 8px 8px 0 0;
        overflow-x: auto;
    }
    .ns-tab {
        padding: 10px 18px;
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
        color: #0369a1;
        background: #f1f5f9;
    }
    .ns-tab.active {
        color: #0369a1;
        background: #ffffff;
        border-color: #cbd5e1;
        border-bottom: 2px solid #ffffff;
        margin-bottom: -2px;
        font-weight: 700;
        box-shadow: 0 -2px 4px rgba(0,0,0,0.02);
    }
    .ns-tab-content {
        display: none;
        background: #ffffff;
        padding: 24px;
        border-radius: 0 0 8px 8px;
        border: 1px solid #e2e8f0;
        border-top: none;
    }
    .ns-tab-content.active {
        display: block;
    }
    
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 20px;
    }
    .detail-group {
        margin-bottom: 16px;
    }
    .detail-label {
        font-size: 11px;
        color: #64748b;
        font-weight: 700;
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .detail-value {
        font-size: 14px;
        color: #1e293b;
        font-weight: 600;
    }
</style>

<div class="view-header">
    <div>
        <div class="view-title">
            <h1><i class="fas fa-box" style="color: #0284c7;"></i> <?php echo htmlspecialchars($item['item_name']); ?></h1>
        </div>
    </div>
    <div class="view-actions">
        <a href="?page=master/item/manage&id=<?php echo $id; ?>" class="ns-btn ns-btn-primary"><i class="fas fa-edit"></i> Edit</a>
        <a href="?page=master/item" class="ns-btn"><i class="fas fa-times"></i> Back to List</a>
    </div>
</div>

<div class="ns-tabs">
    <div class="ns-tab active" onclick="nsOpenTab('tab-primary', this)"><i class="fas fa-box"></i> Primary Information</div>
    <div class="ns-tab" onclick="nsOpenTab('tab-pricing', this)"><i class="fas fa-tags"></i> Pricing & Inventory</div>
    <div class="ns-tab" onclick="nsOpenTab('tab-location', this)"><i class="fas fa-map-marker-alt"></i> Location-Specific Pricing & Stock <span style="background:#e0f2fe;padding:2px 6px;border-radius:10px;font-size:10px;color:#0369a1;"><?php echo count($inv_balances); ?></span></div>
    <div class="ns-tab" onclick="nsOpenTab('tab-movements', this)"><i class="fas fa-exchange-alt"></i> Stock Movements / Related Records <span style="background:#e2e8f0;padding:2px 6px;border-radius:10px;font-size:10px;color:#1e293b;"><?php echo count($movements); ?></span></div>
    <div class="ns-tab" onclick="nsOpenTab('tab-accounting', this)"><i class="fas fa-file-invoice-dollar"></i> Accounting Configuration</div>
    <div class="ns-tab" onclick="nsOpenTab('tab-system', this)"><i class="fas fa-info-circle"></i> System Information & Audit Logs</div>
</div>

<!-- Tab 1: Primary Information -->
<div id="tab-primary" class="ns-tab-content active">
    <div class="detail-grid">
        <div>
            <div class="detail-group">
                <div class="detail-label">Item Name</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['item_name']); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Category</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['category_name'] ?? ($item['item_category'] ? ucfirst($item['item_category']) : 'Uncategorized')); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Brand</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['brand'] ?? 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Status</div>
                <div class="detail-value" style="color: <?php echo $item['is_active'] ? '#080' : '#c00'; ?>; font-weight: 700;">
                    <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                </div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Unit Type</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['unit_name'] ?? ($item['unit_type'] ?? 'PCS')); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Bottle Size (ML)</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['bottle_size_ml'] ?? 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Case Packaging Unit</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['case_unit_name'] ?? 'CASE'); ?> (<?php echo (int)($item['units_per_case'] ?? 1); ?> PCS per Case)</div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">PCS Barcode / UPC</div>
                <div class="detail-value" style="font-family: monospace;"><?php echo htmlspecialchars($item['barcode'] ?? 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">CASE Barcode</div>
                <div class="detail-value" style="font-family: monospace;"><?php echo htmlspecialchars($item['case_barcode'] ?? 'N/A'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tab 2: Pricing & Inventory Control -->
<div id="tab-pricing" class="ns-tab-content">
    <div class="detail-grid">
        <div>
            <div class="detail-group">
                <div class="detail-label">PCS Cost Price</div>
                <div class="detail-value">Rs <?php echo number_format($item['cost_price'] ?? 0, 2); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">CASE Purchase Price</div>
                <div class="detail-value">Rs <?php echo number_format($item['case_purchase_price'] ?? (($item['cost_price'] ?? 0) * ($item['units_per_case'] ?? 1)), 2); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">PCS Selling Price</div>
                <div class="detail-value">Rs <?php echo number_format($item['selling_price'] ?? 0, 2); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">CASE Selling Price</div>
                <div class="detail-value">Rs <?php echo number_format($item['case_selling_price'] ?? (($item['selling_price'] ?? 0) * ($item['units_per_case'] ?? 1)), 2); ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">MRP (Max Retail Price)</div>
                <div class="detail-value" style="color: #0284c7; font-weight: 700;">Rs <?php echo number_format($item['mrp'] ?? 0, 2); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Tax Rate</div>
                <div class="detail-value"><?php echo number_format($item['tax_rate'] ?? 0, 2); ?>%</div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Description / Notes</div>
                <div class="detail-value"><?php echo nl2br(htmlspecialchars($item['description'] ?? 'None')); ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Total Current Stock (On Hand)</div>
                <div class="detail-value" style="font-size: 20px; font-weight: 800; color: <?php echo ($item['current_stock'] <= $item['reorder_level']) ? '#e11d48' : '#059669'; ?>;">
                    <?php echo number_format($item['current_stock'] ?? 0, 2); ?> <?php echo htmlspecialchars($item['unit_name'] ?? 'PCS'); ?>
                    <?php if (($item['units_per_case'] ?? 1) > 1): ?>
                        <span style="font-size: 13px; font-weight: 600; color: #0284c7; display: block;">(<?php echo number_format(($item['current_stock'] ?? 0) / $item['units_per_case'], 2); ?> <?php echo htmlspecialchars($item['case_unit_name'] ?? 'CASE'); ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Reorder Level</div>
                <div class="detail-value"><?php echo number_format($item['reorder_level'] ?? 0, 0); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Reorder Quantity</div>
                <div class="detail-value"><?php echo number_format($item['reorder_qty'] ?? 0, 0); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tab 3: Location-Specific Pricing & Stock Balances -->
<div id="tab-location" class="ns-tab-content">
    <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin-top: 0; margin-bottom: 12px;">Location Inventory Balances & Price Overrides</h3>
    <table class="ns-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                <th style="padding: 10px; text-align: left;">Location</th>
                <th style="padding: 10px; text-align: right;">Quantity On Hand</th>
                <th style="padding: 10px; text-align: right;">Available Qty</th>
                <th style="padding: 10px; text-align: right;">Average Cost</th>
                <th style="padding: 10px; text-align: right;">Location Cost Price</th>
                <th style="padding: 10px; text-align: right;">Location Selling Price</th>
                <th style="padding: 10px; text-align: right;">Location MRP</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inv_balances)): ?>
                <tr><td colspan="7" style="text-align:center; padding: 20px; color: #999;">No location inventory balances found.</td></tr>
            <?php else: foreach ($inv_balances as $bal): 
                $eff_cost = ($bal['cost_price'] !== null && (float)$bal['cost_price'] > 0) ? number_format($bal['cost_price'], 2) : '<span style="color:#aaa;">(Global Base)</span>';
                $eff_sell = ($bal['selling_price'] !== null && (float)$bal['selling_price'] > 0) ? number_format($bal['selling_price'], 2) : '<span style="color:#aaa;">(Global Base)</span>';
                $eff_mrp  = ($bal['mrp'] !== null && (float)$bal['mrp'] > 0) ? number_format($bal['mrp'], 2) : '<span style="color:#aaa;">(Global Base)</span>';
            ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px;"><strong><?php echo htmlspecialchars($bal['location_name']); ?></strong></td>
                    <td style="padding: 10px; text-align: right; font-weight: 700; color: #059669;"><?php echo number_format($bal['quantity_on_hand'], 2); ?></td>
                    <td style="padding: 10px; text-align: right; font-weight: 700; color: #0284c7;"><?php echo number_format($bal['available_qty'], 2); ?></td>
                    <td style="padding: 10px; text-align: right; font-weight: 600;">Rs <?php echo number_format($bal['average_cost'], 2); ?></td>
                    <td style="padding: 10px; text-align: right;">Rs <?php echo $eff_cost; ?></td>
                    <td style="padding: 10px; text-align: right; font-weight: 600; color: #2563eb;">Rs <?php echo $eff_sell; ?></td>
                    <td style="padding: 10px; text-align: right; font-weight: 600; color: #0284c7;">Rs <?php echo $eff_mrp; ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Tab 4: Stock Movements / Related Records -->
<div id="tab-movements" class="ns-tab-content">
    <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin-top: 0; margin-bottom: 12px;">Stock Movements & Related Transaction History</h3>
    <table class="ns-table" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                <th style="padding: 10px; text-align: left;">Date</th>
                <th style="padding: 10px; text-align: left;">Transaction #</th>
                <th style="padding: 10px; text-align: left;">Type</th>
                <th style="padding: 10px; text-align: left;">Location</th>
                <th style="padding: 10px; text-align: right;">Quantity</th>
                <th style="padding: 10px; text-align: right;">Unit Price</th>
                <th style="padding: 10px; text-align: right;">Line Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($movements)): ?>
                <tr><td colspan="7" style="text-align:center; padding: 20px; color: #999;">No stock movements recorded yet.</td></tr>
            <?php else: foreach($movements as $mov): 
                $base_qty = (float)($mov['base_quantity'] ?? $mov['quantity']);
                $base_price = (float)($mov['base_unit_price'] ?? $mov['unit_price']);
                if (in_array($mov['txn_type'], ['customer_invoice', 'Invoice', 'POS', 'Sale'])) {
                    $is_addition = false;
                } elseif (in_array($mov['txn_type'], ['vendor_bill', 'Bill', 'Opening Stock'])) {
                    $is_addition = true;
                } else {
                    $is_addition = $base_qty > 0;
                }
                $qty_color = $is_addition ? '#059669' : '#e11d48';
                $qty_prefix = $is_addition ? '+' : '-';
                $display_qty = number_format(abs($base_qty), 0);
                $conv = (float)($mov['conversion_factor'] ?? 1);
                $sub_note = ($conv > 1 && (float)$mov['quantity'] > 0) ? ' <span style="font-size:10px; color:#64748b; font-weight:normal;">(' . number_format((float)$mov['quantity'], 0) . ' ' . htmlspecialchars($mov['unit'] ?? 'CASE') . ')</span>' : '';
            ?>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 10px;"><?php echo date('M d, Y', strtotime($mov['txn_date'])); ?></td>
                <td style="padding: 10px; font-weight: 600;"><a href="?page=transactions/view&id=<?php echo htmlspecialchars($mov['id'] ?? ''); ?>" style="color: #0284c7; text-decoration: none;"><?php echo htmlspecialchars($mov['txn_number']); ?></a></td>
                <td style="padding: 10px;"><span style="background: #eef2f6; padding: 3px 8px; border-radius: 4px; font-size: 11px; text-transform: uppercase; color: #475569;"><?php echo str_replace('_', ' ', htmlspecialchars($mov['txn_type'])); ?></span></td>
                <td style="padding: 10px;"><span style="font-weight: 600; padding: 2px 8px; border-radius: 4px; background: #f1f5f9; color: #334155; font-size: 11px;"><?php echo htmlspecialchars($mov['location_name']); ?></span></td>
                <td style="padding: 10px; text-align: right; font-weight: 600; color: <?php echo $qty_color; ?>;">
                    <?php echo $qty_prefix . $display_qty . $sub_note; ?>
                </td>
                <td style="padding: 10px; text-align: right;">Rs <?php echo number_format($base_price, 2); ?></td>
                <td style="padding: 10px; text-align: right;">Rs <?php echo number_format($mov['line_total'], 2); ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Tab 5: Accounting Configuration -->
<div id="tab-accounting" class="ns-tab-content">
    <div class="detail-grid">
        <div>
            <div class="detail-group">
                <div class="detail-label">Inventory Account</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['inventory_account'] ?? 'acc-1200 (Inventory Asset)'); ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">COGS Account</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['cogs_account'] ?? 'acc-5000 (Cost of Goods Sold)'); ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Income Account</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['income_account'] ?? 'acc-4000 (Sales Income)'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tab 6: System Information & Audit Logs -->
<div id="tab-system" class="ns-tab-content">
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 15px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            System Information & Master Metadata
        </h3>
        <div class="detail-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
            <div>
                <div class="detail-label">Internal Item ID</div>
                <div class="detail-value" style="font-family: monospace; font-weight: 700; color: #0284c7;"><?php echo htmlspecialchars($item['id']); ?></div>
            </div>
            <div>
                <div class="detail-label">SKU Code</div>
                <div class="detail-value"><span style="color: #0369a1; background: #e0f2fe; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 13px;"><?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?></span></div>
            </div>
            <div>
                <div class="detail-label">Master Record Status</div>
                <div class="detail-value">
                    <?php if (($item['is_active'] ?? 1) == 1): ?>
                        <span style="background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 12px;">Active Item</span>
                    <?php else: ?>
                        <span style="background: #f1f5f9; color: #64748b; padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 12px;">Inactive / Disabled</span>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?php echo !empty($item['created_at']) ? date('M d, Y H:i', strtotime($item['created_at'])) : 'N/A'; ?></div>
            </div>
            <div>
                <div class="detail-label">Last Updated At</div>
                <div class="detail-value"><?php echo !empty($item['updated_at']) ? date('M d, Y H:i', strtotime($item['updated_at'])) : 'N/A'; ?></div>
            </div>
            <div>
                <div class="detail-label">Packaging Ratio</div>
                <div class="detail-value"><?php echo (int)($item['units_per_case'] ?? 1); ?> PCS per <?php echo htmlspecialchars($item['case_unit_name'] ?? 'CASE'); ?></div>
            </div>
            <div>
                <div class="detail-label">Total Stock Movement Records</div>
                <div class="detail-value" style="font-weight: 700; color: #475569;"><?php echo count($movements); ?> Movements</div>
            </div>
        </div>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
        <h3 style="margin: 0; color: var(--ns-primary); font-size: 15px;">Audit Log & Modification History</h3>
        <span style="font-size: 12px; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 12px; font-weight: 600;"><?php echo count($audit_logs); ?> audit records</span>
    </div>

    <?php if(count($audit_logs) == 0): ?>
        <div style="padding: 24px; text-align: center; background: #fafafa; border: 1px dashed #cbd5e1; border-radius: 8px; color: #64748b;">
            <p style="margin: 0 0 6px 0; font-weight: 600;">No explicit item master changes recorded yet.</p>
            <p style="margin: 0; font-size: 12px; color: #94a3b8;">System automatically records audit trail logs when item pricing, cost, MRP, or details are updated via bill entry or master form edits.</p>
        </div>
    <?php else: ?>
        <table class="ns-table" style="width: 100%; font-size: 13px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th width="18%">Date & Time</th>
                    <th width="15%">Updated By</th>
                    <th width="20%">Field Changed</th>
                    <th width="23%">Old Value</th>
                    <th width="24%">New Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($audit_logs as $log): 
                    $diffs = getDiff($log['old_values'] ?? '', $log['new_values'] ?? '');
                    if ($log['action'] == 'update' && empty($diffs)) continue;
                    if (($log['action'] == 'save' || $log['action'] == 'delete' || $log['action'] == 'create') && empty($diffs)):
                ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                        <td style="color: #64748b; font-style: italic;">Record <?php echo ucfirst($log['action']); ?>d</td>
                        <td>-</td>
                        <td>-</td>
                    </tr>
                <?php else: foreach($diffs as $field => $changes): ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                        <td style="font-weight: 600; color: #334155;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $field))); ?></td>
                        <td style="color: #e74c3c; background: #fff5f5;"><del><?php echo htmlspecialchars((string)$changes['old']); ?></del></td>
                        <td style="color: #16a34a; background: #f0fdf4; font-weight: 600;"><?php echo htmlspecialchars((string)$changes['new']); ?></td>
                    </tr>
                <?php endforeach; endif; endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
function nsOpenTab(tabId, element) {
    document.querySelectorAll('.ns-tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.ns-tab').forEach(el => el.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    element.classList.add('active');
}
</script>
