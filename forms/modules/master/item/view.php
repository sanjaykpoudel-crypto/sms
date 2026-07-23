<?php
require_once 'database/DBConnection.php';
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
            SELECT COALESCE(SUM(CASE 
                WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
                WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
                ELSE 0 END), 0)
            FROM transaction_lines l
            JOIN transaction_headers h ON l.header_id = h.id
            WHERE l.item_id = i.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
        ) as current_stock
    FROM items i
    LEFT JOIN accounts a1 ON i.inventory_account_id = a1.id
    LEFT JOIN accounts a2 ON i.cogs_account_id = a2.id
    LEFT JOIN accounts a3 ON i.income_account_id = a3.id
    LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category'
    LEFT JOIN reference_codes r2 ON i.unit_type = r2.id AND r2.type IN ('unit', 'units')
    WHERE i.id = ?
", [$id]);

if (!$item) {
    echo "<div class='alert alert-danger'>Item not found.</div>";
    exit;
}

// Fetch related records (Stock Movements)
$movements = $db->fetchAll("
    SELECT h.id, h.txn_date, h.txn_number, h.txn_type, l.quantity, l.unit_price, l.line_total 
    FROM transaction_lines l 
    JOIN transaction_headers h ON l.header_id = h.id 
    WHERE l.item_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ORDER BY h.txn_date DESC, h.created_at DESC LIMIT 50
", [$id]);
// Fetch Audit Logs
$audit_logs = $db->fetchAll("
    SELECT al.*, u.full_name as updated_by_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.record_id = :id AND al.table_name = 'items'
    ORDER BY al.created_at DESC
", ['id' => $id]);

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
        align-items: flex-start;
    }
    .view-title h1 {
        margin: 0;
        font-size: 24px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .view-subtitle {
        margin-top: 5px;
        color: #666;
        font-size: 14px;
    }
    .view-actions {
        display: flex;
        gap: 10px;
    }
    
    /* Tabs System */
    .ns-tabs {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 20px;
    }
    .ns-tab {
        padding: 12px 20px;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s;
    }
    .ns-tab:hover {
        color: var(--ns-primary);
    }
    .ns-tab.active {
        color: var(--ns-primary);
        border-bottom-color: var(--ns-primary);
    }
    .ns-tab-content {
        display: none;
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .ns-tab-content.active {
        display: block;
    }
    
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }
    .detail-group {
        margin-bottom: 15px;
    }
    .detail-label {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 4px;
        text-transform: uppercase;
    }
    .detail-value {
        font-size: 14px;
        color: #1e293b;
        font-weight: 500;
    }
</style>

<div class="view-header">
    <div>
        <div class="view-title">
            <h1><?php echo htmlspecialchars($item['item_name']); ?></h1>
        </div>
    </div>
    <div class="view-actions">
        <a href="?page=master/item/manage&id=<?php echo $id; ?>" class="ns-btn ns-btn-primary"><i class="fas fa-edit"></i> Edit</a>
        <a href="?page=master/item" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-tabs">
    <div class="ns-tab active" onclick="nsOpenTab('tab-primary', this)">Primary Information</div>
    <div class="ns-tab" onclick="nsOpenTab('tab-related', this)">Stock Movements <span style="background:#e2e8f0;padding:2px 6px;border-radius:10px;font-size:10px;color:#1e293b;"><?php echo count($movements); ?></span></div>
    <div class="ns-tab" onclick="nsOpenTab('tab-system', this)">System Information</div>
</div>

<!-- Primary Information -->
<div id="tab-primary" class="ns-tab-content active">
    <div class="detail-grid">
        <!-- Column 1 -->
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
                <div class="detail-label">Unit Type</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['unit_name'] ?? ($item['unit_type'] ?? '')); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Status</div>
                <div class="detail-value" style="color: <?php echo $item['is_active'] ? '#080' : '#c00'; ?>; font-weight: 600;">
                    <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                </div>
            </div>
        </div>
        <!-- Column 2 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Cost Price</div>
                <div class="detail-value">Rs <?php echo number_format($item['cost_price'] ?? 0, 2); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Selling Price</div>
                <div class="detail-value">Rs <?php echo number_format($item['selling_price'] ?? 0, 2); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Barcode</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['barcode'] ?? ''); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Description</div>
                <div class="detail-value"><?php echo nl2br(htmlspecialchars($item['description'] ?? '')); ?></div>
            </div>
        </div>
        <!-- Column 3 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Current Stock</div>
                <div class="detail-value" style="font-size: 20px; font-weight: 800; color: <?php echo ($item['current_stock'] <= $item['reorder_level']) ? 'var(--accent-red)' : 'var(--accent-green)'; ?>;">
                    <?php echo number_format($item['current_stock'] ?? 0, 0); ?>
                </div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Reorder Level</div>
                <div class="detail-value"><?php echo number_format($item['reorder_level'] ?? 0, 0); ?></div>
            </div>
            <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 15px 0;">
            <div class="detail-group">
                <div class="detail-label">Income Account</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['income_account'] ?? ''); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">COGS Account</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['cogs_account'] ?? ''); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Inventory Account</div>
                <div class="detail-value"><?php echo htmlspecialchars($item['inventory_account'] ?? ''); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Related Records -->
