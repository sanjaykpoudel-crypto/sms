<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];

if ($id) {
    $data = $db->fetchOne("SELECT * FROM locations WHERE id = ?", [$id]);
}

$location_types = [
    'Warehouse',
    'Retail Store',
    'Wholesale Outlet',
    'Storeroom',
    'Transit Depot'
];
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : 'New'; ?> Location</div>
    <div class="ns-page-actions">
        <button type="submit" form="location-form" class="ns-btn ns-btn-primary">Save</button>
        <?php if ($id): ?>
            <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;"
                onclick="nsDelete('locations', '<?php echo $id; ?>', function() { window.location.href = '?page=system/settings/accounting&type=location'; })">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        <?php endif; ?>
        <a href="?page=system/settings/accounting&type=location" class="ns-btn">Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="location-form" method="POST" action="api/transaction_handler.php">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id ?? ''); ?>">

        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Location Name *</label>
                    <input type="text" name="name" class="ns-input"
                        value="<?php echo htmlspecialchars($data['name'] ?? ''); ?>"
                        placeholder="e.g. Main Warehouse, Retail Store A" required>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Location Type *</label>
                    <select name="type" class="ns-select" required>
                        <option value="">Select Location Type</option>
                        <?php foreach ($location_types as $lt): ?>
                            <option value="<?php echo htmlspecialchars($lt); ?>" <?php echo ($data['type'] ?? '') === $lt ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lt); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Description & Details</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Description</label>
                    <textarea name="description" class="ns-input" rows="3"
                        placeholder="Enter location address, contact, or purpose..."><?php echo htmlspecialchars($data['description'] ?? ''); ?></textarea>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group" style="margin-top: 24px;">
                    <label
                        style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 13px;">
                        <input type="checkbox" name="is_inactive" <?php echo (isset($data['is_active']) && $data['is_active'] == 0) ? 'checked' : ''; ?> style="width: 16px; height: 16px;">
                        Inactive
                    </label>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    document.getElementById('location-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = this;
        const submitBtn = document.querySelector('button[form="location-form"]');
        const originalText = submitBtn.innerHTML;

        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;

        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            if (key !== 'is_inactive') {
                data[key] = value;
            }
        });
        data['is_active'] = form.querySelector('[name="is_inactive"]').checked ? 0 : 1;

        const payload = {
            action: data.id ? 'update' : 'save',
            table: 'locations',
            primary_key: 'id',
            primary_value: data.id || null,
            data: data
        };

        fetch('api/transaction_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    nsNotify(res.message || 'Location saved successfully.');
                    setTimeout(() => {
                        window.location.href = '?page=system/settings/accounting&type=location';
                    }, 1200);
                } else {
                    nsNotify(res.message || 'Error occurred while saving.', 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(err => {
                nsNotify('Network error or server failed.', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
    });
</script>