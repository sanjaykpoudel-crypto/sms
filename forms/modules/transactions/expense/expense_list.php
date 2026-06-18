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
<div class="ns-page-header">
    <h1 class="ns-page-title">
        Expenses
        <a href="?page=transactions/expense/manage" class="ns-btn ns-btn-primary">New Transaction</a>
    </h1>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Expense #</th>
                    <th>Payee</th>
                    <th>Expense Account</th>
                    <th>Paid From</th>
                    <th>Amount</th>
                    <th>Category</th>
                    <th width="80">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;">
                        <a href="?page=transactions/view&id=<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['txn_number']); ?></a>
                    </td>
                    <td><?php echo htmlspecialchars($row['party_id']); ?></td>
                    <td><?php echo htmlspecialchars($row['exp_account'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($row['paid_account'] ?? 'N/A'); ?></td>
                    <td style="text-align: right; color: #c00; font-weight: 600;">Rs. <?php echo number_format($row['net_amount'], 2); ?></td>
                    <td><span class="badge" style="background: #f1f5f9; color: #475569; padding: 2px 6px; border-radius: 4px; font-size: 11px;"><?php echo ucfirst($row['expense_category'] ?? 'other'); ?></span></td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=transactions/expense/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: #c00;" title="Void" onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')"><i class="fas fa-ban"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Date</th>
                    <th>Expense #</th>
                    <th>Payee</th>
                    <th>Expense Account</th>
                    <th>Paid From</th>
                    <th>Amount</th>
                    <th>Category</th>
                    <th>Actions</th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
