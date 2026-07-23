<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div class='alert alert-danger'>No vendor ID provided.</div>";
    exit;
}

$vendor = $db->fetchOne("
    SELECT v.*, a.account_name 
    FROM vendors v 
    LEFT JOIN accounts a ON v.payable_account_id = a.id 
    WHERE v.id = ?
", [$id]);

if (!$vendor) {
    echo "<div class='alert alert-danger'>Vendor not found.</div>";
    exit;
}

// Fetch related records (Bills & Tagged Journals)
$bills = $db->fetchAll("
    SELECT 'Bill' as doc_type, vb.id, vb.header_id, vb.vendor_invoice_number as doc_number, vb.bill_date as doc_date, vb.total_amount, vb.balance_due, vb.payment_status 
    FROM vendor_bills vb 
    JOIN transaction_headers th ON vb.header_id = th.id
    WHERE vb.vendor_id = ? AND th.is_deleted = 0
    UNION ALL
    SELECT 'Journal' as doc_type, h.id as id, h.id as header_id, h.txn_number as doc_number, h.txn_date as doc_date,
        SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) as total_amount,
        (SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0)) as balance_due,
        h.status as payment_status
    FROM journal_entries j
    JOIN transaction_headers h ON j.header_id = h.id
    LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
    WHERE (j.party_id = ? OR h.party_id = ?) 
      AND (j.party_type = 'vendor' OR j.party_type IS NULL) 
      AND h.is_deleted = 0 
      AND h.txn_type IN ('Journal', 'journal_entry')
    GROUP BY h.id, h.txn_number, h.txn_date, h.status
    ORDER BY doc_date DESC LIMIT 50
