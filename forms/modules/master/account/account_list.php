<?php
require_once 'database/DBConnection.php';
$db = db();

$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$status_filter = $show_all ? "" : " AND a.is_active = 1 ";

$dp = 2;
try {
    $dp_row = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'decimal_places'");
    if ($dp_row && isset($dp_row['meta_value'])) {
        $dp = (int) $dp_row['meta_value'];
    }
} catch (Exception $e) {
}

$accounts = $db->fetchAll("
    SELECT 
        a.*,
        COALESCE(atm.AccountTypeName, a.account_subtype) as display_account_type,
        COALESCE(atm.Category, a.account_type) as display_category,
        COALESCE(atm.NormalBalance, a.normal_balance) as display_normal_balance,
        COALESCE(
            SUM(
                CASE 
                    WHEN h.id IS NOT NULL THEN
                        CASE 
                            WHEN LOWER(COALESCE(atm.NormalBalance, a.normal_balance)) = 'debit' THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END)
                            ELSE (CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END)
                        END
                    ELSE 0
                END
            ),
            0
        ) as balance
    FROM accounts a
    LEFT JOIN AccountTypeMaster atm ON a.account_type_id = atm.AccountTypeId
    LEFT JOIN journal_entries j ON a.id = j.account_id
    LEFT JOIN transaction_headers h ON j.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    WHERE a.is_deleted = 0 $status_filter
    GROUP BY a.id, atm.AccountTypeId
    ORDER BY a.account_name ASC
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
    .ns-ledger-link {
        color: var(--ns-primary);
        font-weight: 700;
        text-decoration: none;
    }

    .ns-ledger-link:hover {
        text-decoration: underline;
        opacity: 0.8;
    }
</style>

<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        Chart of Accounts
    </h1>
    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="?page=master/account/manage" class="ns-btn ns-btn-primary"
            style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i
                class="fas fa-plus"></i> New</a>
        <a href="?page=master/account/opening_balance" class="ns-btn ns-btn-secondary"
            style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i
                class="fas fa-balance-scale"></i> Bank Opening Balances</a>
    </div>
</div>

<div style="display: none;">
    <label id="inactive-filter-container"
        style="margin-left: 15px; font-size: 12px; font-weight: normal; color: #333; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; vertical-align: middle;">
        <input type="checkbox" id="show-inactive-checkbox" <?php echo $show_all ? 'checked' : ''; ?>
            onchange="toggleStatusFilter(this.checked)"
            style="cursor: pointer; margin: 0; width: 13px; height: 13px; vertical-align: middle;">
        Inactive
    </label>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th width="40" style="text-align: center;">#</th>
                    <th>Account Name</th>
                    <th>Account Type</th>
                    <th>Category</th>
                    <th>Normal Balance</th>
                    <th style="text-align: right;">Balance</th>
                    <th>Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1;
                foreach ($accounts as $row): ?>
                    <tr>
                        <td style="text-align: center; color: #888; font-weight: 600;"><?php echo $sn++; ?></td>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($row['account_name']); ?></td>
                        <td><span class="ns-badge" style="background: #e2e8f0; color: #1e293b; font-weight: 600; border-radius: 4px; padding: 2px 8px; font-size: 11px;"><?php echo htmlspecialchars($row['display_account_type'] ?? 'Other'); ?></span></td>
                        <td><?php echo htmlspecialchars(ucfirst($row['display_category'] ?? '')); ?></td>
                        <td><span style="font-weight: 600; color: <?php echo strtolower($row['display_normal_balance'] ?? '') === 'debit' ? '#0284c7' : '#059669'; ?>;"><?php echo htmlspecialchars(ucfirst($row['display_normal_balance'] ?? '')); ?></span></td>
                        <td style="text-align: right;">
                            <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($row['id']); ?>&date_from=1970-01-01"
                                class="ns-ledger-link">
                                Rs. <?php echo number_format($row['balance'], $dp); ?>
                            </a>
                        </td>
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
                                    <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($row['id']); ?>&date_from=1970-01-01" class="ns-action-item">
                                        <i class="fas fa-book" style="color: #64748b; width: 14px;"></i> General Ledger
                                    </a>
                                    <a href="?page=master/account/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                        <i class="fas fa-edit" style="color: #0284c7; width: 14px;"></i> Edit
                                    </a>
                                    <div style="height: 1px; background: #e2e8f0; margin: 4px 0;"></div>
                                    <a href="javascript:void(0)" onclick="nsDelete('accounts', '<?php echo $row['id']; ?>')" class="ns-action-item danger">
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