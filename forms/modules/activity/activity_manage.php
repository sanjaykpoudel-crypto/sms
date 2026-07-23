<?php
require_once 'database/DBConnection.php';
$db = db();
$id   = $_GET['id'] ?? null;
$data = [];

if ($id) {
    $data = $db->fetchOne("SELECT * FROM activities WHERE id = ? AND is_deleted = 0", [$id]);
    if (!$data) { $id = null; $data = []; }
}

$users     = $db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
$customers = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
$vendors   = $db->fetchAll("SELECT id, company_name AS full_name FROM vendors WHERE is_active = 1 AND is_deleted = 0 ORDER BY company_name ASC");

// Guard against redeclaration when PHP opcode cache reuses the scope
if (!function_exists('fmt_dt_local')) {
    function fmt_dt_local($dt) {
        if (!$dt) return '';
        return date('Y-m-d\TH:i', strtotime($dt));
    }
}

$current_user_id = $_SESSION['user_id'] ?? '';
?>

<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Activity</div>
    <div class="ns-page-actions">
        <button type="submit" form="activity-form" id="activity-save-btn" class="ns-btn ns-btn-primary">
            <?php echo $id ? 'Save Changes' : 'Save'; ?>
        </button>
        <button type="button" onclick="history.back()" class="ns-btn">Cancel</button>
    </div>
</div>