", [$id, $id, $id]);
// Fetch related records (Payments)
$payments = $db->fetchAll("
    SELECT th.id as header_id, th.txn_number, th.txn_date, th.created_at,
           SUM(DISTINCT p.amount) as total_amount,
           GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods,
           GROUP_CONCAT(DISTINCT COALESCE(vb.vendor_invoice_number, th_child.txn_number) ORDER BY COALESCE(vb.vendor_invoice_number, th_child.txn_number) SEPARATOR ', ') as applied_bills
    FROM transaction_headers th
    JOIN payments p ON th.id = p.header_id
    LEFT JOIN transaction_links tl ON tl.parent_id = th.id
    LEFT JOIN transaction_headers th_child ON tl.child_id = th_child.id
    LEFT JOIN vendor_bills vb ON tl.child_id = vb.header_id
    WHERE p.vendor_id = ? AND th.is_deleted = 0
    GROUP BY th.id
    ORDER BY th.txn_date DESC, th.created_at DESC LIMIT 50
", [$id]);
// Fetch Audit Logs
$audit_logs = $db->fetchAll("
    SELECT al.*, u.full_name as updated_by_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.record_id = :id AND al.table_name = 'vendors'
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
    .view-subtitle {
        margin-top: 5px;
        color: #666;
        font-size: 14px;
    }
    .view-actions {
        display: flex;
        gap: 10px;
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
        text-transform: uppercase;
    }
    .detail-value {
        font-size: 14px;
        color: #1e293b;
        font-weight: 500;
    }
</style>

<div class="view-header">
    <div>
        <div class="view-title">
            <h1><?php echo htmlspecialchars($vendor['company_name']); ?></h1>
        </div>
    </div>
    <div class="view-actions">
        <a href="?page=master/vendor/manage&id=<?php echo $id; ?>" class="ns-btn ns-btn-primary"><i class="fas fa-edit"></i> Edit</a>
        <a href="?page=master/vendor" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-tabs">
    <div class="ns-tab active" onclick="nsOpenTab('tab-primary', this)">Primary Information</div>
    <div class="ns-tab" onclick="nsOpenTab('tab-related', this)">Related Bills & Journals <span style="background:#e2e8f0;padding:2px 6px;border-radius:10px;font-size:10px;color:#1e293b;"><?php echo count($bills); ?></span></div>
    <div class="ns-tab" onclick="nsOpenTab('tab-payments', this)">Payments <span style="background:#e2e8f0;padding:2px 6px;border-radius:10px;font-size:10px;color:#1e293b;"><?php echo count($payments); ?></span></div>
    <div class="ns-tab" onclick="nsOpenTab('tab-system', this)">System Information</div>
</div>

<!-- Primary Information -->
<div id="tab-primary" class="ns-tab-content active">
    <div class="detail-grid">
        <!-- Column 1 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Company Name</div>
                <div class="detail-value"><?php echo htmlspecialchars($vendor['company_name']); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Contact Name</div>
                <div class="detail-value"><?php echo htmlspecialchars($vendor['contact_name'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Status</div>
                <div class="detail-value" style="color: <?php echo $vendor['is_active'] ? '#080' : '#c00'; ?>; font-weight: 600;">
                    <?php echo $vendor['is_active'] ? 'Active' : 'Inactive'; ?>
                </div>
            </div>
        </div>
        <!-- Column 2 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Phone</div>
                <div class="detail-value"><?php echo htmlspecialchars($vendor['phone'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Email</div>
                <div class="detail-value"><?php echo htmlspecialchars($vendor['email'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">PAN/VAT Number</div>
                <div class="detail-value"><?php echo htmlspecialchars($vendor['pan_number'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Address</div>
                <div class="detail-value"><?php echo nl2br(htmlspecialchars($vendor['address'] ?: 'N/A')); ?></div>
            </div>
        </div>
        <!-- Column 3 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Payable Account</div>
                <div class="detail-value"><?php echo htmlspecialchars($vendor['account_name'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Credit Limit</div>
                <div class="detail-value">Rs <?php echo number_format($vendor['credit_limit'] ?? 0, 2); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Payment Terms</div>
                <div class="detail-value"><?php echo htmlspecialchars($vendor['payment_terms_days'] ?: '0'); ?> Days</div>
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
                <th>Reference #</th>
                <th style="text-align: right;">Total Amount</th>
                <th style="text-align: right;">Balance Due</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($bills as $bill): 
                $isJournal = ($bill['doc_type'] === 'Journal');
                $typeBadge = $isJournal ? '<span style="font-size:10px; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-weight:700;">JOURNAL</span>' : '<span style="font-size:10px; background:#fff7ed; color:#c2410c; padding:2px 6px; border-radius:4px; font-weight:700;">BILL</span>';
            ?>
            <tr>
                <td><?php echo $typeBadge; ?></td>
                <td><?php echo date('M d, Y', strtotime($bill['doc_date'])); ?></td>
                <td style="font-weight: 600;"><a href="?page=transactions/view&id=<?php echo htmlspecialchars($bill['header_id'] ?? ''); ?>" style="color: var(--ns-primary); text-decoration: none;"><?php echo htmlspecialchars($bill['doc_number']); ?></a></td>
                <td style="text-align: right;">Rs <?php echo number_format($bill['total_amount'], 2); ?></td>
                <td style="text-align: right; color: <?php echo $bill['balance_due'] > 0.01 ? '#c00' : '#28a745'; ?>;">Rs <?php echo number_format($bill['balance_due'], 2); ?></td>
                <td><span style="text-transform: uppercase; font-size: 11px; font-weight: 700; color: <?php echo in_array(strtolower($bill['payment_status']), ['paid', 'posted']) ? '#080' : '#c00'; ?>;"><?php echo htmlspecialchars($bill['payment_status']); ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
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
                <th>Applied Bills</th>
                <th style="text-align: right;">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($payments as $p): ?>
            <tr>
                <td><?php echo date('M d, Y', strtotime($p['txn_date'])); ?></td>
                <td style="font-weight: 600;"><a href="?page=transactions/view&id=<?php echo htmlspecialchars($p['header_id'] ?? ''); ?>" style="color: var(--ns-primary); text-decoration: none;"><?php echo htmlspecialchars($p['txn_number']); ?></a></td>
                <td><span style="text-transform: uppercase; font-size: 11px; font-weight: 700; color: #475569;"><?php echo htmlspecialchars(str_replace('_', ' ', $p['payment_methods'])); ?></span></td>
                <td><?php echo htmlspecialchars($p['applied_bills'] ?: '-'); ?></td>
                <td style="text-align: right; font-weight: bold;">Rs <?php echo number_format($p['total_amount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- System Information -->
<div id="tab-system" class="ns-tab-content">
    <div class="detail-grid" style="margin-bottom: 30px;">
        <div>
            <div class="detail-group">
                <div class="detail-label">Date Created</div>
                <div class="detail-value"><?php echo isset($vendor['created_at']) ? date('F d, Y h:i A', strtotime($vendor['created_at'])) : 'N/A'; ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Last Modified</div>
                <div class="detail-value"><?php echo isset($vendor['updated_at']) ? date('F d, Y h:i A', strtotime($vendor['updated_at'])) : 'N/A'; ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Internal ID</div>
                <div class="detail-value" style="font-family: monospace;"><?php echo $vendor['id']; ?></div>
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
