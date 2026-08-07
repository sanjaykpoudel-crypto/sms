<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div class='alert alert-danger'>No customer ID provided.</div>";
    exit;
}

$customer = $db->fetchOne("
    SELECT c.*, a.account_name 
    FROM customers c 
    LEFT JOIN accounts a ON c.receivable_account_id = a.id 
    WHERE c.id = ?
", [$id]);

if (!$customer) {
    echo "<div class='alert alert-danger'>Customer not found.</div>";
    exit;
}

// Fetch related records (Invoices, Tagged Journals, Credit Memos & Customer Refunds)
$invoices = $db->fetchAll("
    SELECT 'Invoice' as doc_type, ci.id, ci.header_id, ci.invoice_number as doc_number, ci.invoice_date as doc_date, ci.total_amount, ci.balance_due, ci.payment_status 
    FROM customer_invoices ci 
    JOIN transaction_headers th ON ci.header_id = th.id
    WHERE ci.customer_id = ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    UNION ALL
    SELECT 'Journal' as doc_type, h.id as id, h.id as header_id, h.txn_number as doc_number, h.txn_date as doc_date,
        SUM(CASE WHEN (j.party_id = CAST(? AS CHAR) OR (h.party_id = CAST(? AS CHAR) AND (h.party_type = 'customer' OR h.party_type IS NULL))) THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) as total_amount,
        (
            SUM(CASE WHEN (j.party_id = CAST(? AS CHAR) OR (h.party_id = CAST(? AS CHAR) AND (h.party_type = 'customer' OR h.party_type IS NULL))) THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) ELSE 0 END) 
            - COALESCE((
                SELECT SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2)))
                FROM transaction_links tl
                JOIN transaction_headers ph ON tl.parent_id = ph.id
                JOIN payments p ON ph.id = p.header_id
                WHERE tl.child_id = h.id 
                  AND tl.link_type LIKE 'payment:%'
                  AND (p.customer_id = ? OR ph.party_id = CAST(? AS CHAR))
                  AND ph.is_deleted = 0 AND ph.status NOT IN ('void', 'voided', 'draft')
            ), 0.00)
        ) as balance_due,
        h.status as payment_status
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    WHERE (j.party_id = CAST(? AS CHAR) OR (h.party_id = CAST(? AS CHAR) AND (h.party_type = 'customer' OR h.party_type IS NULL))) 
      AND (j.party_type = 'customer' OR j.party_type IS NULL OR h.party_type = 'customer') 
      AND h.is_deleted = 0 
      AND h.status NOT IN ('void', 'voided', 'draft')
      AND h.txn_type IN ('Journal', 'journal_entry', 'Opening Balance', 'Opening_Balance', 'opening_balance')
    GROUP BY h.id, h.txn_number, h.txn_date, h.status
    UNION ALL
    SELECT 'Customer Refund' as doc_type, th.id, th.id as header_id, th.txn_number as doc_number, th.txn_date as doc_date,
        p.amount as total_amount,
        0 as balance_due,
        th.status as payment_status
    FROM payments p
    JOIN transaction_headers th ON p.header_id = th.id
    WHERE p.customer_id = ?
      AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
      AND th.id IN (
          SELECT tl.parent_id FROM transaction_links tl
          JOIN transaction_headers ch ON tl.child_id = ch.id
          WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
      )
    UNION ALL
    SELECT 'Credit Memo' as doc_type, th.id, th.id as header_id, th.txn_number as doc_number, th.txn_date as doc_date,
        -COALESCE(cm.total_amount, th.net_amount) as total_amount,
        0 as balance_due,
        th.status as payment_status
    FROM transaction_headers th
    LEFT JOIN credit_memos cm ON cm.header_id = th.id
    WHERE (cm.customer_id = ? OR (th.party_id = CAST(? AS CHAR) AND (th.party_type = 'customer' OR th.party_type IS NULL)))
      AND th.txn_type IN ('credit_memo', 'Credit Memo')
      AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ORDER BY doc_date DESC LIMIT 50
", [$id, $id, $id, $id, $id, $id, $id, $id, $id, $id, $id, $id]);

