<?php
$db = db();
$list = $db->fetchAll("
    SELECT t.*, 
           (SELECT COUNT(DISTINCT je.party_id) 
            FROM journal_entries je 
            WHERE je.header_id = t.id AND je.party_id IS NOT NULL AND je.party_id != '') as party_count,
           (SELECT COALESCE(c.full_name, v.company_name, u.full_name)
            FROM journal_entries je
            LEFT JOIN customers c ON je.party_id = c.id AND (je.party_type = 'customer' OR (je.party_type IS NULL AND c.id IS NOT NULL))
            LEFT JOIN vendors v ON je.party_id = v.id AND (je.party_type = 'vendor' OR (je.party_type IS NULL AND v.id IS NOT NULL))
            LEFT JOIN users u ON je.party_id = u.id AND je.party_type = 'user'
            WHERE je.header_id = t.id AND je.party_id IS NOT NULL AND je.party_id != ''
            LIMIT 1) as single_party_name,
           COALESCE(c_hdr.full_name, v_hdr.company_name) as hdr_party_name,
           u_created.full_name as creator_name
    FROM transaction_headers t
    LEFT JOIN customers c_hdr ON t.party_id = c_hdr.id AND t.party_type = 'customer'
    LEFT JOIN vendors v_hdr ON t.party_id = v_hdr.id AND t.party_type = 'vendor'
    LEFT JOIN users u_created ON t.created_by = u_created.id
    WHERE t.txn_type IN ('Journal', 'account_transfer') AND t.is_deleted = 0
    ORDER BY t.created_at DESC
");
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
        <i class="fas fa-book" style="color: #d97706; margin-right: 8px;"></i> Journal Entries
    </h1>
    <a href="?page=transactions/journal/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New</a>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Journal #</th>
                    <th>Name / Entity</th>
                    <th>Reference</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): 
                    $party_disp = '-';
                    if (!empty($row['hdr_party_name'])) {
                        $party_disp = $row['hdr_party_name'];
                    } elseif ((int)($row['party_count'] ?? 0) > 1) {
                        $party_disp = 'Multiple Parties (' . $row['party_count'] . ')';
                    } elseif ((int)($row['party_count'] ?? 0) === 1) {
                        $party_disp = $row['single_party_name'] ?? '-';
                    }
                ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?></td>
                    <td><strong><?php echo htmlspecialchars($party_disp); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['reference_number'] ?? $row['ref_number'] ?? '-'); ?></td>
                    <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['net_amount'], 2); ?></td>
                    <td>
                        <span class="badge" style="background: <?php echo $row['status'] == 'posted' ? '#e6fffa' : '#fffaf0'; ?>; color: <?php echo $row['status'] == 'posted' ? '#047481' : '#b7791f'; ?>; border: 1px solid currentColor; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                            <?php echo ucwords($row['status'] ?? 'Draft'); ?>
                        </span>
                    </td>
                    <td style="font-size: 12px;"><?php echo htmlspecialchars($row['creator_name'] ?? $row['created_by']); ?></td>
                    <td style="text-align: center;">
                        <div style="position: relative; display: inline-block;">
                            <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                            </button>
                            <div class="ns-action-dropdown-menu">
                                <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-eye" style="color: #64748b; width: 14px;"></i> View
                                </a>
                                <a href="?page=transactions/journal/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-edit" style="color: #0284c7; width: 14px;"></i> Edit
                                </a>
                                <div style="height: 1px; background: #e2e8f0; margin: 4px 0;"></div>
                                <a href="javascript:void(0)" onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')" class="ns-action-item danger">
                                    <i class="fas fa-ban" style="color: #dc2626; width: 14px;"></i> Void / Delete
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

window.addEventListener('scroll', function() {
    document.querySelectorAll('.ns-action-dropdown-menu.show').forEach(m => m.classList.remove('show'));
}, true);
</script>
