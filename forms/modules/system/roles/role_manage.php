<?php
require_once 'database/DBConnection.php';
$db = db();

$id = $_GET['id'] ?? null;
$data = [];
$permissions = [];

if ($id) {
    $data = $db->fetchOne("SELECT * FROM roles WHERE id = ?", [$id]);
    if ($data && !empty($data['permissions'])) {
        $permissions = json_decode($data['permissions'], true) ?: [];
    }
}

// Helper to check permission state: 'allow' (default for new admin/manager), 'readonly', 'hide'
function get_perm($perms, $key, $default = 'allow') {
    return $perms[$key] ?? $default;
}

// Master Menu Items Array
$menu_sections = [
    'transactions' => [
        'title' => 'Transactions & Operational Entries',
        'icon' => 'fas fa-exchange-alt',
        'color' => '#0284c7',
        'items' => [
            'pos'                => 'POS — Point of Sale Counter Checkout',
            'invoice'            => 'Sales Invoices (Customer Invoices & Quotations)',
            'credit_memo'        => 'Credit Memos & Sales Returns',
            'bill'               => 'Vendor Bills (Purchase Invoices)',
            'bill_credit'        => 'Vendor Credit / Debit Memos (Purchase Returns)',
            'payment'            => 'Payments (Customer Receipts & Vendor Payments)',
            'expense'            => 'Expense Entry',
            'journal'            => 'Journal Entries (Manual JV)',
            'transfer'           => 'Bank / Fund Transfers',
            'cash_denom'         => 'Cash Denominations (Till Count)',
            'adjustment'         => 'Inventory Stock Adjustments',
            'inventory_transfer' => 'Inventory Location Transfers',
        ]
    ],
    'master_lists' => [
        'title' => 'Master Lists & Entities',
        'icon' => 'fas fa-list',
        'color' => '#7c3aed',
        'items' => [
            'customers'       => 'Customer Master List',
            'vendors'         => 'Vendor / Supplier Master List',
            'items'           => 'Item & Inventory Catalog',
            'accounts'        => 'Chart of Accounts',
            'opening_balances'=> 'Bank & Account Opening Balances',
            'users'           => 'Employees & System User Accounts',
        ]
    ],
    'reports' => [
        'title' => 'Reports & Financial Insights',
        'icon' => 'fas fa-chart-bar',
        'color' => '#059669',
        'items' => [
            'rep_financial'  => 'Financial Statements (Balance Sheet, Income Statement, Trial Balance, General Ledger, Cash Book, Equity)',
            'rep_sales'      => 'Sales Reports (By Item, By Customer, Sales Register, Top Profit Items, Open Invoices)',
            'rep_purchases'  => 'Purchase Reports (By Item, By Vendor)',
            'rep_inventory'  => 'Inventory Reports (Valuation, Stock Snapshot, Stock Ledger, Revenue, Profitability, Low Stock, Urgent Buy)',
            'rep_vat'        => 'VAT / Tax Reports (Abbreviated Tax Invoice, VAT Sales Register, VAT Purchase Register)',
            'rep_vendors'    => 'Vendor Reports (Balance Confirmation, AP Register, Open Bills, AP Aging, AP Payments)',
            'rep_customers'  => 'Customer Reports (Balance Confirmation, Statement, AR Register, Open Invoices, AR Aging)',
            'rep_pos'        => 'POS Summary & Daily Cash Reports',
            'rep_insights'   => 'General Insights (Break-Even Tracker, Investment Payback, Profitability)',
        ]
    ],
    'setup_settings' => [
        'title' => 'Setup & System Administration',
        'icon' => 'fas fa-cogs',
        'color' => '#dc2626',
        'items' => [
            'company_info'    => 'System & Company Information',
            'roles_perm'      => 'Role Permissions & Access Control',
            'fiscal_years'    => 'Accounting Periods & Fiscal Year Closing',
            'accounting_prefs'=> 'Accounting Preferences & Default System Accounts',
            'whatsapp'        => 'WhatsApp Integration Settings',
            'ref_codes'       => 'Auto-Generated Reference Numbers & Prefixes',
            'import_export'   => 'Import / Export Data (Excel, CSV)',
            'backup_restore'  => 'Backup & Restore Database',
        ]
    ],
    'activity' => [
        'title' => 'Activity & Task Management',
        'icon' => 'fas fa-calendar-alt',
        'color' => '#f59e0b',
        'items' => [
            'activity'  => 'Activities, Tasks & Calendar',
        ]
    ],
];

// --- Dynamic Auto-Discovery of New Transaction, Master & Report Modules ---
$base_mod_dir = dirname(__DIR__, 2) . '/'; // forms/modules/

// 1. Auto-discover any newly created Transaction subdirectories
$tx_path = realpath($base_mod_dir . 'transactions');
if ($tx_path && is_dir($tx_path)) {
    foreach (scandir($tx_path) as $folder) {
        if ($folder === '.' || $folder === '..' || !is_dir($tx_path . '/' . $folder)) continue;
        if (!isset($menu_sections['transactions']['items'][$folder])) {
            $label = ucwords(str_replace(['_', '-'], ' ', $folder));
            $menu_sections['transactions']['items'][$folder] = "{$label} (Auto-Discovered Transaction)";
        }
    }
}

