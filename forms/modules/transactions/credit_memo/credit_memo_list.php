<?php
require_once 'database/DBConnection.php';
$db = db();

// Ensure credit_memos table exists
$db->execute("
    CREATE TABLE IF NOT EXISTS credit_memos (
        id VARCHAR(36) PRIMARY KEY,
        header_id VARCHAR(36) NOT NULL,
        customer_id VARCHAR(36) NOT NULL,
        memo_number VARCHAR(50) NOT NULL,
        memo_date DATE NOT NULL,
        invoice_id VARCHAR(36) NULL,
        return_to_stock TINYINT(1) DEFAULT 1,
        subtotal DECIMAL(14,2) DEFAULT 0,
        tax_amount DECIMAL(14,2) DEFAULT 0,
        total_amount DECIMAL(14,2) DEFAULT 0,
        remaining_credit DECIMAL(14,2) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'open',
        created_by VARCHAR(36),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY (header_id),
        KEY (customer_id),
        KEY (invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$list = $db->fetchAll("
    SELECT h.id, h.txn_number, h.txn_date, h.net_amount, h.status,
           c.full_name as customer_name, inv.txn_number as ref_invoice_no, cm.customer_id,
           cm.status as cm_status, cm.remaining_credit, cm.total_amount as cm_total
    FROM transaction_headers h
    INNER JOIN credit_memos cm ON h.id = cm.header_id
    LEFT JOIN customers c ON cm.customer_id = c.id
    LEFT JOIN transaction_headers inv ON cm.invoice_id = inv.id
    WHERE h.txn_type = 'credit_memo' AND h.is_deleted = 0
    ORDER BY h.created_at DESC
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
        <i class="fas fa-undo-alt" style="color: #0284c7; margin-right: 8px;"></i> Credit Memos
    </h1>
    <a href="?page=transactions/credit_memo/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New</a>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Memo #</th>
                    <th>Customer</th>
                    <th>Ref Invoice #</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): 
                    $st = strtolower($row['cm_status'] ?? $row['status']);
                    $rem = (float)($row['remaining_credit'] ?? 0);
                    $tot = (float)($row['cm_total'] ?? $row['net_amount']);
                    
                    if (in_array($st, ['closed', 'paid', 'fully applied']) || ($rem <= 0.01 && $tot > 0)) {
                        $st_label = 'Fully Applied';
                        $st_color = '#10b981';
                    } elseif (in_array($st, ['partial', 'partially applied'])) {
                        $st_label = 'Partially Applied';
                        $st_color = '#f59e0b';
                    } else {
                        $st_label = 'Open';
                        $st_color = '#0284c7';
                    }
                ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['customer_name'] ?? 'Walk-in Customer'); ?></td>
                    <td><?php echo htmlspecialchars($row['ref_invoice_no'] ?? '-'); ?></td>
                    <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['net_amount'], 2); ?></td>
                    <td>
                        <span style="color: <?php echo $st_color; ?>; font-weight: 600;">
                            <?php echo $st_label; ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <div style="position: relative; display: inline-block;">
                            <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                            </button>
                            <div class="ns-action-dropdown-menu">
                                <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-eye" style="color: #64748b; width: 14px;"></i> View
                                </a>
                                <a href="?page=transactions/credit_memo/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
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
    const menuH = 120; // estimated menu height
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
