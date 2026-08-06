<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');
$from_loc_id = $_GET['from_location_id'] ?? '';
$to_loc_id = $_GET['to_location_id'] ?? '';

$where = "h.txn_type = 'inventory_transfer' AND h.is_deleted = 0 AND h.txn_date BETWEEN ? AND ?";
$params = [$date_from, $date_to];

if (!empty($from_loc_id)) {
    $where .= " AND h.location_id = ?";
    $params[] = $from_loc_id;
}
if (!empty($to_loc_id)) {
    $where .= " AND h.party_id = ?";
    $params[] = $to_loc_id;
}
if (!empty($search)) {
    $where .= " AND (h.txn_number LIKE ? OR h.memo LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$rows = $db->fetchAll("
    SELECT 
        h.id, h.txn_number, h.txn_date, h.status, h.memo, h.net_amount as total_value,
        fl.name as from_location,
        tl.name as to_location,
        COUNT(l.id) as item_count,
        COALESCE(SUM(l.quantity), 0) as total_qty
    FROM transaction_headers h
    LEFT JOIN locations fl ON h.location_id = fl.id
    LEFT JOIN locations tl ON h.party_id = tl.id
    LEFT JOIN transaction_lines l ON l.header_id = h.id
    WHERE {$where}
    GROUP BY h.id
    ORDER BY h.txn_date DESC, h.txn_number DESC
", $params);

$locations = get_active_locations();
?>

<style>
    .rpt-toolbar {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 14px 18px;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px
    }

    .rpt-title {
        font-size: 16px;
        font-weight: 700;
        color: #1e293b;
        flex: 1;
        display: flex;
        align-items: center;
        gap: 8px
    }

    .rpt-filter-form {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px
    }

    .rpt-filter-group {
        display: flex;
        align-items: center;
        gap: 5px
    }

    .rpt-filter-group label {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
        white-space: nowrap
    }

    .rpt-input {
        padding: 5px 8px !important;
        font-size: 12px !important;
        height: 30px !important;
        border: 1px solid #cbd5e1;
        border-radius: 4px
    }

    .ns-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden
    }

    .ns-table th {
        background: #f8fafc;
        color: #475569;
        font-weight: 700;
        padding: 10px 14px;
        text-align: left;
        border-bottom: 1px solid #e2e8f0
    }

    .ns-table td {
        padding: 10px 14px;
        border-bottom: 1px solid #e2e8f0;
        color: #334155
    }

    .ns-table tr:hover {
        background: #f1f5f9
    }

    .status-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        background: #e0f2fe;
        color: #0369a1
    }
</style>

<div class="rpt-toolbar">
    <div class="rpt-title">
        <i class="fas fa-boxes-packing" style="color: #0284c7;"></i> Inventory Transfer Register
    </div>
    <form class="rpt-filter-form" method="GET" action="index.php">
        <input type="hidden" name="page" value="transactions/inventory_transfer">

        <div class="rpt-filter-group">
            <label>From Date:</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="rpt-input">
        </div>
        <div class="rpt-filter-group">
            <label>To Date:</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="rpt-input">
        </div>
        <div class="rpt-filter-group">
            <label>From Loc:</label>
            <select name="from_location_id" class="rpt-input">
                <option value="">All Source Locs</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['id']; ?>" <?php echo ($from_loc_id == $loc['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rpt-filter-group">
            <label>To Loc:</label>
            <select name="to_location_id" class="rpt-input">
                <option value="">All Dest Locs</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['id']; ?>" <?php echo ($to_loc_id == $loc['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rpt-filter-group">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Search transfer # or memo..." class="rpt-input" style="width:160px">
        </div>
        <button type="submit" class="ns-btn ns-btn-primary" style="height:30px;padding:0 12px;font-size:12px"><i
                class="fas fa-filter"></i> Filter</button>
        <a href="?page=transactions/inventory_transfer/manage" class="ns-btn ns-btn-primary"
            style="height:30px;padding:0 12px;font-size:12px;background:#10b981;border-color:#10b981"><i
                class="fas fa-plus"></i> New Transfer</a>
    </form>
</div>

