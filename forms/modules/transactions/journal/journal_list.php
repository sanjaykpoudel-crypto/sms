<?php
$db = db();
$list = $db->fetchAll("
    SELECT t.*, 
           (SELECT COALESCE(c.full_name, v.company_name, u.full_name)
            FROM journal_entries je
            LEFT JOIN customers c ON je.party_id = c.id AND je.party_type = 'customer'
            LEFT JOIN vendors v ON je.party_id = v.id AND je.party_type = 'vendor'
            LEFT JOIN users u ON je.party_id = u.id AND je.party_type = 'user'
            WHERE je.header_id = t.id AND je.party_id IS NOT NULL
            LIMIT 1) as party_name,
           u_created.full_name as creator_name
    FROM transaction_headers t
    LEFT JOIN users u_created ON t.created_by = u_created.id
    WHERE t.txn_type IN ('Journal', 'account_transfer') AND t.is_deleted = 0
    ORDER BY t.created_at DESC
");
?>
<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        <i class="fas fa-book" style="color: #d97706; margin-right: 8px;"></i> Journal Entries
    </h1>
    <a href="?page=transactions/journal/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New Transaction</a>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Journal #</th>
                    <th>Name / Entity</th>
                    <th>Reference</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th width="80">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($row['txn_date'])); ?></td>
                    <td style="font-weight: 600; color: #0055aa;"><?php echo htmlspecialchars($row['txn_number']); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['party_name'] ?? '-'); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['reference_number'] ?? $row['ref_number'] ?? '-'); ?></td>
                    <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['net_amount'], 2); ?></td>
                    <td>
                        <span class="badge" style="background: <?php echo $row['status'] == 'posted' ? '#e6fffa' : '#fffaf0'; ?>; color: <?php echo $row['status'] == 'posted' ? '#047481' : '#b7791f'; ?>; border: 1px solid currentColor; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                            <?php echo ucwords($row['status'] ?? 'Draft'); ?>
                        </span>
                    </td>
                    <td style="font-size: 12px;"><?php echo htmlspecialchars($row['creator_name'] ?? $row['created_by']); ?></td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=transactions/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=transactions/journal/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: #c00;" title="Void" onclick="nsDelete('transaction_headers', '<?php echo $row['id']; ?>')"><i class="fas fa-ban"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
