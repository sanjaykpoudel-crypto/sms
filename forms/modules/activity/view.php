<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div class='alert alert-danger'>No activity ID provided.</div>";
    exit;
}

$activity = $db->fetchOne("
    SELECT a.*, 
           u.full_name AS assignee_name,
           c.full_name AS customer_name,
           v.company_name AS vendor_name,
           creator.full_name AS creator_name
    FROM activities a
    LEFT JOIN users u ON a.assigned_to = u.id
    LEFT JOIN customers c ON a.customer_id = c.id
    LEFT JOIN vendors v ON a.vendor_id = v.id
    LEFT JOIN users creator ON a.created_by = creator.id
    WHERE a.id = ? AND a.is_deleted = 0
", [$id]);

if (!$activity) {
    echo "<div class='alert alert-danger'>Activity not found or has been deleted.</div>";
    exit;
}

// Fetch Audit Logs/System Notes for this activity
$audit_logs = $db->fetchAll("
    SELECT al.*, u.full_name as updated_by_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.record_id = :id AND al.table_name = 'activities'
    ORDER BY al.created_at DESC
", ['id' => $id]);

if (!function_exists('activity_getDiff')) {
    function activity_getDiff($oldJson, $newJson) {
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

// Maps
$type_map = [
    'task' => ['label' => 'Task', 'icon' => 'fa-check-square', 'color' => '#3498db'],
    'event' => ['label' => 'Event', 'icon' => 'fa-calendar-alt', 'color' => '#e67e22'],
    'phone_call' => ['label' => 'Phone Call', 'icon' => 'fa-phone-alt', 'color' => '#2ecc71'],
    'meeting' => ['label' => 'Meeting', 'icon' => 'fa-users', 'color' => '#9b59b6']
];

$status_map = [
    'not_started' => ['label' => 'Not Started', 'color' => '#95a5a6'],
    'in_progress' => ['label' => 'In Progress', 'color' => '#f1c40f'],
    'completed' => ['label' => 'Completed', 'color' => '#2ecc71'],
    'cancelled' => ['label' => 'Cancelled', 'color' => '#e74c3c']
];

$priority_map = [
    'low' => ['label' => 'Low', 'color' => '#95a5a6'],
    'medium' => ['label' => 'Medium', 'color' => '#e67e22'],
    'high' => ['label' => 'High', 'color' => '#e74c3c']
];

$type_info = $type_map[$activity['activity_type']];
$status_info = $status_map[$activity['status']];
$prio_info = $priority_map[$activity['priority']];
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
        gap: 12px;
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
    .activity-indicator-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 20px;
        color: #fff;
        font-size: 13px;
        font-weight: bold;
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
        padding: 25px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .ns-tab-content.active {
        display: block;
    }
    
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px;
    }
    .detail-group {
        margin-bottom: 20px;
    }
    .detail-label {
        font-size: 11px;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .detail-value {
        font-size: 14px;
        color: #1e293b;
        font-weight: 500;
    }
    .desc-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 15px;
        font-size: 14px;
        line-height: 1.6;
        color: #334155;
        white-space: pre-wrap;
    }
</style>

<div class="view-header">
    <div>
        <div class="view-title">
            <h1>
                <span class="activity-indicator-badge" style="background-color: <?php echo $type_info['color']; ?>;">
                    <i class="fas <?php echo $type_info['icon']; ?>"></i>
                    <?php echo $type_info['label']; ?>
                </span>
                <?php echo htmlspecialchars($activity['title']); ?>
            </h1>
        </div>
        <div class="view-subtitle">
            Priority: <strong style="color: <?php echo $prio_info['color']; ?>;"><?php echo $prio_info['label']; ?></strong> &bull; 
            Status: <span style="background-color: <?php echo $status_info['color']; ?>22; color: <?php echo $status_info['color']; ?>; font-weight: bold; padding: 2px 6px; border-radius: 4px; font-size: 11px; text-transform: uppercase;"><?php echo $status_info['label']; ?></span>
        </div>
    </div>
    <div class="view-actions">
        <a href="?page=activity/manage&id=<?php echo $id; ?>" class="ns-btn ns-btn-primary"><i class="fas fa-edit"></i> Edit</a>
        <button class="ns-btn" style="color: #c00;" onclick="deleteAndRedirect('<?php echo $id; ?>')"><i class="fas fa-trash"></i> Delete</button>
        <a href="?page=activity" class="ns-btn"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

<div class="ns-tabs">
    <div class="ns-tab active" onclick="nsOpenTab('tab-details', this)">Details</div>
    <div class="ns-tab" onclick="nsOpenTab('tab-system', this)">System Information</div>
</div>

<!-- Details Tab -->
<div id="tab-details" class="ns-tab-content active">
    <div class="detail-grid">
        <!-- Column 1: Schedule -->
        <div>
            <div class="ns-section-title" style="margin-top: 0;">Schedule Details</div>
            
            <?php if ($activity['activity_type'] === 'task'): ?>
                <div class="detail-group">
                    <div class="detail-label">Due Date</div>
                    <div class="detail-value" style="font-weight: 600; color: <?php echo (strtotime($activity['due_date']) < time() && $activity['status'] !== 'completed') ? '#c00' : '#1e293b'; ?>;">
                        <?php echo $activity['due_date'] ? date('F d, Y h:i A', strtotime($activity['due_date'])) : 'No due date'; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="detail-group">
                    <div class="detail-label">Start Time</div>
                    <div class="detail-value">
                        <?php echo $activity['start_date'] ? date('F d, Y h:i A', strtotime($activity['start_date'])) : 'N/A'; ?>
                    </div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">End Time</div>
                    <div class="detail-value">
                        <?php echo $activity['end_date'] ? date('F d, Y h:i A', strtotime($activity['end_date'])) : 'N/A'; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Column 2: Assignment & Connections -->
        <div>
            <div class="ns-section-title" style="margin-top: 0;">Assignment & Relations</div>
            <div class="detail-group">
                <div class="detail-label">Assigned To</div>
                <div class="detail-value" style="font-weight: 600;">
                    <?php echo htmlspecialchars($activity['assignee_name'] ?: 'Unassigned'); ?>
                </div>
            </div>
            
            <div class="detail-group">
                <div class="detail-label">Related Customer</div>
                <div class="detail-value">
                    <?php if ($activity['customer_id']): ?>
                        <a href="?page=master/customer/view&id=<?php echo $activity['customer_id']; ?>" style="color: var(--ns-primary); text-decoration: none; font-weight: 600;">
                            <i class="fas fa-user-friends"></i> <?php echo htmlspecialchars($activity['customer_name']); ?>
                        </a>
                    <?php else: ?>
                        <span style="color: #888;">None</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="detail-group">
                <div class="detail-label">Related Vendor</div>
                <div class="detail-value">
                    <?php if ($activity['vendor_id']): ?>
                        <a href="?page=master/vendor/view&id=<?php echo $activity['vendor_id']; ?>" style="color: var(--ns-primary); text-decoration: none; font-weight: 600;">
                            <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($activity['vendor_name']); ?>
                        </a>
                    <?php else: ?>
                        <span style="color: #888;">None</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Description Block -->
    <div style="margin-top: 25px;">
        <div class="ns-section-title">Notes / Description</div>
        <div class="desc-box">
            <?php echo $activity['description'] ? htmlspecialchars($activity['description']) : '<span style="color:#888; font-style:italic;">No description/notes provided.</span>'; ?>
        </div>
    </div>
</div>

<!-- System Info Tab -->
<div id="tab-system" class="ns-tab-content">
    <div class="detail-grid" style="margin-bottom: 30px;">
        <div>
            <div class="detail-group">
                <div class="detail-label">Created By</div>
                <div class="detail-value"><?php echo htmlspecialchars($activity['creator_name'] ?: 'System'); ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Date Created</div>
                <div class="detail-value"><?php echo date('F d, Y h:i A', strtotime($activity['created_at'])); ?></div>
            </div>
        </div>
        <div>
            <div class="detail-group">
                <div class="detail-label">Internal ID</div>
                <div class="detail-value" style="font-family: monospace; font-size: 13px;"><?php echo $activity['id']; ?></div>
            </div>
            <div class="detail-group">
                <div class="detail-label">Last Modified</div>
                <div class="detail-value"><?php echo date('F d, Y h:i A', strtotime($activity['updated_at'])); ?></div>
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
                    $diffs = activity_getDiff($log['old_values'] ?? '', $log['new_values'] ?? '');
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

function deleteAndRedirect(activityId) {
    nsDelete('activities', activityId, function() {
        window.location.href = '?page=activity';
    });
}
</script>
