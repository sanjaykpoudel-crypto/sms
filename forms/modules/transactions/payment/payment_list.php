<?php
require_once 'database/DBConnection.php';
$db = db();

$type_filter = $_GET['type'] ?? 'all';
$where_clause = "h.txn_type IN ('customer_payment', 'vendor_payment') AND h.is_deleted = 0";
if ($type_filter === 'customer_payment') {
    $where_clause = "h.txn_type = 'customer_payment' AND h.is_deleted = 0";
} elseif ($type_filter === 'vendor_payment') {
    $where_clause = "h.txn_type = 'vendor_payment' AND h.is_deleted = 0";
}

// Query to get all payments with their total amounts and party info
$sql = "
    SELECT 
        h.id, 
        h.txn_date, 
        h.txn_number, 
        h.txn_type,
        COALESCE(NULLIF(SUM(p.amount), 0), NULLIF(h.net_amount, 0), 0) as total_amount,
        GROUP_CONCAT(DISTINCT COALESCE(acc.account_name, p.payment_method) SEPARATOR ', ') as methods,
        MAX(COALESCE(c.full_name, v.company_name)) as party_name,
        h.created_by,
        GROUP_CONCAT(DISTINCT COALESCE(ci.invoice_number, vb.vendor_invoice_number) ORDER BY COALESCE(ci.invoice_number, vb.vendor_invoice_number) SEPARATOR ', ') as applied_refs
    FROM transaction_headers h
    LEFT JOIN payments p ON h.id = p.header_id
    LEFT JOIN accounts acc ON p.bank_account_id = acc.id
    LEFT JOIN customers c ON p.customer_id = c.id
    LEFT JOIN vendors v ON p.vendor_id = v.id
    LEFT JOIN transaction_links tl ON tl.parent_id = h.id
    LEFT JOIN customer_invoices ci ON tl.child_id = ci.header_id
    LEFT JOIN vendor_bills vb ON tl.child_id = vb.header_id
    WHERE {$where_clause}
    GROUP BY h.id
    ORDER BY h.created_at DESC
";

$list = $db->fetchAll($sql);
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
        <i class="fas fa-money-bill-wave" style="color: #3b82f6; margin-right: 8px;"></i> Payments
    </h1>
    <a href="?page=transactions/payment/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New</a>
</div>

<div class="ns-portlet" style="margin-bottom: 8px; overflow: visible !important;">
    <div class="ns-portlet-content" style="padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border-radius: 8px; overflow: visible !important;">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <span style="font-weight: 600; color: #475569; font-size: 13px;"><i class="fas fa-filter" style="color: #3b82f6;"></i> Filter Type:</span>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="?page=transactions/payment&type=all" class="ns-btn <?php echo $type_filter === 'all' ? 'ns-btn-primary' : ''; ?>" style="font-size: 12px; padding: 6px 12px;">All Payments</a>
                <a href="?page=transactions/payment&type=customer_payment" class="ns-btn <?php echo $type_filter === 'customer_payment' ? 'ns-btn-primary' : ''; ?>" style="font-size: 12px; padding: 6px 12px;"><i class="fas fa-arrow-down" style="color: <?php echo $type_filter === 'customer_payment' ? '#fff' : '#080'; ?>; margin-right: 4px;"></i> Customer Payments (Money In)</a>
                <a href="?page=transactions/payment&type=vendor_payment" class="ns-btn <?php echo $type_filter === 'vendor_payment' ? 'ns-btn-primary' : ''; ?>" style="font-size: 12px; padding: 6px 12px;"><i class="fas fa-arrow-up" style="color: <?php echo $type_filter === 'vendor_payment' ? '#fff' : '#c00'; ?>; margin-right: 4px;"></i> Vendor Payments (Money Out)</a>
            </div>
        </div>
    </div>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Payment #</th>
                    <th>Type</th>
                    <th>Party</th>
                    <th>Methods</th>
                    <th>Applied Invoices / Bills</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Created By</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?></td>
                    <td>
                        <span style="color: <?php echo $row['txn_type'] == 'customer_payment' ? '#080' : '#c00'; ?>; font-weight: 600;">
                            <?php echo $row['txn_type'] == 'customer_payment' ? 'Money In' : 'Money Out'; ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($row['party_name'] ?? 'N/A'); ?></td>
                    <td><small><?php echo htmlspecialchars($row['methods'] ?? 'N/A'); ?></small></td>
                    <td style="font-size: 12px; color: #475569;"><?php echo htmlspecialchars($row['applied_refs'] ?: '-'); ?></td>
                    <td style="text-align: right; font-weight: bold;"><?php echo number_format($row['total_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['created_by']); ?></td>
                    <td style="text-align: center;">
                        <div style="position: relative; display: inline-block;">
                            <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                            </button>
                            <div class="ns-action-dropdown-menu">
                                <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-eye" style="color: #64748b; width: 14px;"></i> View
                                </a>
                                <a href="?page=transactions/payment/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
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
