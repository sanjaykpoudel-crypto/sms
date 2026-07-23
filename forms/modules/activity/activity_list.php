<?php
require_once 'database/DBConnection.php';
$db = db();

// Fetch filter options
$users     = $db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");

// Parse filters from GET request
$type_filter        = $_GET['type']        ?? '';
$status_filter      = $_GET['status']      ?? '';
$priority_filter    = $_GET['priority']    ?? '';
$assigned_to_filter = $_GET['assigned_to'] ?? '';

// Build query using positional ? params (matches codebase style)
$where_clauses = ["a.is_deleted = 0"];
$params = [];

if ($type_filter) {
    $where_clauses[] = "a.activity_type = ?";
    $params[] = $type_filter;
}
if ($status_filter) {
    $where_clauses[] = "a.status = ?";
    $params[] = $status_filter;
}
if ($priority_filter) {
    $where_clauses[] = "a.priority = ?";
    $params[] = $priority_filter;
}
if ($assigned_to_filter) {
    $where_clauses[] = "a.assigned_to = ?";
    $params[] = ($assigned_to_filter === 'me') ? ($_SESSION['user_id'] ?? '') : $assigned_to_filter;
}

$where_sql = implode(' AND ', $where_clauses);

$query = "
    SELECT a.*,
           u.full_name  AS assignee_name,
           c.full_name  AS customer_name,
           v.company_name AS vendor_name
    FROM activities a
    LEFT JOIN users     u ON a.assigned_to = u.id
    LEFT JOIN customers c ON a.customer_id = c.id
    LEFT JOIN vendors   v ON a.vendor_id   = v.id
    WHERE $where_sql
    ORDER BY a.created_at DESC
";

$activities = $db->fetchAll($query, $params);

// Maps
$type_map = [
    'task'       => ['label' => 'Task',       'icon' => 'fa-check-square', 'color' => '#3498db'],
    'event'      => ['label' => 'Event',      'icon' => 'fa-calendar-alt', 'color' => '#e67e22'],
    'phone_call' => ['label' => 'Phone Call', 'icon' => 'fa-phone-alt',    'color' => '#2ecc71'],
    'meeting'    => ['label' => 'Meeting',    'icon' => 'fa-users',         'color' => '#9b59b6'],
];
$status_map = [
    'not_started' => ['label' => 'Not Started', 'color' => '#95a5a6'],
    'in_progress' => ['label' => 'In Progress', 'color' => '#e67e22'],
    'completed'   => ['label' => 'Completed',   'color' => '#2ecc71'],
    'cancelled'   => ['label' => 'Cancelled',   'color' => '#e74c3c'],
];
$priority_map = [
    'low'    => ['label' => 'Low',    'color' => '#95a5a6'],
    'medium' => ['label' => 'Medium', 'color' => '#e67e22'],
    'high'   => ['label' => 'High',   'color' => '#e74c3c'],
];
?>

<style>
    .filter-panel {
        background: #fff;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: flex-end;
    }
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .filter-group label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        color: #64748b;
    }
    .filter-select {
        padding: 6px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        min-width: 150px;
        font-size: 13px;
        outline: none;
        background-color: #fff;
    }
    .filter-select:focus { border-color: var(--ns-primary); }
    .activity-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        color: #fff;
    }
    .priority-indicator {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 600;
    }
    .priority-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .status-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
    }
</style>

<div class="ns-page-header">
    <h1 class="ns-page-title">
        Activities
        <a href="?page=activity/manage" class="ns-btn ns-btn-primary" style="margin-left: 10px;">
            <i class="fas fa-plus"></i> New Activity
        </a>
        <a href="?page=activity/calendar" class="ns-btn" style="margin-left: 5px;">
            <i class="fas fa-calendar-alt"></i> Calendar
        </a>
    </h1>
</div>

