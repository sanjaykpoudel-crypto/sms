<?php
require_once 'database/DBConnection.php';
$db = db();

$sql = "SELECT p.*, c.full_name as customer_name, u.full_name as user_name 
        FROM pos_entry p
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.is_deleted = 0 AND (p.invoice_no IS NULL OR p.invoice_no = '')
        ORDER BY p.created_at DESC";
$list = $db->fetchAll($sql);
?>
<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-cash-register" style="color: #0284c7; margin-right: 8px;"></i> POS Transactions
    </h1>
    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="?page=transactions/pos/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New</a>
        <a href="?page=reports/pos_summary" class="ns-btn" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-chart-line"></i> Daily Summary</a>
    </div>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table datatable">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th style="text-align: right;">Gross</th>
                    <th style="text-align: right;">Discount</th>
                    <th style="text-align: right;">VAT</th>
                    <th style="text-align: right;">Net Amount</th>
                    <th>Status</th>
                    <th>Cashier</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): 
                    $status_color = '#2ecc71'; // completed
                    if($row['status'] == 'returned') $status_color = '#e67e22';
                    if($row['status'] == 'voided') $status_color = '#e74c3c';
                ?>
                <tr>
                    <td style="white-space: nowrap;"><?php echo date('M d, Y H:i', strtotime($row['date_time'])); ?></td>
                    <td style="font-weight: 700; color: var(--ns-primary);"><?php echo htmlspecialchars($row['invoice_no']); ?></td>
                    <td><?php echo htmlspecialchars($row['customer_name'] ?: 'Walk-in Customer'); ?></td>
                    <td style="text-align: right;"><?php echo number_format($row['gross_amount'], 2); ?></td>
                    <td style="text-align: right; color: #e74c3c;"><?php echo number_format($row['discount_amount'], 2); ?></td>
                    <td style="text-align: right;"><?php echo number_format($row['tax_amount'], 2); ?></td>
                    <td style="text-align: right; font-weight: 700;">Rs <?php echo number_format($row['net_amount'], 2); ?></td>
                    <td>
                        <span class="badge" style="background: <?php echo $status_color; ?>; color: white; padding: 4px 8px; border-radius: 4px; font-size: 10px; text-transform: uppercase;">
                            <?php echo $row['status']; ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=transactions/pos/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View Details"><i class="fas fa-eye"></i></a>
                            <a href="api/print_pos.php?id=<?php echo $row['id']; ?>" target="_blank" class="ns-btn" title="Print Receipt"><i class="fas fa-print"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.datatable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25
    });
});
</script>
