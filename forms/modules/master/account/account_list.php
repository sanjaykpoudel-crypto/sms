<?php
require_once 'database/DBConnection.php';
$db = db();

$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$status_filter = $show_all ? "" : " AND a.is_active = 1 ";

$dp = 2;
try {
    $dp_row = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'decimal_places'");
    if ($dp_row && isset($dp_row['meta_value'])) {
        $dp = (int)$dp_row['meta_value'];
    }
} catch (Exception $e) {}

$accounts = $db->fetchAll("
    SELECT 
        a.*,
        COALESCE(
            SUM(
                CASE 
                    WHEN h.id IS NOT NULL THEN
                        CASE 
                            WHEN a.normal_balance = 'debit' THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END)
                            ELSE (CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END)
                        END
                    ELSE 0
                END
            ),
            0
        ) as balance
    FROM accounts a
    LEFT JOIN journal_entries j ON a.id = j.account_id
    LEFT JOIN transaction_headers h ON j.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
    WHERE a.is_deleted = 0 $status_filter
    GROUP BY a.id
    ORDER BY a.updated_at DESC
");
?>
<style>
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

<div class="ns-page-header">
    <h1 class="ns-page-title">
        Chart of Accounts
        <a href="?page=master/account/manage" class="ns-btn ns-btn-primary">New Account</a>
        <a href="?page=master/account/opening_balance" class="ns-btn ns-btn-secondary"><i class="fas fa-balance-scale"></i> Bank Opening Balances</a>
    </h1>
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
                    <th>Account Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Subtype</th>
                    <th>Normal Balance</th>
                    <th style="text-align: right;">Balance</th>
                    <th>Status</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $row): ?>
                <tr>
                    <td style="font-weight: 600;"><?php echo htmlspecialchars($row['account_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['account_name']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['account_type'])); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['account_subtype'])); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['normal_balance'])); ?></td>
                    <td style="text-align: right;">
                        <a href="?page=reports/financial/general_ledger&account_id=<?php echo urlencode($row['id']); ?>&date_from=1970-01-01" class="ns-ledger-link">
                            Rs. <?php echo number_format($row['balance'], $dp); ?>
                        </a>
                    </td>
                    <td>
                        <span style="color: <?php echo $row['is_active'] ? '#080' : '#c00'; ?>">
                            <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=master/account/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: #c00;" title="Delete" onclick="nsDelete('accounts', '<?php echo $row['id']; ?>')"><i class="fas fa-trash"></i></button>
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
