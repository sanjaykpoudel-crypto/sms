<?php
require_once 'database/DBConnection.php';
$db = db();
$list = $db->fetchAll("SELECT * FROM transaction_headers WHERE txn_type = 'cash_denomination' AND is_deleted = 0 ORDER BY created_at DESC");
?>
<div class="ns-page-header">
    <h1 class="ns-page-title">
        Cash Denomination Entries
        <a href="?page=transactions/cash_denom/manage" class="ns-btn ns-btn-primary">New Transaction</a>
    </h1>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Entry #</th>
                    <th style="text-align: center;">Total Amount</th>
                    <th>Shift/Counter</th>
                    <th>Created By</th>
                    <th width="80">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo number_format($row['net_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['party_id'] ?: 'Main Counter'); ?></td>
                    <td><?php echo htmlspecialchars($row['created_by']); ?></td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=transactions/cash_denom/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: #c00;" title="Void" onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')"><i class="fas fa-ban"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

            </tbody>
        </table>
    </div>
</div>
