<?php
require_once 'database/DBConnection.php';
$db = db();

// Ensure vendor_credits table exists
$db->execute("
    CREATE TABLE IF NOT EXISTS vendor_credits (
        id VARCHAR(36) PRIMARY KEY,
        header_id VARCHAR(36) NOT NULL,
        vendor_id VARCHAR(36) NOT NULL,
        credit_number VARCHAR(50) NOT NULL,
        credit_date DATE NOT NULL,
        bill_id VARCHAR(36) NULL,
        deduct_from_stock TINYINT(1) DEFAULT 1,
        subtotal DECIMAL(14,2) DEFAULT 0,
        tax_amount DECIMAL(14,2) DEFAULT 0,
        total_amount DECIMAL(14,2) DEFAULT 0,
        remaining_credit DECIMAL(14,2) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'open',
        created_by VARCHAR(36),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY (header_id),
        KEY (vendor_id),
        KEY (bill_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$list = $db->fetchAll("
    SELECT h.id, h.txn_number, h.txn_date, h.net_amount, h.status,
           v.company_name as vendor_name, bill.txn_number as ref_bill_no, vc.vendor_id,
           vc.status as vc_status, vc.remaining_credit, vc.total_amount as vc_total
    FROM transaction_headers h
    INNER JOIN vendor_credits vc ON h.id = vc.header_id
    LEFT JOIN vendors v ON vc.vendor_id = v.id
    LEFT JOIN transaction_headers bill ON vc.bill_id = bill.id
    WHERE h.txn_type IN ('vendor_credit', 'bill_credit') AND h.is_deleted = 0
    ORDER BY h.created_at DESC
");
?>

<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-undo-alt" style="color: #0284c7; margin-right: 8px;"></i> Vendor Credits
    </h1>
    <a href="?page=transactions/bill_credit/manage" class="ns-btn ns-btn-primary"
        style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i
            class="fas fa-plus"></i> New</a>
</div>

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Credit #</th>
                    <th>Vendor</th>
                    <th>Ref Bill #</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row):
                    $st = strtolower($row['vc_status'] ?? $row['status']);
                    $rem = (float) ($row['remaining_credit'] ?? 0);
                    $tot = (float) ($row['vc_total'] ?? $row['net_amount']);

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
                        <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['vendor_name'] ?? 'Vendor'); ?></td>
                        <td><?php echo htmlspecialchars($row['ref_bill_no'] ?? '-'); ?></td>
                        <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['net_amount'], 2); ?>
                        </td>
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
                                    <a href="?page=transactions/bill_credit/manage&id=<?php echo $row['id']; ?>"
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