<div class="ns-form-container">
    <form id="activity-form" method="POST" action="#">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id ?? ''); ?>">

        <!-- ── General Information ────────────────────────── -->
        <div class="ns-section-title">General Information</div>
        <div class="ns-form-row">
            <div style="flex:1;">
                <div class="ns-form-group">
                    <label class="ns-label">Subject / Title *</label>
                    <input type="text" name="title" class="ns-input"
                           value="<?php echo htmlspecialchars($data['title'] ?? ''); ?>"
                           required placeholder="Enter activity subject">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Activity Type *</label>
                    <select name="activity_type" id="activity_type" class="ns-select" required>
                        <option value="task"       <?php echo ($data['activity_type'] ?? 'task') === 'task'       ? 'selected' : ''; ?>>Task</option>
                        <option value="event"      <?php echo ($data['activity_type'] ?? '')     === 'event'      ? 'selected' : ''; ?>>Event</option>
                        <option value="phone_call" <?php echo ($data['activity_type'] ?? '')     === 'phone_call' ? 'selected' : ''; ?>>Phone Call</option>
                        <option value="meeting"    <?php echo ($data['activity_type'] ?? '')     === 'meeting'    ? 'selected' : ''; ?>>Meeting</option>
                    </select>
                </div>
            </div>
            <div style="flex:1;">
                <div class="ns-form-group">
                    <label class="ns-label">Priority *</label>
                    <select name="priority" class="ns-select" required>
                        <option value="low"    <?php echo ($data['priority'] ?? '')       === 'low'    ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo ($data['priority'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="high"   <?php echo ($data['priority'] ?? '')       === 'high'   ? 'selected' : ''; ?>>High</option>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Status *</label>
                    <select name="status" class="ns-select" required>
                        <option value="not_started" <?php echo ($data['status'] ?? 'not_started') === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                        <option value="in_progress" <?php echo ($data['status'] ?? '')            === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed"   <?php echo ($data['status'] ?? '')            === 'completed'   ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled"   <?php echo ($data['status'] ?? '')            === 'cancelled'   ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ── Schedule ───────────────────────────────────── -->
        <div class="ns-section-title">Schedule</div>
        <div class="ns-form-row">
            <div style="flex:1;display:flex;flex-direction:column;">
                <div class="ns-form-group" id="due-date-container">
                    <label class="ns-label">Due Date</label>
                    <input type="datetime-local" name="due_date" id="due_date" class="ns-input"
                           value="<?php echo fmt_dt_local($data['due_date'] ?? ''); ?>">
                </div>
                <div class="ns-form-group" id="start-date-container" style="display:none;">
                    <label class="ns-label">Start Date &amp; Time</label>
                    <input type="datetime-local" name="start_date" id="start_date" class="ns-input"
                           value="<?php echo fmt_dt_local($data['start_date'] ?? ''); ?>">
                </div>
            </div>
            <div style="flex:1;display:flex;flex-direction:column;">
                <div class="ns-form-group" id="end-date-container" style="display:none;">
                    <label class="ns-label">End Date &amp; Time</label>
                    <input type="datetime-local" name="end_date" id="end_date" class="ns-input"
                           value="<?php echo fmt_dt_local($data['end_date'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- ── Relationships & Assignment ─────────────────── -->
        <div class="ns-section-title">Relationships &amp; Assignment</div>
        <div class="ns-form-row">
            <div style="flex:1;">
                <div class="ns-form-group">
                    <label class="ns-label">Assigned To</label>
                    <select name="assigned_to" class="ns-select">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['id']); ?>"
                            <?php echo ($data['assigned_to'] ?? '') === $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="flex:1;">
                <div class="ns-form-group">
                    <label class="ns-label">Related Customer <small style="color:#888;">(optional)</small></label>
                    <select name="customer_id" id="customer_id" class="ns-select"
                            onchange="actToggleRelation('customer')">
                        <option value="">None</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['id']); ?>"
                            <?php echo ($data['customer_id'] ?? '') === $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Related Vendor <small style="color:#888;">(optional)</small></label>
                    <select name="vendor_id" id="vendor_id" class="ns-select"
                            onchange="actToggleRelation('vendor')">
                        <option value="">None</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?php echo htmlspecialchars($v['id']); ?>"
                            <?php echo ($data['vendor_id'] ?? '') === $v['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ── Description ────────────────────────────────── -->
        <div class="ns-section-title">Notes / Description</div>
        <div class="ns-form-group">
            <textarea name="description" class="ns-input" rows="6"
                      placeholder="Enter details, action items, or meeting minutes..."
                      style="width:100%;font-family:inherit;resize:vertical;box-sizing:border-box;padding:10px;"
            ><?php echo htmlspecialchars($data['description'] ?? ''); ?></textarea>
        </div>
    </form>
</div>

<script>
(function() {
    // ── Date field toggling based on activity type ──────────
    var typeSelect  = document.getElementById('activity_type');
    var dueCont     = document.getElementById('due-date-container');
    var startCont   = document.getElementById('start-date-container');
    var endCont     = document.getElementById('end-date-container');
    var dueInput    = document.getElementById('due_date');
    var startInput  = document.getElementById('start_date');
    var endInput    = document.getElementById('end_date');

    function handleTypeChange() {
        var isTask = typeSelect.value === 'task';
        dueCont.style.display   = isTask ? 'block' : 'none';
        startCont.style.display = isTask ? 'none'  : 'block';
        endCont.style.display   = isTask ? 'none'  : 'block';

        if (isTask) {
            startInput.value = '';
            endInput.value   = '';
        } else {
            dueInput.value   = '';
        }
    }

    typeSelect.addEventListener('change', handleTypeChange);
    handleTypeChange();

    // ── Mutual exclusion: Customer ↔ Vendor ──────────────
    window.actToggleRelation = function(source) {
        if (source === 'customer' && document.getElementById('customer_id').value) {
            document.getElementById('vendor_id').value = '';
        } else if (source === 'vendor' && document.getElementById('vendor_id').value) {
            document.getElementById('customer_id').value = '';
        }
    };

    // ── Form submission ───────────────────────────────────
    var currentUserId = <?php echo json_encode($current_user_id); ?>;

    document.getElementById('activity-form').addEventListener('submit', function(e) {
        e.preventDefault();

        var saveBtn = document.getElementById('activity-save-btn');
        var origText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        saveBtn.disabled  = true;

        var formData = new FormData(this);
        var payload_data = {};
        formData.forEach(function(val, key) { payload_data[key] = val; });

        var existingId = payload_data['id'];

        // Clean up empty / falsy relationship / date values → send as null
        ['customer_id','vendor_id','assigned_to','start_date','end_date','due_date'].forEach(function(k) {
            if (!payload_data[k]) payload_data[k] = null;
        });

        // Inject creator on new records only
        if (!existingId) {
            delete payload_data['id']; // Remove empty-string id so handler auto-generates UUID
            payload_data['created_by'] = currentUserId || null;
        }

        var envelope = {
            action        : existingId ? 'update' : 'save',
            table         : 'activities',
            primary_key   : 'id',
            primary_value : existingId || null,
            data          : payload_data
        };

        fetch('api/transaction_handler.php', {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify(envelope)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.status === 'success') {
                nsNotify(res.message);
                setTimeout(function() {
                    window.location.href = '?page=activity/view&id=' + res.id;
                }, 1200);
            } else {
                nsNotify(res.message || 'Error occurred while saving.', 'error');
                saveBtn.innerHTML = origText;
                saveBtn.disabled  = false;
            }
        })
        .catch(function() {
            nsNotify('Network error. Please try again.', 'error');
            saveBtn.innerHTML = origText;
            saveBtn.disabled  = false;
        });
    });
})();
</script>
