<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div class='alert alert-danger'>No user ID provided.</div>";
    exit;
}

$user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);

if (!$user) {
    echo "<div class='alert alert-danger'>User not found.</div>";
    exit;
}

// Fetch related records (Transactions created by user)
$username = $user['username'];
$transactions = $db->fetchAll("
    SELECT h.id, h.txn_number, h.txn_date, h.txn_type, h.status, h.created_at 
    FROM transaction_headers h 
    WHERE h.created_by = ? OR h.created_by = ? 
    ORDER BY h.created_at DESC LIMIT 50
", [$id, $username]);
// Fetch Audit Logs
$audit_logs = $db->fetchAll("
    SELECT al.*, u.full_name as updated_by_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.record_id = :id AND al.table_name = 'users'
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
        if (in_array($key, ['updated_at', 'created_at', 'id', 'password', 'last_login'])) continue;
        
        if ((string)$oldVal !== (string)$val) {
            $diff[$key] = ['old' => $oldVal, 'new' => $val];
        }
    }
    return $diff;
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
    .ns-view-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .ns-view-table thead tr {
        background: #f1f5f9;
    }
    .ns-view-table th {
        padding: 10px 12px;
        text-align: left;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        color: #475569;
        border-bottom: 2px solid #e2e8f0;
        letter-spacing: 0.5px;
    }
    .ns-view-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .ns-view-table tbody tr:hover {
        background: #f8fafc;
    }
</style>

<div class="view-header">
    <div>
        <div class="view-title">
            <h1><?php echo htmlspecialchars($user['full_name']); ?></h1>
        </div>
        <div class="view-subtitle">Employee Username: <?php echo htmlspecialchars($user['username']); ?></div>
    </div>
    <div class="view-actions">
        <a href="?page=system/users/manage&id=<?php echo $id; ?>" class="ns-btn ns-btn-primary">Edit Employee</a>
        <a href="?page=system/users" class="ns-btn">Back to List</a>
    </div>
</div>

<div class="ns-tabs">
    <div class="ns-tab active" onclick="nsOpenTab('tab-primary', this)">Primary Information</div>
    <div class="ns-tab" onclick="nsOpenTab('tab-related', this)">Recent Transactions <span style="background:#e2e8f0;padding:2px 6px;border-radius:10px;font-size:10px;color:#1e293b;"><?php echo count($transactions); ?></span></div>
    <div class="ns-tab" onclick="nsOpenTab('tab-system', this)">System Information</div>
</div>

<!-- Primary Information -->
<div id="tab-primary" class="ns-tab-content active">
    <div class="detail-grid">
        <!-- Column 1 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Full Name</div>
                <div class="detail-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Username</div>
                <div class="detail-value"><?php echo htmlspecialchars($user['username']); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Role</div>
                <div class="detail-value"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></div>
            </div>
        </div>
        <!-- Column 2 -->
        <div>
            <div class="detail-group">
                <div class="detail-label">Email</div>
                <div class="detail-value"><?php echo htmlspecialchars($user['email'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Status</div>
                <div class="detail-value" style="color: <?php echo $user['is_active'] ? '#080' : '#c00'; ?>; font-weight: 600;">
                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Related Records -->
<div id="tab-related" class="ns-tab-content">
    <table class="ns-view-table" style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
            <tr>
                <th>Date / Time</th>
                <th>Transaction #</th>
                <th>Type</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($transactions)): ?>
            <tr><td colspan="4" style="text-align:center; padding:20px; color:#999;">No recent transactions created by this user.</td></tr>
            <?php else: foreach($transactions as $txn): ?>
            <tr>
                <td>
                    <div style="font-weight: 500; color: #1e293b;"><?php echo date('M d, Y', strtotime($txn['txn_date'])); ?></div>
                    <div style="font-size: 11px; color: #64748b;"><?php echo date('h:i A', strtotime($txn['created_at'])); ?></div>
                </td>
                <td style="font-weight: 600;"><a href="?page=transactions/view&id=<?php echo htmlspecialchars($txn['id'] ?? ''); ?>" style="color: var(--ns-primary); text-decoration: none;"><?php echo htmlspecialchars($txn['txn_number']); ?></a></td>
                <td><span style="background: #eef2f6; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #475569;"><?php echo str_replace('_', ' ', htmlspecialchars($txn['txn_type'])); ?></span></td>
                <td><span style="text-transform: uppercase; font-size: 11px; font-weight: 700; color: <?php echo in_array($txn['status'], ['posted', 'paid', 'approved']) ? '#080' : '#c00'; ?>;"><?php echo htmlspecialchars($txn['status']); ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- System Information -->
<div id="tab-system" class="ns-tab-content">
    <div class="detail-grid" style="margin-bottom: 30px;">
        <div>
            <div class="detail-group">
                <div class="detail-label">Date Created</div>
                <div class="detail-value"><?php echo isset($user['created_at']) ? date('F d, Y h:i A', strtotime($user['created_at'])) : 'N/A'; ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Last Login</div>
                <div class="detail-value"><?php echo isset($user['last_login']) && $user['last_login'] ? date('F d, Y h:i A', strtotime($user['last_login'])) : 'Never logged in'; ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Internal ID</div>
                <div class="detail-value" style="font-family: monospace;"><?php echo $user['id']; ?></div>
            </div>
        </div>
    </div>

    <h3 style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px;">System Notes / Change Log</h3>
    <?php if(count($audit_logs) == 0): ?>
        <p style="color: #888; font-style: italic;">No changes recorded yet.</p>
    <?php else: ?>
        <table class="ns-view-table" style="width: 100%; font-size: 13px; border-collapse:collapse;">
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
                    $diffs = getDiff($log['old_value'], $log['new_value']);
                    if ($log['action_type'] == 'update' && empty($diffs)) continue;
                    if (($log['action_type'] == 'save' || $log['action_type'] == 'delete') && empty($diffs)):
                ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($log['updated_by_name'] ?? 'System'); ?></strong></td>
                        <td colspan="3" style="color: #64748b; font-style: italic;">
                            Record <?php echo ucfirst($log['action_type']); ?>d
                        </td>
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
