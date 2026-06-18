<?php
$type = $_GET['type'] ?? 'tax';
$id = $_GET['id'] ?? '';
$type_labels = [
    'tax' => 'Tax / VAT',
    'currency' => 'Currency',
    'payment_method' => 'Payment Method',
    'category' => 'Category',
    'units' => 'Unit',
    'status' => 'Status'
];

$db = db();
$item = [];
if (!empty($id)) {
    $item = $db->fetchOne("SELECT * FROM reference_codes WHERE id = :id", ['id' => $id]);
    if ($item) $type = $item['type'];
}

$name = $item['name'] ?? '';
$code = $item['code'] ?? '';
$value = $item['value'] ?? 0;
$symbol = $item['symbol'] ?? '';
$description = $item['description'] ?? '';
$is_active = $item['is_active'] ?? 1;
?>

<div class="ns-form-header">
    <div class="ns-form-title"><?php echo empty($id) ? "New" : "Edit"; ?> Accounting Entry</div>
    <div class="ns-page-actions">
        <button type="submit" form="accounting-form" class="ns-btn ns-btn-primary">Save Changes</button>
        <a href="?page=system/settings/accounting" class="ns-btn">Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="accounting-form">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        
        <div class="ns-section-title">Primary Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Item Type <span class="ns-required">*</span></label>
                    <select name="type" class="ns-select" id="type-select" required <?php echo !empty($id) ? 'disabled' : ''; ?>>
                        <?php foreach($type_labels as $val => $lab): ?>
                            <option value="<?php echo $val; ?>" <?php echo $type == $val ? 'selected' : ''; ?>><?php echo $lab; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if(!empty($id)): ?>
                        <input type="hidden" name="type" value="<?php echo $type; ?>">
                    <?php endif; ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Name / Label <span class="ns-required">*</span></label>
                    <input type="text" name="name" class="ns-input" value="<?php echo htmlspecialchars($name); ?>" required placeholder="e.g. Sales Tax, US Dollar, etc.">
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label" id="code-label">Code <span class="ns-required">*</span></label>
                    <input type="text" name="code_entry" class="ns-input" value="<?php echo htmlspecialchars($code); ?>" required placeholder="e.g. VAT, USD, BTL">
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Status</label>
                    <select name="is_active" class="ns-select">
                        <option value="1" <?php echo $is_active == 1 ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo $is_active == 0 ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Dynamic Configuration Sections -->
        
        <!-- Tax Configuration -->
        <div class="type-section section-tax" style="display: none;">
            <div class="ns-section-title">Tax Configuration</div>
            <div class="ns-form-row">
                <div style="flex: 1;">
                    <div class="ns-form-group">
                        <label class="ns-label">Tax Rate (%) <span class="ns-required">*</span></label>
                        <input type="number" step="0.01" name="value_tax" class="ns-input" value="<?php echo $type == 'tax' ? $value : '0.00'; ?>">
                    </div>
                </div>
                <div style="flex: 1;">
                    <!-- Placeholder -->
                </div>
            </div>
        </div>

        <!-- Currency Configuration -->
        <div class="type-section section-currency" style="display: none;">
            <div class="ns-section-title">Currency Configuration</div>
            <div class="ns-form-row">
                <div style="flex: 1;">
                    <div class="ns-form-group">
                        <label class="ns-label">Symbol <span class="ns-required">*</span></label>
                        <input type="text" name="symbol" class="ns-input" value="<?php echo htmlspecialchars($symbol); ?>" placeholder="e.g. $, €">
                    </div>
                </div>
                <div style="flex: 1;">
                    <div class="ns-form-group">
                        <label class="ns-label">Exchange Rate <span class="ns-required">*</span></label>
                        <input type="number" step="0.0001" name="value_currency" class="ns-input" value="<?php echo $type == 'currency' ? $value : '1.0000'; ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="ns-section-title">Additional Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group" style="align-items: flex-start;">
                    <label class="ns-label">Description</label>
                    <textarea name="description" class="ns-input" rows="3" style="height: auto;" placeholder="Provide additional context for this entry..."><?php echo htmlspecialchars($description); ?></textarea>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    function updateFormFields() {
        const typeSelect = document.getElementById('type-select');
        if (!typeSelect) return;
        
        const val = typeSelect.value;
        const sections = document.querySelectorAll('.type-section');
        const codeLabel = document.getElementById('code-label');
        
        // Update Label
        if (codeLabel) {
            if (val === 'tax') codeLabel.innerHTML = 'Tax Code <span class="ns-required">*</span>';
            else if (val === 'currency') codeLabel.innerHTML = 'ISO Code <span class="ns-required">*</span>';
            else codeLabel.innerHTML = 'Reference Code <span class="ns-required">*</span>';
        }

        // Hide all sections first
        sections.forEach(s => s.style.display = 'none');
        
        let targetSelector = null;
        if (val === 'tax') {
            targetSelector = '.section-tax';
        } else if (val === 'currency') {
            targetSelector = '.section-currency';
        }
        
        if (targetSelector) {
            const target = document.querySelector(targetSelector);
            if (target) {
                target.style.display = 'block';
                if (typeof jQuery !== 'undefined') {
                    $(target).hide().fadeIn(200);
                }
            }
        }
    }

    // Use window.load to ensure jQuery and other assets are ready
    window.addEventListener('load', function() {
        updateFormFields(); // Initialize on load
        
        const typeSelect = document.getElementById('type-select');
        if (typeSelect) {
            typeSelect.addEventListener('change', updateFormFields);
        }

        // Form submission
        const form = document.getElementById('accounting-form');
        if (form && typeof jQuery !== 'undefined') {
            $(form).submit(function(e) {
                e.preventDefault();
                const type = $('#type-select').val();
                
                let value = 0;
                let code = $('[name="code_entry"]').val();
                
                if (type === 'tax') {
                    value = $('[name="value_tax"]').val();
                } else if (type === 'currency') {
                    value = $('[name="value_currency"]').val();
                }
                
                const formData = $(this).serializeArray();
                formData.push({name: 'value', value: value});
                formData.push({name: 'override_code', value: code});
                
                const submitBtn = $('button[form="accounting-form"]');
                const originalText = submitBtn.html();
                submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

                $.ajax({
                    url: 'api/setup_manage.php',
                    method: 'POST',
                    data: formData,
                    success: function(resp) {
                        try {
                            const data = typeof resp === 'object' ? resp : JSON.parse(resp);
                            if (data.status === 'success') {
                                nsNotify(data.message || 'Accounting entry saved successfully');
                                setTimeout(() => {
                                    window.location.href = '?page=system/settings/accounting';
                                }, 1000);
                            } else {
                                nsNotify(data.message || 'Error saving entry', 'error');
                                submitBtn.prop('disabled', false).html(originalText);
                            }
                        } catch(e) {
                            nsNotify('Error processing server response.', 'error');
                            submitBtn.prop('disabled', false).html(originalText);
                        }
                    },
                    error: function() {
                        nsNotify('Network error or server failed.', 'error');
                        submitBtn.prop('disabled', false).html(originalText);
                    }
                });
            });
        }
    });
</script>