<div id="tab-related" class="ns-tab-content">
    <table class="ns-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Transaction #</th>
                <th>Type</th>
                <th style="text-align: right;">Quantity</th>
                <th style="text-align: right;">Unit Price</th>
                <th style="text-align: right;">Line Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($movements as $mov): 
                // Determine if this movement adds or subtracts stock
                if (in_array($mov['txn_type'], ['customer_invoice', 'Invoice', 'POS', 'Sale'])) {
                    $is_addition = false;
                } elseif (in_array($mov['txn_type'], ['vendor_bill', 'Bill', 'Opening Stock'])) {
                    $is_addition = true;
                } else {
                    $is_addition = $mov['quantity'] > 0;
                }
                $qty_color = $is_addition ? '#080' : '#c00';
                $qty_prefix = $is_addition ? '+' : '-';
                $display_qty = number_format(abs($mov['quantity']), 0);
            ?>
            <tr>
                <td><?php echo date('M d, Y', strtotime($mov['txn_date'])); ?></td>
                <td style="font-weight: 600;"><a href="?page=transactions/view&id=<?php echo htmlspecialchars($mov['id'] ?? ''); ?>" style="color: var(--ns-primary); text-decoration: none;"><?php echo htmlspecialchars($mov['txn_number']); ?></a></td>
                <td><span style="background: #eef2f6; padding: 3px 8px; border-radius: 4px; font-size: 11px; text-transform: uppercase; color: #475569;"><?php echo str_replace('_', ' ', htmlspecialchars($mov['txn_type'])); ?></span></td>
                <td style="text-align: right; font-weight: 600; color: <?php echo $qty_color; ?>;">
                    <?php echo $qty_prefix . $display_qty; ?>
                </td>
                <td style="text-align: right;">Rs <?php echo number_format($mov['unit_price'], 2); ?></td>
                <td style="text-align: right;">Rs <?php echo number_format($mov['line_total'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- System Information -->
<div id="tab-system" class="ns-tab-content">
    <div class="detail-grid" style="margin-bottom: 30px;">
        <div>
            <div class="detail-group">
                <div class="detail-label">Date Created</div>
                <div class="detail-value"><?php echo isset($item['created_at']) ? date('F d, Y h:i A', strtotime($item['created_at'])) : ''; ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Last Modified</div>
                <div class="detail-value"><?php echo isset($item['updated_at']) ? date('F d, Y h:i A', strtotime($item['updated_at'])) : ''; ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Internal ID</div>
                <div class="detail-value" style="font-family: monospace;"><?php echo $item['id']; ?></div>
            </div>
        </div>
    </div>

    <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">System Notes / Change Log</h3>
    <?php if(count($audit_logs) == 0): ?>
        <p style="color: #888; font-style: italic;">No changes recorded yet.</p>
    <?php else: ?>
        <table class="ns-table" style="width: 100%; font-size: 13px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th width="15%">Date</th>
                    <th width="15%">User</th>
                    <th width="20%">Field</th>
                    <th width="25%">Old Value</th>
                    <th width="25%">New Value</th>
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
                        <td></td>
                        <td></td>
                    </tr>
                <?php else: foreach($diffs as $field => $changes): ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                        <td style="font-weight: 500;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $field))); ?></td>
                        <td style="color: #e74c3c; background: #fff5f5;"><del><?php echo htmlspecialchars((string)$changes['old']); ?></del></td>
                        <td style="color: #2ecc71; background: #f0fff4; font-weight: 600;"><?php echo htmlspecialchars((string)$changes['new']); ?></td>
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
