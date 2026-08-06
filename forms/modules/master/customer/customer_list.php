<?php
require_once 'database/DBConnection.php';
$db = db();

$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$status_filter = $show_all ? "" : " AND c.is_active = 1 ";

$customers = $db->fetchAll(
    "
    SELECT c.*, loc.name as location_name,
    ((
        SELECT COALESCE(SUM(ci.total_amount), 0) 
        FROM customer_invoices ci 
        JOIN transaction_headers th ON ci.header_id = th.id 
        WHERE ci.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) + (
        SELECT COALESCE(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END), 0)
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (j.party_id = c.id OR th.party_id = c.id) AND (j.party_type = 'customer' OR j.party_type IS NULL) AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    )) AS total_sales,
    (
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id 
        WHERE p.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) AS total_paid,
    ((
        SELECT COALESCE(SUM(ci.balance_due), 0) 
        FROM customer_invoices ci 
        JOIN transaction_headers th ON ci.header_id = th.id 
        WHERE ci.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) + COALESCE((
        SELECT SUM(
            CASE WHEN j.party_id = c.id THEN (CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END) ELSE 0 END
        ) - COALESCE((
            SELECT SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)))
            FROM transaction_links tl
            JOIN transaction_headers ph ON tl.parent_id = ph.id
            JOIN payments p ON ph.id = p.header_id
            WHERE tl.child_id = th.id 
              AND tl.link_type LIKE 'payment:%'
              AND p.customer_id = c.id
              AND ph.is_deleted = 0 AND ph.status NOT IN ('void', 'voided', 'draft')
        ), 0.00)
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE j.party_id = c.id AND (j.party_type = 'customer' OR j.party_type IS NULL) AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    ), 0.00)) AS total_due
    FROM customers c 
    LEFT JOIN locations loc ON c.location_id = loc.id
    WHERE c.is_deleted = 0 $status_filter
    ORDER BY c.full_name ASC
"
);
?>
<style>
    .ns-portlet, .ns-portlet-content {
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
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
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

<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        Customers
    </h1>
    <a href="?page=master/customer/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New</a>
</div>

<div style="display: none;">
    <label id="inactive-filter-container" style="margin-left: 15px; font-size: 12px; font-weight: normal; color: #333; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; vertical-align: middle;">
        <input type="checkbox" id="show-inactive-checkbox" <?php echo $show_all ? 'checked' : ''; ?> onchange="toggleStatusFilter(this.checked)" style="cursor: pointer; margin: 0; width: 13px; height: 13px; vertical-align: middle;">
        Inactive
    </label>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th width="50" style="text-align: center;">#</th>
                    <th>Full Name</th>
                    <th>Location</th>
                    <th>Type</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th style="text-align: center;">Total Sales</th>
                    <th style="text-align: center;">Total Paid</th>
                    <th style="text-align: center;">Remaining Amount</th>
                    <th>Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1; foreach ($customers as $row): 
                    $remaining = $row['total_due'];
                ?>
                <tr>
                    <td style="text-align: center; color: #888; font-weight: 600;"><?php echo $sn++; ?></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><span style="font-weight: 600; font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #e0f2fe; color: #0369a1;"><?php echo htmlspecialchars($row['location_name'] ?? 'Gokarna'); ?></span></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['customer_type'])); ?></td>
                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td style="text-align: center; font-weight: 600; color: #080;">Rs <?php echo number_format($row['total_sales'], 2); ?></td>
                    <td style="text-align: center; color: #2563eb;">Rs <?php echo number_format($row['total_paid'], 2); ?></td>
                    <td style="text-align: center; font-weight: 600; color: <?php echo $remaining > 0 ? '#c00' : '#333'; ?>">Rs <?php echo number_format($remaining, 2); ?></td>
                    <td>
                        <span style="color: <?php echo $row['is_active'] ? '#080' : '#c00'; ?>">
                            <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <div style="position: relative; display: inline-block;">
                            <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                            </button>
                            <div class="ns-action-dropdown-menu">
                                <a href="?page=master/customer/view&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-eye" style="color: #64748b; width: 14px;"></i> View
                                </a>
                                <a href="?page=master/customer/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-edit" style="color: #0284c7; width: 14px;"></i> Edit
                                </a>
                                <div style="height: 1px; background: #e2e8f0; margin: 4px 0;"></div>
                                <a href="javascript:void(0)" onclick="nsDelete('customers', '<?php echo $row['id']; ?>')" class="ns-action-item danger">
                                    <i class="fas fa-trash-alt" style="color: #dc2626; width: 14px;"></i> Delete
                                </a>
                            </div>
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

function nsPositionDropdown(toggle, menu) {
    const btnRect = toggle.getBoundingClientRect();
    const menuH = 100;
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

window.addEventListener('scroll', function() {
    document.querySelectorAll('.ns-action-dropdown-menu.show').forEach(m => m.classList.remove('show'));
}, true);
</script>
