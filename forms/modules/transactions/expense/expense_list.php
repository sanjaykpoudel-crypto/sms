<?php
require_once 'database/DBConnection.php';
$db = db();
$list = $db->fetchAll("
    SELECT h.*, e.expense_account_id, a_exp.account_name as exp_account, a_paid.account_name as paid_account, e.expense_category
    FROM transaction_headers h
    LEFT JOIN expenses e ON h.id = e.header_id
    LEFT JOIN accounts a_exp ON e.expense_account_id = a_exp.id
    LEFT JOIN accounts a_paid ON e.paid_from_account_id = a_paid.id
    WHERE h.txn_type = 'expense' AND h.is_deleted = 0
    ORDER BY h.created_at DESC
");
?>

<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-wallet" style="color: #ef4444; margin-right: 8px;"></i> Expenses
    </h1>
    <a href="?page=transactions/expense/manage" class="ns-btn ns-btn-primary"
        style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i
            class="fas fa-plus"></i> New</a>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Expense #</th>
                    <th>Payee</th>
                    <th>Expense Account</th>
                    <th>Paid From</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Category</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): ?>
                    <tr>
                        <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                        <td style="font-weight: 600; color: #0055aa;">
                            <a
                                href="?page=transactions/view&id=<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['txn_number']); ?></a>
                        </td>
                        <td><?php echo htmlspecialchars($row['party_id']); ?></td>
                        <td><?php echo htmlspecialchars($row['exp_account'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($row['paid_account'] ?? 'N/A'); ?></td>
                        <td style="text-align: right; color: #c00; font-weight: 600;">Rs.
                            <?php echo number_format($row['net_amount'], 2); ?>
                        </td>
                        <td><span class="badge"
                                style="background: #f1f5f9; color: #475569; padding: 2px 6px; border-radius: 4px; font-size: 11px;"><?php echo ucfirst($row['expense_category'] ?? 'other'); ?></span>
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
                                    <a href="?page=transactions/expense/manage&id=<?php echo $row['id']; ?>"
                                        class="ns-action-item">
                                        <i class="fas fa-edit" style="color: #0284c7; width: 14px;"></i> Edit
                                    </a>
                                    <div style="height: 1px; background: #e2e8f0; margin: 4px 0;"></div>
                                    <a href="javascript:void(0)"
                                        onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')"
                                        class="ns-action-item danger">
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
