<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? '';

if (!$id) {
    echo "<div class='alert alert-danger'>Invalid Transaction ID</div>";
    return;
}

$header = $db->fetchOne("
    SELECT t.*, 
           u_created.full_name as created_by_name,
           COALESCE(c.full_name, v.company_name, u_party.full_name) as party_name
    FROM transaction_headers t
    LEFT JOIN users u_created ON t.created_by = u_created.id
    LEFT JOIN customers c ON t.party_id = c.id AND t.party_type = 'customer'
    LEFT JOIN vendors v ON t.party_id = v.id AND t.party_type = 'vendor'
    LEFT JOIN users u_party ON t.party_id = u_party.id AND t.party_type = 'user'
    WHERE t.id = :id
", ['id' => $id]);

if (!$header || $header['is_deleted'] == 1) {
    echo "<div style='padding:20px;'><div class='alert alert-danger'>Transaction not found or may have been permanently deleted.</div></div>";
    return;
}

$txn_type = $header['txn_type'];

$is_locked = false;
if (!empty($header['txn_date'])) {
    $closed_fy = $db->fetchOne("
        SELECT id FROM fiscal_years 
        WHERE ? BETWEEN start_date AND end_date AND status = 'closed'
    ", [$header['txn_date']]);
    if ($closed_fy) {
        $is_locked = true;
    }
}

$details = [];

// Fetch Specific Details
if ($txn_type == 'vendor_bill') {
    $details = $db->fetchOne("
        SELECT vb.*, v.company_name as entity_name, v.phone as entity_phone 
        FROM vendor_bills vb
        LEFT JOIN vendors v ON vb.vendor_id = v.id
        WHERE vb.header_id = :id
    ", ['id' => $id]);
} elseif ($txn_type == 'customer_invoice') {
    $details = $db->fetchOne("
        SELECT ci.*, c.full_name as entity_name, c.phone as entity_phone 
        FROM customer_invoices ci
        LEFT JOIN customers c ON ci.customer_id = c.id
        WHERE ci.header_id = :id
    ", ['id' => $id]);
} elseif (in_array(strtolower($txn_type), ['customer_payment', 'vendor_payment'])) {
    $details = $db->fetchOne("
        SELECT p.*, 
            COALESCE(c.full_name, v.company_name) as entity_name, 
            COALESCE(c.phone, v.phone) as entity_phone,
            a.account_name as bank_account_name,
            th.txn_number as applied_to_number
        FROM payments p
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN vendors v ON p.vendor_id = v.id
        LEFT JOIN accounts a ON p.bank_account_id = a.id
        LEFT JOIN transaction_headers th ON p.applied_to_txn_id = th.id
        WHERE p.header_id = :id
    ", ['id' => $id]);
    if ($details) {
        $total_paid_row = $db->fetchOne("SELECT SUM(amount) as total_amt FROM payments WHERE header_id = :id", ['id' => $id]);
        $details['total_amount'] = (float)($total_paid_row['total_amt'] ?? 0);
    }
} elseif (strtolower($txn_type) == 'expense') {
    $details = $db->fetchOne("
        SELECT e.*, a_exp.account_name as expense_account_name, a_paid.account_name as paid_from_account_name
        FROM expenses e
        LEFT JOIN accounts a_exp ON e.expense_account_id = a_exp.id
        LEFT JOIN accounts a_paid ON e.paid_from_account_id = a_paid.id
        WHERE e.header_id = :id
    ", ['id' => $id]);
    if ($details) {
        $details['entity_name'] = $header['party_id']; 
        $details['total_amount'] = $details['amount'];
    }
} elseif (strtolower($txn_type) == 'cash_denomination') {
    $details = $db->fetchOne("
        SELECT * FROM cash_denominations WHERE header_id = :id
    ", ['id' => $id]);
    if ($details) {
        $details['total_amount'] = $details['total_cash'];
        $details['entity_name'] = $header['party_id']; // Shift/Counter
    }
}

// Fetch Items
$items = $db->fetchAll("
    SELECT tl.*, i.item_name, i.sku, a.account_name,
           COALESCE(rc.name, tl.unit, '') as unit_display
    FROM transaction_lines tl
    LEFT JOIN items i ON tl.item_id = i.id
    LEFT JOIN reference_codes rc ON i.unit_type = rc.id AND rc.type = 'units'
    LEFT JOIN accounts a ON tl.account_id = a.id
    WHERE tl.header_id = :id
    ORDER BY tl.line_number ASC
", ['id' => $id]);

// Fetch GL Entries
$gl_entries = $db->fetchAll("
    SELECT je.*, a.account_name, a.account_code,
           COALESCE(c.full_name, v.company_name, u.full_name) as party_name
    FROM journal_entries je 
    JOIN accounts a ON je.account_id = a.id 
    LEFT JOIN customers c ON je.party_id = c.id AND je.party_type = 'customer'
    LEFT JOIN vendors v ON je.party_id = v.id AND je.party_type = 'vendor'
    LEFT JOIN users u ON je.party_id = u.id AND je.party_type = 'user'
    WHERE je.header_id = :id
    ORDER BY je.entry_type DESC, je.id ASC
", ['id' => $id]); 

// Fetch Related Links
$links = $db->fetchAll("
    SELECT tl.*, 
        p.txn_number as parent_num, p.txn_type as parent_type, p.status as parent_status,
        c.txn_number as child_num, c.txn_type as child_type, c.status as child_status
    FROM transaction_links tl
    LEFT JOIN transaction_headers p ON tl.parent_id = p.id
    LEFT JOIN transaction_headers c ON tl.child_id = c.id
    WHERE tl.parent_id = :id OR tl.child_id = :id
", ['id' => $id]);

// Also fetch from payments directly and via transaction_links
$payments = $db->fetchAll("
    SELECT p.id, p.header_id, p.payment_method, p.payment_date, th.txn_number, th.status,
           IF(p.applied_to_txn_id IS NOT NULL, p.amount, COALESCE(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)), p.amount)) as amount,
           a.account_name
    FROM transaction_links tl
    JOIN transaction_headers th ON tl.parent_id = th.id
    LEFT JOIN payments p ON p.header_id = th.id
    LEFT JOIN accounts a ON p.bank_account_id = a.id
    WHERE tl.child_id = :id AND tl.link_type LIKE 'payment:%'
    UNION DISTINCT
    SELECT p.id, p.header_id, p.payment_method, p.payment_date, th.txn_number, th.status, p.amount,
           a.account_name
    FROM payments p
    LEFT JOIN transaction_headers th ON p.header_id = th.id
    LEFT JOIN accounts a ON p.bank_account_id = a.id
    WHERE p.applied_to_txn_id = :id
", ['id' => $id]);

// Audit Logs
$audit_logs = $db->fetchAll("
    SELECT al.*, u.full_name as updated_by_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.record_id = :id
    ORDER BY al.created_at DESC
", ['id' => $id]);

function getDiff($oldJson, $newJson) {
    $old = json_decode($oldJson, true) ?: [];
    $new = json_decode($newJson, true) ?: [];
    
    if (!$old && $new) return array_map(function($v) { return ['old' => '', 'new' => $v]; }, $new);
    if (!$new) return [];
    
    $diff = [];
    foreach ($new as $key => $val) {
        $oldVal = $old[$key] ?? '';
        // Ignore noise fields
        if (in_array($key, ['updated_at', 'created_at', 'id', 'header_id'])) continue;
        
        if ((string)$oldVal !== (string)$val) {
            $diff[$key] = ['old' => $oldVal, 'new' => $val];
        }
    }
    return $diff;
}

// Logic for delete validation
$can_delete = true;
$delete_error = "";
if (count($payments) > 0) {
    $can_delete = false;
    $delete_error = "Cannot delete: Related payments exist.";
} elseif (count($links) > 0) {
    $can_delete = false;
    $delete_error = "Cannot delete: This transaction is linked to others.";
}

// Determine Status and Formatting
$statusStr = $details['payment_status'] ?? $header['status'];
if (strtolower($statusStr) === 'paid') {
    $statusStr = 'Paid in Full';
} else {
    $statusStr = ucwords($statusStr);
}
$statusColor = '#666';
if (in_array(strtolower($statusStr), ['approved', 'posted', 'paid', 'paid in full'])) $statusColor = '#2ecc71';
if (in_array(strtolower($statusStr), ['voided', 'unpaid'])) $statusColor = '#e74c3c';
if (in_array(strtolower($statusStr), ['partial'])) $statusColor = '#f39c12';

$net_amount = $details['total_amount'] ?? $header['net_amount'] ?? 0;
$displayType = ucwords(str_replace('_', ' ', $txn_type));

    $edit_url = "#";
    $list_url = "?page=transactions"; // fallback
    if ($txn_type == 'vendor_bill') {
        $edit_url = "?page=transactions/bill/manage&id=".$id;
        $list_url = "?page=transactions/bill";
    } elseif ($txn_type == 'customer_invoice') {
        $edit_url = "?page=transactions/invoice/manage&id=".$id;
        $list_url = "?page=transactions/invoice";
    } elseif (in_array($txn_type, ['customer_payment', 'vendor_payment'])) {
        $edit_url = "?page=transactions/payment/manage&id=".$id;
        $list_url = "?page=transactions/payment";
    } elseif ($txn_type == 'Journal') {
        $edit_url = "?page=transactions/journal/manage&id=".$id;
        $list_url = "?page=transactions/journal";
    } elseif (strtolower($txn_type) == 'expense') {
        $edit_url = "?page=transactions/expense/manage&id=".$id;
        $list_url = "?page=transactions/expense";
    } elseif (strtolower($txn_type) == 'inventory_adjustment') {
        $edit_url = "?page=transactions/adjustment/manage&id=".$id;
        $list_url = "?page=transactions/adjustment";
    } elseif ($txn_type == 'cash_denomination') {
        $edit_url = "?page=transactions/cash_denom/manage&id=".$id;
        $list_url = "?page=transactions/cash_denom";
    } elseif ($txn_type == 'account_transfer') {
        $edit_url = "?page=transactions/transfer/manage&id=".$id;
        $list_url = "?page=transactions/transfer";
    }
?>
<style>
    .view-header {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }
    .view-title h1 {
        margin: 0;
        font-size: 24px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .view-title .badge {
        font-size: 12px;
        padding: 4px 8px;
        border-radius: 4px;
        color: white;
        font-weight: 600;
        text-transform: uppercase;
        vertical-align: middle;
    }
    .view-subtitle {
        margin-top: 5px;
        color: #666;
        font-size: 14px;
    }
    .view-actions {
        display: flex;
        gap: 10px;
    }
    .summary-box {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        min-width: 200px;
        text-align: center;
    }
    .summary-label {
        font-size: 12px;
        color: #666;
        text-transform: uppercase;
        font-weight: 600;
    }
    .summary-value {
        font-size: 24px;
        font-weight: 700;
        color: #1a202c;
        margin-top: 5px;
    }
    
    /* Tabs System */
    .ns-tabs {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 20px;
    }
    .ns-tab {
        padding: 12px 20px;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s;
    }
    .ns-tab:hover {
        color: var(--ns-primary);
    }
    .ns-tab.active {
        color: var(--ns-primary);
        border-bottom-color: var(--ns-primary);
    }
    .ns-tab-content {
        display: none;
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .ns-tab-content.active {
        display: block;
    }
    
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }
    .detail-group {
        margin-bottom: 15px;
    }
    .detail-label {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .detail-value {
        font-size: 14px;
        color: #1e293b;
        font-weight: 500;
    }
    
    .audit-group {
        border-left: 3px solid var(--ns-primary);
        padding-left: 15px;
        margin-bottom: 20px;
    }
    .audit-meta {
        font-size: 12px;
        color: #64748b;
        margin-bottom: 8px;
        font-weight: 600;
    }
    .audit-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .audit-table th, .audit-table td {
        padding: 6px 10px;
        border: 1px solid #e2e8f0;
        text-align: left;
    }
    .audit-table th {
        background: #f8f9fa;
        color: #64748b;
    }
</style>

<div class="view-header">
    <div>
        <div class="view-title">
            <h1>
                <?php echo htmlspecialchars($displayType); ?>: <?php echo htmlspecialchars($header['txn_number']); ?>
                <span class="badge" style="background: <?php echo $statusColor; ?>;"><?php echo $statusStr; ?></span>
            </h1>
        </div>
        <div class="view-subtitle">
            <?php if (!empty($details['entity_name'])): ?>
                <strong><?php echo htmlspecialchars($details['entity_name']); ?></strong> | 
            <?php endif; ?>
            Date: <?php echo date('M d, Y', strtotime($header['txn_date'])); ?>
        </div>
    </div>
    
    <div style="display: flex; gap: 20px; align-items: flex-start;">
        <?php if($net_amount > 0): ?>
        <div class="summary-box">
            <div class="summary-label">Total Amount</div>
            <div class="summary-value">Rs. <?php echo number_format($net_amount, 2); ?></div>
        </div>
        <?php endif; ?>
        
        <div class="view-actions">
            <?php if (in_array($txn_type, ['customer_invoice', 'vendor_bill']) && ($details['balance_due'] ?? 0) > 0.01 && ($details['payment_status'] ?? '') !== 'paid' && !$is_locked): 
                $partyType = $txn_type == 'customer_invoice' ? 'customer' : 'vendor';
                $partyId = $txn_type == 'customer_invoice' ? $details['customer_id'] : $details['vendor_id'];
            ?>
                <a href="?page=transactions/payment/manage&party_type=<?php echo $partyType; ?>&party_id=<?php echo $partyId; ?>" class="ns-btn" style="background: #28a745; color: white; border-color: #28a745;"><i class="fas fa-money-bill-wave"></i> Make Payment</a>
            <?php endif; ?>
            
            <?php if (!$is_locked): ?>
                <a href="<?php echo $edit_url; ?>" class="ns-btn"><i class="fas fa-edit"></i> Edit</a>
            <?php else: ?>
                <button class="ns-btn" disabled style="color: #64748b; background: #f1f5f9; border-color: #cbd5e1; cursor: not-allowed;" title="This transaction is locked in a closed fiscal period"><i class="fas fa-lock"></i> Locked</button>
            <?php endif; ?>
            
            <a href="?page=transactions/print&id=<?php echo $id; ?>" target="_blank" class="ns-btn ns-btn-primary"><i class="fas fa-print"></i> Print</a>
            <a href="javascript:history.back()" class="ns-btn"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>
</div>

<!-- PRIMARY INFORMATION (Always Visible) -->
<div class="view-primary-info" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <div class="detail-grid">
        <!-- Main Info -->
        <div>
            <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">Primary Information</h3>
            <?php if(in_array(strtolower($txn_type), ['customer_invoice', 'vendor_bill', 'customer_payment', 'vendor_payment'])): ?>
            <div class="detail-group">
                <div class="detail-label"><?php echo ($txn_type == 'vendor_bill' || $txn_type == 'vendor_payment') ? 'Vendor' : 'Customer'; ?></div>
                <div class="detail-value"><?php echo htmlspecialchars($details['entity_name'] ?? 'N/A'); ?></div>
            </div>
            <?php elseif(strtolower($txn_type) == 'cash_denomination'): ?>
            <div class="detail-group">
                <div class="detail-label">Counter/Shift</div>
                <div class="detail-value"><?php echo htmlspecialchars($details['entity_name'] ?? 'Main'); ?></div>
            </div>
            <?php endif; ?>
            <?php if(isset($details['due_date'])): ?>
            <div class="detail-group">
                <div class="detail-label">Due Date</div>
                <div class="detail-value"><?php echo date('M d, Y', strtotime($details['due_date'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if(!empty($details['vendor_invoice_number'])): ?>
            <div class="detail-group">
                <div class="detail-label">Vendor Ref #</div>
                <div class="detail-value"><?php echo htmlspecialchars($details['vendor_invoice_number']); ?></div>
            </div>
            <?php endif; ?>
            <?php if(!empty($details['invoice_number']) && $txn_type == 'customer_invoice'): ?>
            <div class="detail-group">
                <div class="detail-label">Invoice #</div>
                <div class="detail-value"><?php echo htmlspecialchars($details['invoice_number']); ?></div>
            </div>
            <?php endif; ?>
            <div class="detail-group">
                <div class="detail-label">Status</div>
                <div class="detail-value">
                    <span class="badge" style="background: <?php echo $statusColor; ?>; padding: 4px 8px; border-radius: 4px; color: white; font-weight: 600; font-size: 11px; text-transform: uppercase;">
                        <?php echo $statusStr; ?>
                    </span>
                </div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Memo</div>
                <div class="detail-value"><?php echo nl2br(htmlspecialchars($header['memo'] ?: 'None')); ?></div>
            </div>
        </div>
        
        <!-- Financial Info -->
        <div>
            <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">Financial Summary</h3>
            
            <?php if(in_array($txn_type, ['customer_payment', 'vendor_payment'])): ?>
                <div class="detail-group">
                    <div class="detail-label">Payment Method</div>
                    <div class="detail-value"><?php echo htmlspecialchars(ucfirst($details['payment_method'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Account</div>
                    <div class="detail-value"><?php echo htmlspecialchars($details['bank_account_name'] ?? 'N/A'); ?></div>
                </div>
                <?php if(!empty($details['cheque_number']) || !empty($details['transaction_reference'])): ?>
                <div class="detail-group">
                    <div class="detail-label">Cheque / Ref #</div>
                    <div class="detail-value"><?php echo htmlspecialchars(trim(($details['cheque_number']??'').' '.($details['transaction_reference']??''))); ?></div>
                </div>
                <?php endif; ?>
                <?php if(!empty($details['applied_to_number'])): ?>
                <div class="detail-group">
                    <div class="detail-label">Applied To</div>
                    <div class="detail-value"><a href="?page=transactions/view&id=<?php echo $details['applied_to_txn_id']; ?>"><?php echo htmlspecialchars($details['applied_to_number']); ?></a></div>
                </div>
                <?php endif; ?>
            <?php elseif(strtolower($txn_type) == 'expense'): ?>
                <div class="detail-group">
                    <div class="detail-label">Expense Account</div>
                    <div class="detail-value"><?php echo htmlspecialchars($details['expense_account_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Paid From</div>
                    <div class="detail-value"><?php echo htmlspecialchars($details['paid_from_account_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-group" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                    <div class="detail-label" style="font-size: 14px; color: #333;">Amount</div>
                    <div class="detail-value" style="font-size: 18px; font-weight: 700;">Rs. <?php echo number_format($details['amount'] ?? 0, 2); ?></div>
                </div>
            <?php elseif(strtolower($txn_type) == 'cash_denomination'): ?>
                <div class="detail-group" style="margin-top: 15px; padding-top: 15px;">
                    <div class="detail-label" style="font-size: 14px; color: #333;">Total Cash Counted</div>
                    <div class="detail-value" style="font-size: 24px; font-weight: 700; color: #003087;">Rs. <?php echo number_format($details['total_amount'] ?? 0, 2); ?></div>
                </div>
            <?php else: ?>
                <?php if (in_array(strtolower($txn_type), ['customer_invoice', 'vendor_bill'])): ?>
                    <div class="detail-group">
                        <div class="detail-label">Subtotal</div>
                        <div class="detail-value">Rs. <?php echo number_format($details['subtotal'] ?? 0, 2); ?></div>
                    </div>
                    <div class="detail-group">
                        <div class="detail-label">Discount</div>
                        <div class="detail-value">Rs. <?php echo number_format($details['discount_amount'] ?? 0, 2); ?></div>
                    </div>
                    <div class="detail-group">
                        <div class="detail-label">Tax (VAT)</div>
                        <div class="detail-value">Rs. <?php echo number_format($details['tax_amount'] ?? 0, 2); ?></div>
                    </div>
                <?php endif; ?>
                <div class="detail-group" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                    <div class="detail-label" style="font-size: 14px; color: #333;">Total Amount</div>
                    <div class="detail-value" style="font-size: 18px; font-weight: 700;">Rs. <?php echo number_format($details['total_amount'] ?? $header['net_amount'] ?? 0, 2); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="ns-tabs">
    <?php if(strtolower($txn_type) == 'cash_denomination'): ?>
    <div class="ns-tab active" data-target="tab-denom">Denomination Breakdown</div>
    <?php endif; ?>

    <?php if(count($items) > 0): ?>
    <div class="ns-tab <?php echo strtolower($txn_type) != 'cash_denomination' ? 'active' : ''; ?>" data-target="tab-items">Items (<?php echo count($items); ?>)</div>
    <div class="ns-tab" data-target="tab-gl">GL Impact</div>
    <?php else: ?>
    <div class="ns-tab <?php echo strtolower($txn_type) != 'cash_denomination' ? 'active' : ''; ?>" data-target="tab-gl">GL Impact</div>
    <?php endif; ?>
    
    <?php if(in_array($txn_type, ['customer_payment', 'vendor_payment'])): ?>
    <div class="ns-tab" data-target="tab-applied">Applied Documents</div>
    <?php endif; ?>
    
    <?php if(!in_array(strtolower($txn_type), ['customer_payment', 'vendor_payment', 'expense'])): ?>
    <div class="ns-tab" data-target="tab-related">Related Records</div>
    <?php endif; ?>
    <div class="ns-tab" data-target="tab-system">System Information</div>
</div>

<!-- DENOMINATION TAB -->
<?php if(strtolower($txn_type) == 'cash_denomination' && $details): ?>
<div class="ns-tab-content active" id="tab-denom">
    <div style="max-width: 500px; margin: 0 auto;">
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Denomination</th>
                    <th style="text-align: center;">Count</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $denoms = [
                    ['val' => 1000, 'key' => 'note_1000'],
                    ['val' => 500,  'key' => 'note_500'],
                    ['val' => 100,  'key' => 'note_100'],
                    ['val' => 50,   'key' => 'note_50'],
                    ['val' => 20,   'key' => 'note_20'],
                    ['val' => 10,   'key' => 'note_10'],
                    ['val' => 5,    'key' => 'coin_5'],
                    ['val' => 2,    'key' => 'coin_2'],
                    ['val' => 1,    'key' => 'coin_1'],
                ];
                foreach ($denoms as $d):
                    $count = (int)($details[$d['key']] ?? 0);
                    if ($count === 0) continue;
                ?>
                <tr>
                    <td style="font-weight: 600;">NPR <?php echo $d['val']; ?></td>
                    <td style="text-align: center;"><?php echo $count; ?></td>
                    <td style="text-align: right;">Rs. <?php echo number_format($count * $d['val'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f1f5f9; font-weight: 700;">
                    <td colspan="2" style="text-align: right;">Total Counted Cash:</td>
                    <td style="text-align: right; color: #003087; font-size: 16px;">Rs. <?php echo number_format($details['total_cash'], 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ITEMS TAB -->
<?php if(count($items) > 0): ?>
<div class="ns-tab-content <?php echo (strtolower($txn_type) != 'cash_denomination') ? 'active' : ''; ?>" id="tab-items">
    <table class="ns-table" style="width: 100%;">
        <thead>
            <tr>
                <th>#</th>
                <th>Item Name</th>
                <th>Description</th>
                <th style="text-align: right;">Qty</th>
                <th>Unit</th>
                <th style="text-align: right;">Rate</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $totQty = 0; $totAmt = 0;
            foreach($items as $idx => $line): 
                $totQty += $line['quantity'];
                $totAmt += $line['line_total'];
            ?>
            <tr>
                <td><?php echo $line['line_number'] ?: ($idx+1); ?></td>
                <td><strong><?php echo htmlspecialchars($line['item_name'] ?? 'Unknown Item'); ?></strong></td>
                <td><?php echo htmlspecialchars($line['description']); ?></td>
                <td style="text-align: right;"><?php echo number_format($line['quantity'], 2); ?></td>
                <td><?php echo htmlspecialchars($line['unit_display']); ?></td>
                <td style="text-align: right;"><?php echo number_format($line['unit_price'], 2); ?></td>
                <td style="text-align: right;"><strong><?php echo number_format($line['line_total'], 2); ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
<tr style="background: #f8f9fa; font-weight: 700;">
        <td>Totals:</td>
        <td></td>
        <td></td>
        <td style="text-align: right;"><?php echo number_format($totQty, 2); ?></td>
        <td></td>
        <td></td>
        <td style="text-align: right;"><?php echo number_format($totAmt, 2); ?></td>
    </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<!-- GL IMPACT TAB -->
<div class="ns-tab-content <?php echo (count($items) == 0 && strtolower($txn_type) != 'cash_denomination') ? 'active' : ''; ?>" id="tab-gl">
    <?php if(count($gl_entries) == 0): ?>
        <div style="padding: 20px; text-align: center; color: #888;">
            <i class="fas fa-book" style="font-size: 32px; opacity: 0.3; margin-bottom: 10px;"></i>
            <p>No GL entries posted for this transaction yet.</p>
        </div>
    <?php else: ?>
        <table class="ns-table" style="width: 100%;">
            <thead>
                <tr>
                    <th>Account Code</th>
                    <th>Account Name</th>
                    <th>Name / Entity</th>
                    <th>Memo</th>
                    <th style="text-align: right;">Debit (Dr)</th>
                    <th style="text-align: right;">Credit (Cr)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totDr = 0; $totCr = 0;
                foreach($gl_entries as $je): 
                    $isDr = $je['entry_type'] == 'debit';
                    $dr = $isDr ? $je['amount'] : 0;
                    $cr = !$isDr ? $je['amount'] : 0;
                    $totDr += $dr;
                    $totCr += $cr;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($je['account_code']); ?></td>
                    <td>
                        <div style="padding-left: <?php echo $isDr ? '0' : '20px'; ?>">
                            <?php echo htmlspecialchars($je['account_name']); ?>
                        </div>
                    </td>
                    <td><span style="font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($je['party_name'] ?? '-'); ?></span></td>
                    <td><?php echo htmlspecialchars($je['memo']); ?></td>
                    <td style="text-align: right;"><?php echo $dr > 0 ? number_format($dr, 2) : ''; ?></td>
                    <td style="text-align: right;"><?php echo $cr > 0 ? number_format($cr, 2) : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8f9fa; font-weight: 700;">
                    <td colspan="4" style="text-align: right;">Totals:</td>
                    <td style="text-align: right; border-top: 2px solid #ccc; border-bottom: 4px double #ccc;"><?php echo number_format($totDr, 2); ?></td>
                    <td style="text-align: right; border-top: 2px solid #ccc; border-bottom: 4px double #ccc;"><?php echo number_format($totCr, 2); ?></td>
                </tr>
            </tfoot>
        </table>
        
        <?php if(abs($totDr - $totCr) > 0.01): ?>
            <div class="alert alert-danger" style="margin-top: 15px;">
                <i class="fas fa-exclamation-triangle"></i> Warning: Trial Balance mismatch! Debits and Credits are not equal.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- APPLIED DOCUMENTS TAB -->
<?php if(in_array($txn_type, ['customer_payment', 'vendor_payment'])): ?>
<?php 
$applied_records = $db->fetchAll("
    SELECT tl.*, th.txn_number as applied_to_number, th.txn_type as applied_to_type, th.status as applied_to_status,
           CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)) as amount
    FROM transaction_links tl
    JOIN transaction_headers th ON tl.child_id = th.id
    WHERE tl.parent_id = :id AND tl.link_type LIKE 'payment:%'
", ['id' => $id]);
?>
<div class="ns-tab-content" id="tab-applied">
    <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">Invoices / Bills Paid</h3>
    <?php if(count($applied_records) == 0): ?>
        <p style="color: #888; font-style: italic;">No applied documents found.</p>
    <?php else: ?>
        <table class="ns-table" style="width: 100%;">
            <thead>
                <tr>
                    <th>Document #</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th style="text-align: right;">Applied Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totApp = 0;
                foreach($applied_records as $app): 
                    $totApp += $app['amount'];
                ?>
                <tr>
                    <td><a href="?page=transactions/view&id=<?php echo $app['child_id']; ?>"><?php echo htmlspecialchars($app['applied_to_number']); ?></a></td>
                    <td><?php echo ucwords(str_replace('_', ' ', $app['applied_to_type'])); ?></td>
                    <td>
                        <span class="badge" style="background: <?php echo in_array(strtolower($app['applied_to_status']), ['paid', 'posted', 'approved']) ? '#2ecc71' : (strtolower($app['applied_to_status']) == 'partial' ? '#f39c12' : '#666'); ?>; color: white; padding: 2px 6px; font-size: 10px;">
                            <?php echo htmlspecialchars(ucfirst($app['applied_to_status'])); ?>
                        </span>
                    </td>
                    <td style="text-align: right; font-weight: 600;">Rs. <?php echo number_format($app['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8f9fa; font-weight: 700;">
                    <td colspan="3" style="text-align: right;">Total Applied:</td>
                    <td style="text-align: right;">Rs. <?php echo number_format($totApp, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- RELATED RECORDS TAB -->
<?php if(!in_array($txn_type, ['customer_payment', 'vendor_payment'])): ?>
<div class="ns-tab-content" id="tab-related">
    <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">Linked Payments</h3>
    <?php if(count($payments) == 0): ?>
        <p style="color: #888; font-style: italic;">No payments linked.</p>
    <?php else: ?>
        <table class="ns-table" style="width: 100%; margin-bottom: 30px;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Payment Account</th>
                    <th>Status</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($payments as $p): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($p['payment_date'])); ?></td>
                    <td><a href="?page=transactions/view&id=<?php echo $p['header_id']; ?>"><?php echo htmlspecialchars($p['txn_number']); ?></a></td>
                    <td>
                        <?php 
                        if (!empty($p['account_name'])) {
                            echo htmlspecialchars($p['account_name']);
                        } else {
                            echo htmlspecialchars(ucfirst($p['payment_method'] ?? 'Unknown'));
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($p['status']); ?></td>
                    <td style="text-align: right; font-weight: 600;">Rs. <?php echo number_format($p['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">Other Links</h3>
    <?php if(count($links) == 0): ?>
        <p style="color: #888; font-style: italic;">No other linked records.</p>
    <?php else: ?>
        <table class="ns-table" style="width: 100%;">
            <thead>
                <tr>
                    <th>Link Type</th>
                    <th>Document</th>
                    <th>Type</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($links as $l): 
                    $isParent = $l['parent_id'] == $id;
                    $relDoc = $isParent ? $l['child_num'] : $l['parent_num'];
                    $relType = $isParent ? $l['child_type'] : $l['parent_type'];
                    $relId = $isParent ? $l['child_id'] : $l['parent_id'];
                    $relStatus = $isParent ? $l['child_status'] : $l['parent_status'];
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($l['link_type']); ?> (<?php echo $isParent ? 'Child' : 'Parent'; ?>)</td>
                    <td><a href="?page=transactions/view&id=<?php echo $relId; ?>"><?php echo htmlspecialchars($relDoc); ?></a></td>
                    <td><?php echo ucwords(str_replace('_', ' ', $relType)); ?></td>
                    <td><?php echo htmlspecialchars($relStatus); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- SYSTEM INFO TAB -->
<div class="ns-tab-content" id="tab-system">
    <div class="detail-grid" style="margin-bottom: 30px;">
        <div>
            <div class="detail-group">
                <div class="detail-label">Created By</div>
                <div class="detail-value"><?php echo htmlspecialchars($header['created_by_name'] ?? 'System'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Created At</div>
                <div class="detail-value"><?php echo date('M d, Y H:i:s', strtotime($header['created_at'])); ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Internal ID</div>
                <div class="detail-value" style="font-family: monospace;"><?php echo htmlspecialchars($id); ?></div>
            </div>
        </div>
    </div>
    
    <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">System Notes / Change Log</h3>
    <?php if(count($audit_logs) == 0): ?>
        <p style="color: #888; font-style: italic;">No changes recorded yet.</p>
    <?php else: ?>
        <table class="ns-table" style="width: 100%; font-size: 13px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th width="15%">Date</th>
                    <th width="15%">User</th>
                    <th width="20%">Field</th>
                    <th width="25%">Old Value</th>
                    <th width="25%">New Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($audit_logs as $log): 
                    $diffs = getDiff($log['old_values'] ?? '', $log['new_values'] ?? '');
                    
                    // For 'update' actions, only show if there are actual diffs
                    if ($log['action'] == 'update' && empty($diffs)) continue;

                    // For 'save' or 'delete', show a summary row if no specific diffs
                    if (($log['action'] == 'save' || $log['action'] == 'delete' || $log['action'] == 'create') && empty($diffs)):
                ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                        <td colspan="3" style="color: #64748b; font-style: italic;">
                            Record <?php echo ucfirst($log['action']); ?>d
                        </td>
                    </tr>
                <?php 
                    else:
                        foreach($diffs as $field => $changes): 
                ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                        <td style="font-weight: 500;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $field))); ?></td>
                        <td style="color: #e74c3c; background: #fff5f5;"><del><?php echo htmlspecialchars((string)$changes['old']); ?></del></td>
                        <td style="color: #2ecc71; background: #f0fff4; font-weight: 600;"><?php echo htmlspecialchars((string)$changes['new']); ?></td>
                    </tr>
                <?php endforeach; endif; endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
    // Tab Switching Logic
    document.querySelectorAll('.ns-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            // Remove active from all tabs
            document.querySelectorAll('.ns-tab').forEach(t => t.classList.remove('active'));
            // Remove active from all content
            document.querySelectorAll('.ns-tab-content').forEach(c => c.classList.remove('active'));
            
            // Add active to clicked
            tab.classList.add('active');
            document.getElementById(tab.getAttribute('data-target')).classList.add('active');
        });
    });

    // Delete Logic
    function attemptDelete() {
        const canDelete = <?php echo $can_delete ? 'true' : 'false'; ?>;
        const deleteError = "<?php echo $delete_error; ?>";
        const id = "<?php echo $id; ?>";
        const table = "transaction_headers";
        const listUrl = "<?php echo $list_url; ?>";

        if (!canDelete) {
            nsNotify(deleteError, 'error');
            return;
        }

        if (confirm("Are you sure you want to delete this transaction? This will mark it as void/deleted.")) {
            fetch('api/transaction_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    table: table,
                    primary_key: 'id',
                    primary_value: id
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    nsNotify('Transaction deleted successfully.');
                    setTimeout(() => { window.location.href = listUrl; }, 1500);
                } else {
                    nsNotify(data.message || 'Delete failed', 'error');
                }
            })
            .catch(err => {
                nsNotify('Network error', 'error');
            });
        }
    }
</script>
