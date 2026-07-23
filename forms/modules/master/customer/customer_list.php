<?php
require_once 'database/DBConnection.php';
$db = db();

$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$status_filter = $show_all ? "" : " AND c.is_active = 1 ";

$customers = $db->fetchAll(
    "
    SELECT c.*, 
    ((
        SELECT COALESCE(SUM(ci.total_amount), 0) 
        FROM customer_invoices ci 
        JOIN transaction_headers th ON ci.header_id = th.id 
        WHERE ci.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) + (
        SELECT COALESCE(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END), 0)
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (j.party_id = c.id OR th.party_id = c.id) AND (j.party_type = 'customer' OR j.party_type IS NULL) AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    )) AS total_sales,
    (
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id 
        WHERE p.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) AS total_paid,
    ((
        SELECT COALESCE(SUM(ci.balance_due), 0) 
        FROM customer_invoices ci 
        JOIN transaction_headers th ON ci.header_id = th.id 
        WHERE ci.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) + (
        SELECT COALESCE(SUM(CASE WHEN j.entry_type='debit' THEN j.amount ELSE -j.amount END), 0) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0)
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        LEFT JOIN transaction_links tl ON tl.child_id = th.id AND tl.link_type LIKE 'payment:%'
        WHERE (j.party_id = c.id OR th.party_id = c.id) AND (j.party_type = 'customer' OR j.party_type IS NULL) AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    )) AS total_due
    FROM customers c 
    WHERE c.is_deleted = 0 $status_filter
    ORDER BY c.full_name ASC
"
);
?>
<div class="ns-page-header" style="display: flex; align-items: center; gap: 15px;">
    <h1 class="ns-page-title" style="margin: 0; font-size: 20px; font-weight: 800;">
        Customers
    </h1>
    <a href="?page=master/customer/manage" class="ns-btn ns-btn-primary" style="padding: 4px 10px; font-size: 11px; height: 26px; display: inline-flex; align-items: center;"><i class="fas fa-plus"></i> New</a>
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
                    <th width="50" style="text-align: center;">#</th>
                    <th>Full Name</th>
                    <th>Type</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th style="text-align: center;">Total Sales</th>
                    <th style="text-align: center;">Total Paid</th>
                    <th style="text-align: center;">Remaining Amount</th>
                    <th>Status</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1; foreach ($customers as $row): 
                    $remaining = $row['total_due'];
                ?>
                <tr>
                    <td style="text-align: center; color: #888; font-weight: 600;"><?php echo $sn++; ?></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['customer_type'])); ?></td>
                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td style="text-align: center; font-weight: 600; color: #080;">Rs <?php echo number_format($row['total_sales'], 2); ?></td>
                    <td style="text-align: center; color: #2563eb;">Rs <?php echo number_format($row['total_paid'], 2); ?></td>
                    <td style="text-align: center; font-weight: 600; color: <?php echo $remaining > 0 ? '#c00' : '#333'; ?>">Rs <?php echo number_format($remaining, 2); ?></td>
                    <td>
                        <span style="color: <?php echo $row['is_active'] ? '#080' : '#c00'; ?>">
                            <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="?page=master/customer/view&id=<?php echo $row['id']; ?>" class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=master/customer/manage&id=<?php echo $row['id']; ?>" class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color: #c00;" title="Delete" onclick="nsDelete('customers', '<?php echo $row['id']; ?>')"><i class="fas fa-trash"></i></button>
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