<div class="filter-panel">
    <form method="GET" action="" id="activity-filter-form">
        <input type="hidden" name="page" value="activity">
        <div class="filter-row">
            <div class="filter-group">
                <label>Type</label>
                <select name="type" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="task"       <?php echo $type_filter === 'task'       ? 'selected' : ''; ?>>Task</option>
                    <option value="event"      <?php echo $type_filter === 'event'      ? 'selected' : ''; ?>>Event</option>
                    <option value="phone_call" <?php echo $type_filter === 'phone_call' ? 'selected' : ''; ?>>Phone Call</option>
                    <option value="meeting"    <?php echo $type_filter === 'meeting'    ? 'selected' : ''; ?>>Meeting</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="not_started" <?php echo $status_filter === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed"   <?php echo $status_filter === 'completed'   ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled"   <?php echo $status_filter === 'cancelled'   ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Priority</label>
                <select name="priority" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Priorities</option>
                    <option value="low"    <?php echo $priority_filter === 'low'    ? 'selected' : ''; ?>>Low</option>
                    <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="high"   <?php echo $priority_filter === 'high'   ? 'selected' : ''; ?>>High</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Assigned To</label>
                <select name="assigned_to" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Assignees</option>
                    <option value="me" <?php echo $assigned_to_filter === 'me' ? 'selected' : ''; ?>>My Activities</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['id']); ?>"
                            <?php echo $assigned_to_filter === $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <a href="?page=activity" class="ns-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <?php if (empty($activities)): ?>
            <div style="padding: 50px; text-align: center; color: #888;">
                <i class="fas fa-tasks" style="font-size: 40px; opacity: 0.2; display: block; margin-bottom: 12px;"></i>
                <div style="font-size: 16px; font-weight: 600;">No activities found</div>
                <div style="font-size: 13px; margin-top: 6px;">
                    <a href="?page=activity/manage" class="ns-btn ns-btn-primary" style="margin-top: 15px; display: inline-block;">
                        <i class="fas fa-plus"></i> Create your first activity
                    </a>
                </div>
            </div>
        <?php else: ?>
        <table class="ns-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Subject / Title</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th>Dates</th>
                    <th>Related To</th>
                    <th width="90">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activities as $row):
                    $ti = $type_map[$row['activity_type']] ?? $type_map['task'];
                    $si = $status_map[$row['status']]       ?? $status_map['not_started'];
                    $pi = $priority_map[$row['priority']]   ?? $priority_map['medium'];
                ?>
                <tr>
                    <td>
                        <span class="activity-badge" style="background:<?php echo $ti['color']; ?>;">
                            <i class="fas <?php echo $ti['icon']; ?>"></i>
                            <?php echo $ti['label']; ?>
                        </span>
                    </td>
                    <td style="font-weight:600;">
                        <a href="?page=activity/view&id=<?php echo $row['id']; ?>"
                           style="color:var(--ns-primary);text-decoration:none;">
                            <?php echo htmlspecialchars($row['title']); ?>
                        </a>
                    </td>
                    <td>
                        <div class="priority-indicator">
                            <span class="priority-dot" style="background:<?php echo $pi['color']; ?>;"></span>
                            <?php echo $pi['label']; ?>
                        </div>
                    </td>
                    <td>
                        <span class="status-badge"
                              style="background:<?php echo $si['color']; ?>22;color:<?php echo $si['color']; ?>;">
                            <?php echo $si['label']; ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($row['assignee_name'] ?: '—'); ?></td>
                    <td style="font-size:12px;color:#555;">
                        <?php if ($row['activity_type'] === 'task'): ?>
                            <strong>Due:</strong>
                            <?php echo $row['due_date'] ? date('M d, Y g:i A', strtotime($row['due_date'])) : 'No due date'; ?>
                        <?php else: ?>
                            <strong>Start:</strong>
                            <?php echo $row['start_date'] ? date('M d, Y g:i A', strtotime($row['start_date'])) : '—'; ?><br>
                            <strong>End:</strong>
                            <?php echo $row['end_date'] ? date('M d, Y g:i A', strtotime($row['end_date'])) : '—'; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['customer_id'] && $row['customer_name']): ?>
                            <a href="?page=master/customer/view&id=<?php echo $row['customer_id']; ?>"
                               style="color:var(--ns-primary);text-decoration:none;">
                                <i class="fas fa-user-friends" style="font-size:11px;"></i>
                                <?php echo htmlspecialchars($row['customer_name']); ?>
                            </a>
                        <?php elseif ($row['vendor_id'] && $row['vendor_name']): ?>
                            <a href="?page=master/vendor/view&id=<?php echo $row['vendor_id']; ?>"
                               style="color:var(--ns-primary);text-decoration:none;">
                                <i class="fas fa-user-tie" style="font-size:11px;"></i>
                                <?php echo htmlspecialchars($row['vendor_name']); ?>
                            </a>
                        <?php else: ?>
                            <span style="color:#bbb;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <a href="?page=activity/view&id=<?php echo $row['id']; ?>"
                               class="ns-btn" title="View"><i class="fas fa-eye"></i></a>
                            <a href="?page=activity/manage&id=<?php echo $row['id']; ?>"
                               class="ns-btn" title="Edit"><i class="fas fa-edit"></i></a>
                            <button class="ns-btn" style="color:#c00;" title="Delete"
                                    onclick="nsDelete('activities','<?php echo $row['id']; ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