// Fetch related records (Payments - Customer Receipts only)
$payments = $db->fetchAll("
    SELECT th.id as header_id, th.txn_number, th.txn_date, th.created_at,
           SUM(DISTINCT p.amount) as total_amount,
           GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods,
           GROUP_CONCAT(DISTINCT COALESCE(ci.invoice_number, th_child.txn_number) ORDER BY COALESCE(ci.invoice_number, th_child.txn_number) SEPARATOR ', ') as applied_invoices
    FROM transaction_headers th
    JOIN payments p ON th.id = p.header_id
    LEFT JOIN transaction_links tl ON tl.parent_id = th.id
    LEFT JOIN transaction_headers th_child ON tl.child_id = th_child.id
    LEFT JOIN customer_invoices ci ON tl.child_id = ci.header_id
    WHERE p.customer_id = ?
      AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
      AND th.id NOT IN (
          SELECT tl.parent_id FROM transaction_links tl
          JOIN transaction_headers ch ON tl.child_id = ch.id
          WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
      )
    GROUP BY th.id
    ORDER BY th.txn_date DESC, th.created_at DESC LIMIT 50
", [$id]);

// Fetch Credit Memos sum
$credit_memos_sum = $db->fetchOne("
    SELECT COALESCE(SUM(COALESCE(cm.total_amount, th.net_amount)), 0) as total
    FROM transaction_headers th
    LEFT JOIN credit_memos cm ON cm.header_id = th.id
    WHERE (cm.customer_id = ? OR (th.party_id = CAST(? AS CHAR) AND (th.party_type = 'customer' OR th.party_type IS NULL)))
      AND th.txn_type IN ('credit_memo', 'Credit Memo')
      AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
", [$id, $id])['total'] ?? 0;

// Summary Computations
// Total Sales = Invoices + Journal Debits + Customer Refunds - Credit Memos
// Total Paid  = Payments Received (Money IN)
// Remaining   = Total Sales - Total Paid
$total_invoices_sum = array_sum(array_column($invoices, 'total_amount')); // already includes negative CM rows & positive refunds
$total_payments_sum = array_sum(array_column($payments, 'total_amount'));
$total_remaining_balance = $total_invoices_sum - $total_payments_sum;

// Fetch Audit Logs
$audit_logs = $db->fetchAll("
    SELECT al.*, COALESCE(u.full_name, al.user_id) as updated_by_name
    FROM audit_logs al
    LEFT JOIN users u ON (al.user_id = CAST(u.id AS CHAR) OR al.user_id = u.username)
    WHERE al.record_id = :id AND al.table_name = 'customers'
    ORDER BY al.created_at DESC
", ['id' => $id]);

if (!function_exists('getDiff')) {
    function getDiff($oldJson, $newJson) {
        $old = json_decode($oldJson, true) ?: [];
        $new = json_decode($newJson, true) ?: [];
        
        if (!$old && $new) return array_map(function($v) { return ['old' => '', 'new' => $v]; }, $new);
        if (!$new) return [];
        
        $diff = [];
        foreach ($new as $key => $val) {
            $oldVal = $old[$key] ?? '';
            if (in_array($key, ['updated_at', 'created_at', 'id'])) continue;
            
            if ((string)$oldVal !== (string)$val) {
                $diff[$key] = ['old' => $oldVal, 'new' => $val];
            }
        }
        return $diff;
    }
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
    .view-actions {
        display: flex;
        gap: 10px;
    }
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
        text-transform: uppercase;
    }
    .detail-value {
        font-size: 14px;
        color: #1e293b;
        font-weight: 500;
    }
    .summary-kpi-card {
        flex: 1;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 16px;
        text-align: center;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .summary-kpi-title {
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 4px;
    }
    .summary-kpi-val {
        font-size: 20px;
        font-weight: 800;
    }
</style>

<div class="view-header">
    <div>
        <div class="view-title">
            <h1><?php echo htmlspecialchars($customer['full_name']); ?></h1>
        </div>
    </div>
    <div class="view-actions">
        <a href="?page=master/customer/manage&id=<?php echo $id; ?>" class="ns-btn ns-btn-primary"><i class="fas fa-edit"></i> Edit</a>
        <a href="?page=master/customer" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<!-- Summary Cards matching Customer List -->
<div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
    <div class="summary-kpi-card">
        <div class="summary-kpi-title">Net Sales (Invoices & Journals - Returns)</div>
        <div class="summary-kpi-val" style="color: #16a34a;">Rs <?php echo number_format($total_invoices_sum, 2); ?></div>
    </div>
    <div class="summary-kpi-card">
        <div class="summary-kpi-title">Total Paid</div>
        <div class="summary-kpi-val" style="color: #2563eb;">Rs <?php echo number_format($total_payments_sum, 2); ?></div>
    </div>
    <div class="summary-kpi-card">
        <div class="summary-kpi-title">Remaining Amount</div>
        <div class="summary-kpi-val" style="color: <?php echo $total_remaining_balance > 0 ? '#dc2626' : ($total_remaining_balance < 0 ? '#2563eb' : '#334155'); ?>;">
            Rs <?php echo number_format($total_remaining_balance, 2); ?>
            <?php if ($total_remaining_balance < 0): ?>
                <span style="font-size: 10px; font-weight: normal; color: #2563eb; display: block;">(Advance Credit)</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="ns-tabs">
    <div class="ns-tab active" onclick="nsOpenTab('tab-primary', this)">Primary Information</div>
    <div class="ns-tab" onclick="nsOpenTab('tab-related', this)">Related Invoices & Journals <span style="background:#e2e8f0;padding:2px 6px;border-radius:10px;font-size:10px;color:#1e293b;"><?php echo count($invoices); ?></span></div>
    <div class="ns-tab" onclick="nsOpenTab('tab-payments', this)">Payments <span style="background:#e2e8f0;padding:2px 6px;border-radius:10px;font-size:10px;color:#1e293b;"><?php echo count($payments); ?></span></div>
    <div class="ns-tab" onclick="nsOpenTab('tab-system', this)">System Information</div>
</div>

<!-- Primary Information -->
<div id="tab-primary" class="ns-tab-content active">
    <div class="detail-grid">
        <!-- Column 1 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Full Name</div>
                <div class="detail-value"><?php echo htmlspecialchars($customer['full_name']); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Customer Type</div>
                <div class="detail-value"><?php echo ucfirst(htmlspecialchars($customer['customer_type'])); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Status</div>
                <div class="detail-value" style="color: <?php echo $customer['is_active'] ? '#080' : '#c00'; ?>; font-weight: 600;">
                    <?php echo $customer['is_active'] ? 'Active' : 'Inactive'; ?>
                </div>
            </div>
        </div>
        <!-- Column 2 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Phone</div>
                <div class="detail-value"><?php echo htmlspecialchars($customer['phone'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Email</div>
                <div class="detail-value"><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">PAN/VAT Number</div>
                <div class="detail-value"><?php echo htmlspecialchars($customer['pan_number'] ?: 'N/A'); ?></div>
            </div>
        </div>
        <!-- Column 3 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Receivable Account</div>
                <div class="detail-value"><?php echo htmlspecialchars($customer['account_name'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Credit Limit</div>
                <div class="detail-value">Rs <?php echo number_format($customer['credit_limit'] ?? 0, 2); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Payment Terms</div>
                <div class="detail-value"><?php echo htmlspecialchars($customer['payment_terms_days'] ?: '0'); ?> Days</div>
            </div>
        </div>
    </div>
</div>

<!-- Related Records -->
<div id="tab-related" class="ns-tab-content">
    <table class="ns-table">
        <thead>
            <tr>
                <th>Type</th>
                <th>Date</th>
                <th>Document #</th>
                <th style="text-align: right;">Total Amount</th>
                <th style="text-align: right;">Balance Due</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $tab_inv_total = 0;
            foreach($invoices as $inv): 
                $tab_inv_total += floatval($inv['total_amount']);
                $docType = $inv['doc_type'];
                $isCM = ($docType === 'Credit Memo');
                $isJournal = ($docType === 'Journal');
                if ($isCM) {
                    $typeBadge = '<span style="font-size:10px; background:#fff3e0; color:#e65100; padding:2px 6px; border-radius:4px; font-weight:700;">CREDIT MEMO</span>';
                } elseif ($isJournal) {
                    $typeBadge = '<span style="font-size:10px; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-weight:700;">JOURNAL</span>';
                } else {
                    $typeBadge = '<span style="font-size:10px; background:#f0fdf4; color:#15803d; padding:2px 6px; border-radius:4px; font-weight:700;">INVOICE</span>';
                }
                $amtColor = $isCM ? '#dc2626' : 'inherit';
                $amtPrefix = $isCM ? '-' : '';
                $displayAmt = $isCM ? abs($inv['total_amount']) : $inv['total_amount'];
            ?>
            <tr style="<?php echo $isCM ? 'background:#fff8f6;' : ''; ?>">
                <td><?php echo $typeBadge; ?></td>
                <td><?php echo date('M d, Y', strtotime($inv['doc_date'])); ?></td>
                <td style="font-weight: 600;"><a href="?page=transactions/view&id=<?php echo htmlspecialchars($inv['header_id'] ?? ''); ?>" style="color: var(--ns-primary); text-decoration: none;"><?php echo htmlspecialchars($inv['doc_number']); ?></a></td>
                <td style="text-align: right; color: <?php echo $amtColor; ?>; font-weight: <?php echo $isCM ? '700' : 'normal'; ?>;"><?php echo $amtPrefix; ?>Rs <?php echo number_format($displayAmt, 2); ?></td>
                <?php if (!$isCM): ?>
                <td style="text-align: right; color: <?php echo $inv['balance_due'] > 0.01 ? '#c00' : '#28a745'; ?>;">Rs <?php echo number_format($inv['balance_due'], 2); ?></td>
                <td><span style="text-transform: uppercase; font-size: 11px; font-weight: 700; color: <?php echo in_array(strtolower($inv['payment_status']), ['paid', 'posted', 'closed']) ? '#080' : '#c00'; ?>;"><?php echo htmlspecialchars($inv['payment_status']); ?></span></td>
                <?php else: ?>
                <td colspan="2" style="text-align:center; color:#999; font-size:11px;">Return / Credit Applied</td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background: #f8fafc; font-weight: 700; border-top: 2px solid #cbd5e1;">
                <td colspan="3" style="text-align: right; padding: 10px 12px; font-size: 13px;">NET TOTAL (after returns):</td>
                <td style="text-align: right; color: <?php echo $tab_inv_total >= 0 ? '#16a34a' : '#dc2626'; ?>; font-size: 13px;">Rs <?php echo number_format($tab_inv_total, 2); ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- Payments -->
<div id="tab-payments" class="ns-tab-content">
    <table class="ns-table">
        <thead>
            <tr>
                <th>Payment Date</th>
                <th>Payment #</th>
                <th>Payment Method</th>
                <th>Applied Invoices</th>
                <th style="text-align: right;">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $tab_pay_total = 0;
            foreach($payments as $p): 
                $tab_pay_total += floatval($p['total_amount']);
            ?>
            <tr>
                <td><?php echo date('M d, Y', strtotime($p['txn_date'])); ?></td>
                <td style="font-weight: 600;"><a href="?page=transactions/view&id=<?php echo htmlspecialchars($p['header_id'] ?? ''); ?>" style="color: var(--ns-primary); text-decoration: none;"><?php echo htmlspecialchars($p['txn_number']); ?></a></td>
                <td><span style="text-transform: uppercase; font-size: 11px; font-weight: 700; color: #475569;"><?php echo htmlspecialchars(str_replace('_', ' ', $p['payment_methods'])); ?></span></td>
                <td><?php echo htmlspecialchars($p['applied_invoices'] ?: '-'); ?></td>
                <td style="text-align: right; font-weight: bold;">Rs <?php echo number_format($p['total_amount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background: #f8fafc; font-weight: 700; border-top: 2px solid #cbd5e1;">
                <td colspan="4" style="text-align: right; padding: 10px 12px; font-size: 13px;">SUM TOTAL:</td>
                <td style="text-align: right; color: #2563eb; font-size: 13px;">Rs <?php echo number_format($tab_pay_total, 2); ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- System Information -->
<div id="tab-system" class="ns-tab-content">
    <div class="detail-grid" style="margin-bottom: 30px;">
        <div>
            <div class="detail-group">
                <div class="detail-label">Date Created</div>
                <div class="detail-value"><?php echo isset($customer['created_at']) ? date('F d, Y h:i A', strtotime($customer['created_at'])) : 'N/A'; ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Last Modified</div>
                <div class="detail-value"><?php echo isset($customer['updated_at']) ? date('F d, Y h:i A', strtotime($customer['updated_at'])) : 'N/A'; ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Internal ID</div>
                <div class="detail-value" style="font-family: monospace;"><?php echo $customer['id']; ?></div>
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
                    if ($log['action'] == 'update' && empty($diffs)) continue;
                    if (($log['action'] == 'save' || $log['action'] == 'delete' || $log['action'] == 'create') && empty($diffs)):
                ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                        <td style="color: #64748b; font-style: italic;">Record <?php echo ucfirst($log['action']); ?>d</td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php else: foreach($diffs as $field => $changes): ?>
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
function nsOpenTab(tabId, element) {
    document.querySelectorAll('.ns-tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.ns-tab').forEach(el => el.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    element.classList.add('active');
}
</script>