// 2. Auto-discover any newly created Master subdirectories
$m_path = realpath($base_mod_dir . 'master');
if ($m_path && is_dir($m_path)) {
    foreach (scandir($m_path) as $folder) {
        if ($folder === '.' || $folder === '..' || !is_dir($m_path . '/' . $folder)) continue;
        $m_key = $folder;
        if (!isset($menu_sections['master_lists']['items'][$m_key]) && !isset($menu_sections['master_lists']['items'][$m_key . 's'])) {
            $label = ucwords(str_replace(['_', '-'], ' ', $folder));
            $menu_sections['master_lists']['items'][$m_key] = "{$label} Master (Auto-Discovered)";
        }
    }
}

// 3. Auto-discover any newly created Report subdirectories / standalone report files
$r_path = realpath($base_mod_dir . 'reports');
if ($r_path && is_dir($r_path)) {
    foreach (scandir($r_path) as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $r_path . '/' . $item;
        if (is_dir($full)) {
            $rep_key = 'rep_' . $item;
            if (!isset($menu_sections['reports']['items'][$rep_key])) {
                $label = ucwords(str_replace(['_', '-'], ' ', $item));
                $menu_sections['reports']['items'][$rep_key] = "{$label} Reports (Auto-Discovered Category)";
            }
        } elseif (is_file($full) && strpos($item, '.php') !== false && !in_array($item, ['reports_list.php', 'rpt_helpers.php', 'pos_summary.php'])) {
            $clean = str_replace(['.php', '_list'], '', $item);
            $single_key = 'rep_' . $clean;
            if (!isset($menu_sections['reports']['items'][$single_key])) {
                $label = ucwords(str_replace(['_', '-'], ' ', $clean));
                $menu_sections['reports']['items'][$single_key] = "{$label} Report (Auto-Discovered)";
            }
        }
    }
}
?>

