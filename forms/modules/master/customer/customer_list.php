<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$status_filter = $show_all ? "" : " AND c.is_active = 1 ";

$customers = $db->fetchAll(
    "
    SELECT c.*, loc.name as location_name,
    ((
        SELECT COALESCE(SUM(ci.total_amount), 0) 
        FROM customer_invoices ci 
        JOIN transaction_headers th ON ci.header_id = th.id 
        WHERE ci.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ) + (
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN transaction_headers th ON je.transaction_id = th.id
        WHERE jl.entity_id = c.id AND jl.entity_type = 'CUSTOMER'
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry', 'Opening Balance', 'Opening_Balance', 'opening_balance')
    ) + (
        SELECT COALESCE(SUM(p.amount), 0)
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id
        WHERE p.customer_id = c.id AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
          AND th.id IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ) - (
        SELECT COALESCE(SUM(COALESCE(cm.total_amount, th.net_amount)), 0)
        FROM transaction_headers th
        JOIN credit_memos cm ON cm.header_id = th.id
        WHERE cm.customer_id = c.id
          AND th.txn_type IN ('credit_memo', 'Credit Memo')
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    )) AS total_sales,
    (
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id 
        WHERE p.customer_id = c.id
          AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
          AND th.id NOT IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ) AS total_paid
    FROM customers c 
    LEFT JOIN locations loc ON c.location_id = loc.id
    WHERE c.is_deleted = 0 $status_filter
    ORDER BY c.full_name ASC
");

$grand_sales = 0;
$grand_paid = 0;
$grand_remaining = 0;
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

<div class="ns-portlet" style="overflow: visible !important;">
    <div class="ns-portlet-content" style="overflow: visible !important;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th width="50" style="text-align: center;">#</th>
                    <th>Full Name</th>
                    <th>Location</th>
                    <th>Type</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th style="text-align: center;">Total Sales</th>
                    <th style="text-align: center;">Total Paid</th>
                    <th style="text-align: center;">Remaining Amount</th>
                    <th>Status</th>
                    <th width="100" style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1; foreach ($customers as $row): 
                    $sales = floatval($row['total_sales']);
                    $paid = floatval($row['total_paid']);
                    $remaining = get_customer_net_balance($db, $row['id']);

                    $grand_sales += $sales;
                    $grand_paid += $paid;
                    $grand_remaining += $remaining;
                ?>
                <tr>
                    <td style="text-align: center; color: #888; font-weight: 600;"><?php echo $sn++; ?></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><span style="font-weight: 600; font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #e0f2fe; color: #0369a1;"><?php echo htmlspecialchars($row['location_name'] ?? 'Gokarna'); ?></span></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['customer_type'])); ?></td>
                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td style="text-align: center; font-weight: 600; color: #080;">Rs <?php echo number_format($sales, 2); ?></td>
                    <td style="text-align: center; color: #2563eb;">Rs <?php echo number_format($paid, 2); ?></td>
                    <td style="text-align: center; font-weight: 600; color: <?php echo $remaining > 0 ? '#c00' : ($remaining < 0 ? '#2563eb' : '#333'); ?>;">
                        Rs <?php echo number_format($remaining, 2); ?>
                        <?php if ($remaining < 0): ?>
                            <span style="font-size: 10px; font-weight: normal; color: #2563eb; display: block;">(Advance)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="color: <?php echo $row['is_active'] ? '#080' : '#c00'; ?>">
                            <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <div style="position: relative; display: inline-block;">
                            <button type="button" class="ns-action-btn ns-dropdown-toggle">
                                Actions <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                            </button>
                            <div class="ns-action-dropdown-menu">
                                <a href="?page=master/customer/view&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-eye" style="color: #64748b; width: 14px;"></i> View
                                </a>
                                <a href="?page=master/customer/manage&id=<?php echo $row['id']; ?>" class="ns-action-item">
                                    <i class="fas fa-edit" style="color: #0284c7; width: 14px;"></i> Edit
                                </a>
                                <div style="height: 1px; background: #e2e8f0; margin: 4px 0;"></div>
                                <a href="javascript:void(0)" onclick="nsDelete('customers', '<?php echo $row['id']; ?>')" class="ns-action-item danger">
                                    <i class="fas fa-trash-alt" style="color: #dc2626; width: 14px;"></i> Delete
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8fafc; font-weight: 700; border-top: 2px solid #cbd5e1;">
                    <td colspan="6" style="text-align: right; padding: 10px 12px; font-size: 13px;">TOTAL:</td>
                    <td style="text-align: center; color: #080; font-size: 13px;">Rs <?php echo number_format($grand_sales, 2); ?></td>
                    <td style="text-align: center; color: #2563eb; font-size: 13px;">Rs <?php echo number_format($grand_paid, 2); ?></td>
                    <td style="text-align: center; color: <?php echo $grand_remaining > 0 ? '#c00' : ($grand_remaining < 0 ? '#2563eb' : '#333'); ?>; font-size: 13px;">
                        Rs <?php echo number_format($grand_remaining, 2); ?>
                        <?php if ($grand_remaining < 0): ?>
                            <span style="font-size: 10px; font-weight: normal; color: #2563eb; display: block;">(Net Advance)</span>
                        <?php endif; ?>
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
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