<div style="background:#fff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden">
    <table class="ns-table">
        <thead>
            <tr>
                <th width="120">Transfer #</th>
                <th width="100">Date</th>
                <th>From Location (Source)</th>
                <th>To Location (Destination)</th>
                <th width="90" style="text-align:center">Items</th>
                <th width="100" style="text-align:right">Total Qty</th>
                <th width="120" style="text-align:right">Total Value</th>
                <th width="90" style="text-align:center">Status</th>
                <th width="110" style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="font-weight:700;color:#0284c7">
                            <a href="?page=transactions/inventory_transfer/view&id=<?php echo $r['id']; ?>"
                                style="color:inherit;text-decoration:none">
                                <?php echo htmlspecialchars($r['txn_number']); ?>
                            </a>
                        </td>
                        <td><?php echo date('d M Y', strtotime($r['txn_date'])); ?></td>
                        <td><span
                                style="font-weight:600;color:#1e293b"><?php echo htmlspecialchars($r['from_location'] ?? 'Default Store'); ?></span>
                        </td>
                        <td><span
                                style="font-weight:600;color:#10b981"><?php echo htmlspecialchars($r['to_location'] ?? 'Unknown Store'); ?></span>
                        </td>
                        <td style="text-align:center"><?php echo $r['item_count']; ?></td>
                        <td style="text-align:right;font-weight:600"><?php echo number_format($r['total_qty'], 2); ?></td>
                        <td style="text-align:right;font-weight:700;color:#0284c7">Rs
                            <?php echo number_format($r['total_value'], 2); ?></td>
                        <td style="text-align:center"><span
                                class="status-badge"><?php echo htmlspecialchars($r['status']); ?></span></td>
                        <td style="text-align:center; position: relative;">
                            <button class="ns-action-btn ns-dropdown-toggle">Actions <i
                                    class="fas fa-chevron-down"></i></button>
                            <div class="ns-action-dropdown-menu">
                                <a href="?page=transactions/inventory_transfer/view&id=<?php echo $r['id']; ?>"
                                    class="ns-action-item"><i class="fas fa-eye"></i> View</a>
                                <a href="?page=transactions/inventory_transfer/manage&id=<?php echo $r['id']; ?>"
                                    class="ns-action-item"><i class="fas fa-edit"></i> Edit</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:30px;color:#94a3b8">
                        <i class="fas fa-boxes-packing" style="font-size:36px;margin-bottom:10px;opacity:0.4"></i>
                        <div>No inventory transfers found for the selected period.</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
    .ns-portlet,
    .ns-portlet-content,
    div[style*="overflow:hidden"] {
        overflow: visible !important;
    }

    .ns-action-btn {
        padding: 4px 10px;
        font-size: 11px;
        font-weight: 600;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        color: #0f172a;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.15s ease;
    }

    .ns-action-btn:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
        color: #0284c7;
    }

    .ns-action-dropdown-menu {
        display: none;
        position: fixed;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        min-width: 160px;
        z-index: 99999;
        padding: 4px 0;
    }

    .ns-action-dropdown-menu.show {
        display: block;
    }

    .ns-action-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        font-size: 12px;
        color: #334155;
        text-decoration: none;
        font-weight: 500;
        transition: background 0.15s ease;
    }

    .ns-action-item:hover {
        background: #f1f5f9;
        color: #0284c7;
        text-decoration: none;
    }

    .ns-action-item.danger:hover {
        background: #fef2f2;
        color: #dc2626;
    }
</style>

<script>
    function nsPositionDropdown(toggle, menu) {
        const btnRect = toggle.getBoundingClientRect();
        const menuH = 120;
        const spaceBelow = window.innerHeight - btnRect.bottom;
        menu.style.left = (btnRect.right - 160) + 'px';
        if (spaceBelow < menuH + 10) {
            menu.style.top = (btnRect.top - menuH) + 'px';
        } else {
            menu.style.top = (btnRect.bottom + 4) + 'px';
        }
    }

    document.addEventListener('click', function (e) {
        const toggle = e.target.closest('.ns-dropdown-toggle');
        const allMenus = document.querySelectorAll('.ns-action-dropdown-menu');

        if (toggle) {
            e.stopPropagation();
            const menu = toggle.nextElementSibling;
            const isOpen = menu.classList.contains('show');
            allMenus.forEach(m => m.classList.remove('show'));
            if (!isOpen) {
                menu.classList.add('show');
                nsPositionDropdown(toggle, menu);
            }
        } else {
            allMenus.forEach(m => m.classList.remove('show'));
        }
    });

    window.addEventListener('scroll', function () {
        document.querySelectorAll('.ns-action-dropdown-menu.show').forEach(m => m.classList.remove('show'));
    }, true);
</script>