<style>
    .perm-card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .perm-header { background: #f8fafc; padding: 12px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
    .perm-header-title { font-weight: 700; color: #1e293b; font-size: 14px; display: flex; align-items: center; gap: 8px; }
    .perm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .perm-table th { background: #f1f5f9; padding: 8px 15px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; }
    .perm-table td { padding: 10px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .perm-table tr:hover { background: #fafafa; }
    .perm-radio-group { display: flex; gap: 15px; align-items: center; }
    .perm-radio-label { font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 5px; font-weight: 600; }
    .perm-radio-label.allow { color: #166534; }
    .perm-radio-label.readonly { color: #854d0e; }
    .perm-radio-label.hide { color: #991b1b; }
    .bulk-btn { background: #e2e8f0; border: none; padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; cursor: pointer; color: #475569; transition: all 0.2s; }
    .bulk-btn:hover { background: #cbd5e1; }
</style>

<div class="ns-form-header">
    <div class="ns-form-title">
        <i class="fas fa-user-shield" style="margin-right: 10px; color: var(--ns-primary);"></i>
        <?php echo $id ? 'Edit Role & Permissions' : 'Create New Role'; ?>
    </div>
    <div class="ns-page-actions">
        <button type="submit" form="role-form" class="ns-btn ns-btn-primary"><i class="fas fa-save"></i> Save Role</button>
        <?php if ($id && empty($data['is_system'])): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDelete('roles', '<?php echo $id; ?>', function() { window.location.href = '?page=system/roles/role_list'; })"><i class="fas fa-trash-alt"></i> Delete</button>
        <?php endif; ?>
        <a href="?page=system/roles" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="role-form" method="POST" action="api/save_role.php">
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="ns-portlet" style="margin-bottom: 20px;">
            <div class="ns-portlet-content">
                <div class="ns-section-title">Role Information</div>
                <div class="ns-form-row">
                    <div style="flex: 1;">
                        <div class="ns-form-group">
                            <label class="ns-label">Role Name *</label>
                            <input type="text" name="role_name" class="ns-input" value="<?php echo htmlspecialchars($data['role_name'] ?? ''); ?>" required placeholder="e.g. Senior Accountant, Sales Manager">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Role Code</label>
                            <input type="text" name="role_code" class="ns-input" value="<?php echo htmlspecialchars($data['role_code'] ?? ''); ?>" placeholder="e.g. senior_accountant (Auto-generated if blank)" <?php echo (!empty($data['is_system'])) ? 'readonly style="background:#f8fafc;"' : ''; ?>>
                        </div>
                    </div>
                    <div style="flex: 1.5;">
                        <div class="ns-form-group">
                            <label class="ns-label">Description</label>
                            <textarea name="description" class="ns-input" style="height: 40px;" placeholder="Brief description of the responsibilities and access scope of this role..."><?php echo htmlspecialchars($data['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="ns-form-row">
                            <div style="flex: 1;">
                                <div class="ns-form-group">
                                    <label class="ns-label">General Access Level</label>
                                    <select name="access_level" class="ns-select">
                                        <option value="custom" <?php echo (($data['access_level'] ?? '') == 'custom') ? 'selected' : ''; ?>>Custom Matrix Access</option>
                                        <option value="full" <?php echo (($data['access_level'] ?? '') == 'full') ? 'selected' : ''; ?>>Full System Access</option>
                                        <option value="readonly" <?php echo (($data['access_level'] ?? '') == 'readonly') ? 'selected' : ''; ?>>Read-Only Access</option>
                                    </select>
                                </div>
                            </div>
                            <div style="flex: 0.5;">
                                <div class="ns-form-group">
                                    <label class="ns-label" style="display: block;">Inactive</label>
                                    <input type="checkbox" name="is_inactive" <?php echo (isset($data['is_active']) && $data['is_active'] == 0) ? 'checked' : ''; ?> style="width: 18px; height: 18px; cursor: pointer;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
            <div style="font-weight: 700; font-size: 15px; color: #1e293b;">
                <i class="fas fa-sliders-h" style="margin-right: 8px; color: var(--ns-primary);"></i> Menu Items & Feature Access Matrix
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="bulk-btn" onclick="setAllPermissions('allow')"><i class="fas fa-check-circle" style="color:#166534;"></i> Allow All</button>
                <button type="button" class="bulk-btn" onclick="setAllPermissions('readonly')"><i class="fas fa-eye" style="color:#854d0e;"></i> Read-Only All</button>
                <button type="button" class="bulk-btn" onclick="setAllPermissions('hide')"><i class="fas fa-ban" style="color:#991b1b;"></i> Hide All</button>
            </div>
        </div>

        <!-- Permissions Matrix Sections -->
        <?php foreach ($menu_sections as $sec_key => $sec): ?>
            <div class="perm-card">
                <div class="perm-header">
                    <div class="perm-header-title">
                        <i class="<?php echo $sec['icon']; ?>" style="color: <?php echo $sec['color'] ?? 'var(--ns-primary)'; ?>;"></i>
                        <?php echo $sec['title']; ?>
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="bulk-btn" onclick="setSectionPermissions('<?php echo $sec_key; ?>', 'allow')">Allow Section</button>
                        <button type="button" class="bulk-btn" onclick="setSectionPermissions('<?php echo $sec_key; ?>', 'hide')">Hide Section</button>
                    </div>
                </div>
                <table class="perm-table" id="section-<?php echo $sec_key; ?>">
                    <thead>
                        <tr>
                            <th>Menu Item / Feature</th>
                            <th width="350" style="text-align: right;">Access Permission</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sec['items'] as $item_key => $item_label): 
                            $curr_perm = get_perm($permissions, $item_key, (($data['role_code'] ?? '') === 'admin' ? 'allow' : 'allow'));
                        ?>
                        <tr>
                            <td style="font-weight: 600; color: #334155;"><?php echo htmlspecialchars($item_label); ?></td>
                            <td style="text-align: right;">
                                <div class="perm-radio-group" style="justify-content: flex-end;">
                                    <label class="perm-radio-label allow">
                                        <input type="radio" name="permissions[<?php echo $item_key; ?>]" value="allow" <?php echo $curr_perm === 'allow' ? 'checked' : ''; ?>> Show / Full
                                    </label>
                                    <label class="perm-radio-label readonly">
                                        <input type="radio" name="permissions[<?php echo $item_key; ?>]" value="readonly" <?php echo $curr_perm === 'readonly' ? 'checked' : ''; ?>> View Only
                                    </label>
                                    <label class="perm-radio-label hide">
                                        <input type="radio" name="permissions[<?php echo $item_key; ?>]" value="hide" <?php echo $curr_perm === 'hide' ? 'checked' : ''; ?>> Hide
                                    </label>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

    </form>
</div>

<script>
function setSectionPermissions(sectionKey, val) {
    const section = document.getElementById('section-' + sectionKey);
    if (!section) return;
    section.querySelectorAll(`input[type="radio"][value="${val}"]`).forEach(radio => {
        radio.checked = true;
    });
}

function setAllPermissions(val) {
    document.querySelectorAll(`input[type="radio"][value="${val}"]`).forEach(radio => {
        radio.checked = true;
    });
}

document.getElementById('role-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const btn = document.querySelector('button[form="role-form"]') || form.querySelector('button[type="submit"]');
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    }

    const formData = new FormData(form);

    fetch('api/save_role.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
        if (res.status === 'success') {
            if (typeof showNotification === 'function') {
                showNotification(res.message, 'success');
            } else {
                alert(res.message);
            }
            setTimeout(() => {
                window.location.href = '?page=system/roles';
            }, 800);
        } else {
            alert(res.message || 'Error saving role');
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
        alert('An error occurred while saving role.');
    });
});
</script>
