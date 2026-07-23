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
        COALESCE(h.net_amount, SUM(p.amount), 0) as total_amount,
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
<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-money-bill-wave" style="color: #3b82f6; margin-right: 8px;"></i> Payments
    </h1>
    <a href="?page=transactions/payment/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New Transaction</a>
</div>

<div class="ns-portlet" style="margin-bottom: 8px;">
    <div class="ns-portlet-content" style="padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border-radius: 8px;">
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

<div class="ns-portlet">
    <div class="ns-portlet-content">
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
                    <th width="80">Actions</th>
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
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=transactions/payment/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: #c00;" title="Void" onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')"><i class="fas fa-ban"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
