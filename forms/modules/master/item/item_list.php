<?php
require_once 'database/DBConnection.php';
$db = db();

$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$status_filter = $show_all ? "" : " AND i.is_active = 1 ";

$items = $db->fetchAll("SELECT i.*, r.name as category_name, r2.name as unit_name,
    (SELECT COALESCE(SUM(CASE 
        WHEN h.txn_type IN ('vendor_bill', 'Bill', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
        WHEN h.txn_type IN ('customer_invoice', 'Invoice', 'POS', 'Sale') THEN -l.quantity 
        ELSE 0 END), 0)
     FROM transaction_lines l
     JOIN transaction_headers h ON l.header_id = h.id
     WHERE l.item_id = i.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    ) as current_stock
FROM items i 
LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category' 
LEFT JOIN reference_codes r2 ON i.unit_type = r2.id AND r2.type IN ('unit', 'units') 
WHERE i.is_deleted = 0 $status_filter
ORDER BY i.item_name ASC");
?>
<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        Items & Inventory
    </h1>
    <a href="?page=master/item/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New Item</a>
</div>

<div style="display: none;">
    <label id="inactive-filter-container" style="margin-left: 15px; font-size: 12px; font-weight: normal; color: #333; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; vertical-align: middle;">
        <input type="checkbox" id="show-inactive-checkbox" <?php echo $show_all ? 'checked' : ''; ?> onchange="toggleStatusFilter(this.checked)" style="cursor: pointer; margin: 0; width: 13px; height: 13px; vertical-align: middle;">
        Inactive
    </label>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th width="50" style="text-align: center;">#</th>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th style="text-align: center;">Units</th>
                    <th style="text-align: right;">Cost Price</th>
                    <th style="text-align: right;">Selling Price</th>
                    <th style="text-align: right;">Stock on Hand</th>
                    <th style="text-align: center;">Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1; foreach ($items as $row): ?>
                <tr>
                    <td style="text-align: center; color: #888; font-weight: 600;"><?php echo $sn++; ?></td>
                    <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['category_name'] ?? ($row['item_category'] ? ucfirst($row['item_category']) : 'Uncategorized')); ?></td>
                    <td style="text-align: center;"><?php echo htmlspecialchars($row['unit_name'] ?? $row['unit_type']); ?></td>
                    <td style="text-align: right;"><?php echo number_format($row['cost_price'], 2); ?></td>
                    <td style="text-align: right;"><?php echo number_format($row['selling_price'], 2); ?></td>
                    <td style="text-align: right; font-weight: 700; color: <?php echo ($row['current_stock'] <= $row['reorder_level']) ? 'var(--ns-danger)' : 'inherit'; ?>;">
                        <?php echo number_format($row['current_stock'] ?? 0, 0); ?>
                    </td>
                    <td style="text-align: center;">
                        <span style="color: <?php echo $row['is_active'] ? 'var(--ns-success)' : 'var(--ns-danger)'; ?>; font-weight: 600;">
                            <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <div style="display: flex; gap: 5px; justify-content: center;">
                            <a href="?page=master/item/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=master/item/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: var(--ns-danger);" title="Delete" onclick="nsDelete('items', '<?php echo $row['id']; ?>')"><i class="fas fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleStatusFilter(checked) {
    const url = new URL(window.location.href);
    if (checked) {
        url.searchParams.set('show_all', '1');
    } else {
        url.searchParams.delete('show_all');
    }
    window.location.href = url.toString();
}
</script>