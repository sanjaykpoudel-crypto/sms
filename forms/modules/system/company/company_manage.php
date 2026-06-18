<?php
// This file is loaded via include from index.php, so $db is already available.
// But we call db() defensively in case this is ever accessed differently.
if (!isset($db)) $db = db();

$info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$sys = [];
foreach($info as $row) {
    $sys[$row['meta_field']] = $row['meta_value'];
}

// Build logo src for display
// __FILE__ = sms/forms/modules/system/company/company_manage.php
// We need sms/ root = dirname 4 levels up
$sms_root  = dirname(__FILE__, 5); // company -> system -> modules -> forms -> sms
$logo_db   = $sys['logo'] ?? '';
$logo_abs  = $logo_db ? ($sms_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logo_db)) : '';
$logo_src  = ($logo_abs && file_exists($logo_abs)) ? ($logo_db . '?v=' . time()) : '';
?>

<div class="ns-card">
    <div class="ns-card-header">
        <h2 class="ns-card-title"><i class="fas fa-building" style="margin-right: 10px;"></i> System Information & Print Header</h2>
        <div class="ns-card-tools">
            <button type="button" id="btn-save-company" class="ns-btn ns-btn-primary"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </div>
    <div class="ns-card-body">
        <form id="company-form" enctype="multipart/form-data">

            <div style="display: flex; gap: 30px; align-items: flex-start;">

                <!-- Logo Column -->
                <div style="flex: 0 0 180px; text-align: center;">
                    <label class="ns-label" style="display:block; margin-bottom:8px;">Company Logo</label>
                    <div style="border: 2px dashed #dde2e8; border-radius: 10px; padding: 15px; background: #fafafa; min-height: 140px; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                        <?php if ($logo_src): ?>
                            <img src="<?php echo htmlspecialchars($logo_src); ?>" id="logo-preview" style="max-width:100%; max-height:140px; object-fit:contain;">
                        <?php else: ?>
                            <div id="logo-placeholder" style="color:#bbb; font-size:13px; text-align:center;">
                                <i class="fas fa-image" style="font-size:40px; display:block; margin-bottom:8px;"></i>
                                No Logo Uploaded
                            </div>
                        <?php endif; ?>
                    </div>
                    <input type="file" name="img" id="logo-input" accept="image/*" style="display:none;" onchange="previewLogo(this)">
                    <button type="button" class="ns-btn ns-btn-sm" style="margin-top:10px; width:100%;" onclick="document.getElementById('logo-input').click()">
                        <i class="fas fa-upload"></i> Change Logo
                    </button>
                </div>

                <!-- Details Column -->
                <div style="flex: 1;">
                    <div class="ns-section-title">Company Details</div>
                    <div class="ns-form-row">
                        <div class="ns-form-group" style="flex: 2;">
                            <label class="ns-label required">Company Legal Name</label>
                            <input type="text" name="name" class="ns-input" value="<?php echo htmlspecialchars($sys['name'] ?? ''); ?>" required>
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Invoice Title</label>
                            <input type="text" name="print_title" class="ns-input" value="<?php echo htmlspecialchars($sys['print_title'] ?? 'Tax Invoice'); ?>">
                        </div>
                    </div>

                    <div class="ns-form-row">
                        <div class="ns-form-group">
                            <label class="ns-label">PAN / VAT Number</label>
                            <input type="text" name="pan_no" class="ns-input" value="<?php echo htmlspecialchars($sys['pan_no'] ?? ''); ?>">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Phone / Contact</label>
                            <input type="text" name="contact" class="ns-input" value="<?php echo htmlspecialchars($sys['contact'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="ns-form-row">
                        <div class="ns-form-group">
                            <label class="ns-label">Email Address</label>
                            <input type="email" name="email" class="ns-input" value="<?php echo htmlspecialchars($sys['email'] ?? ''); ?>">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Website</label>
                            <input type="text" name="website" class="ns-input" value="<?php echo htmlspecialchars($sys['website'] ?? ''); ?>" placeholder="https://www.example.com">
                        </div>
                    </div>

                    <div class="ns-form-group">
                        <label class="ns-label">Company Address</label>
                        <textarea name="address" class="ns-input" rows="2"><?php echo htmlspecialchars($sys['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="ns-section-title" style="margin-top:16px;">Print Header</div>
                    <div class="ns-form-row">
                        <div class="ns-form-group">
                            <label class="ns-label">Authorized Signatory Label</label>
                            <input type="text" name="signatory_label" class="ns-input" value="<?php echo htmlspecialchars($sys['signatory_label'] ?? 'Authorized Signatory'); ?>">
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Footer Text</label>
                            <input type="text" name="print_footer_text" class="ns-input" value="<?php echo htmlspecialchars($sys['print_footer_text'] ?? ''); ?>" placeholder="e.g. Thank you for your business!">
                        </div>
                    </div>

                    <div class="ns-section-title" style="margin-top:16px;">Localization</div>
                    <div class="ns-form-row">
                        <div class="ns-form-group">
                            <label class="ns-label">Date Format</label>
                            <select name="date_format" class="ns-input">
                                <option value="Y-m-d"  <?php echo ($sys['date_format'] ?? 'Y-m-d') === 'Y-m-d'  ? 'selected' : ''; ?>>YYYY-MM-DD (2026-05-01)</option>
                                <option value="d-m-Y"  <?php echo ($sys['date_format'] ?? '') === 'd-m-Y'  ? 'selected' : ''; ?>>DD-MM-YYYY (01-05-2026)</option>
                                <option value="d/m/Y"  <?php echo ($sys['date_format'] ?? '') === 'd/m/Y'  ? 'selected' : ''; ?>>DD/MM/YYYY (01/05/2026)</option>
                                <option value="M d, Y" <?php echo ($sys['date_format'] ?? '') === 'M d, Y' ? 'selected' : ''; ?>>May 01, 2026</option>
                            </select>
                        </div>
                        <div class="ns-form-group">
                            <label class="ns-label">Decimal Places</label>
                            <select name="decimal_places" class="ns-input">
                                <option value="0" <?php echo ($sys['decimal_places'] ?? '2') === '0' ? 'selected' : ''; ?>>0 — No Decimals</option>
                                <option value="1" <?php echo ($sys['decimal_places'] ?? '') === '1' ? 'selected' : ''; ?>>1 — (0.0)</option>
                                <option value="2" <?php echo ($sys['decimal_places'] ?? '2') === '2' ? 'selected' : ''; ?>>2 — (0.00)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

        </form>
    </div>
</div>

<script>
function previewLogo(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var prev = document.getElementById('logo-preview');
            if (!prev) {
                // Create img if placeholder shown
                var ph = document.getElementById('logo-placeholder');
                var img = document.createElement('img');
                img.id = 'logo-preview';
                img.style = 'max-width:100%; max-height:140px; object-fit:contain;';
                if (ph) ph.parentNode.replaceChild(img, ph);
                else document.querySelector('[style*="min-height:140px"]').appendChild(img);
                prev = img;
            }
            prev.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

document.getElementById('btn-save-company').addEventListener('click', function() {
    var btn = this;
    var form = document.getElementById('company-form');
    var formData = new FormData(form);

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    fetch('api/system_settings.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.text(); })
    .then(function(text) {
        try {
            var data = JSON.parse(text);
            if (data.status === 'success') {
                nsNotify('System information saved successfully!', 'success');
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                nsNotify('Error: ' + data.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
            }
        } catch(e) {
            nsNotify('Server error: ' + text.substring(0, 200), 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
        }
    })
    .catch(function(err) {
        nsNotify('Network error: ' + err.message, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
    });
});
</script>
