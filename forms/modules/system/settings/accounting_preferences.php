<?php
$db = db();
$info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$settings = [];
foreach($info as $row) {
    $settings[$row['meta_field']] = $row['meta_value'];
}

$accounts = $db->fetchAll("SELECT id, account_code, account_name FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_code ASC");
$customers = $db->fetchAll("SELECT id, customer_code, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
$vendors = $db->fetchAll("SELECT id, vendor_code, company_name FROM vendors WHERE is_active = 1 AND is_deleted = 0 ORDER BY company_name ASC");

function account_select($name, $current_val, $accounts) {
    $html = '<select name="'.$name.'" class="ns-select">';
    $html .= '<option value="">-- Select Account --</option>';
    foreach($accounts as $acc) {
        $selected = ($current_val == $acc['id']) ? 'selected' : '';
        $html .= '<option value="'.$acc['id'].'" '.$selected.'>['.$acc['account_code'].'] '.$acc['account_name'].'</option>';
    }
    $html .= '</select>';
    return $html;
}

function entity_select($name, $current_val, $entities, $type = 'Customer') {
    $html = '<select name="'.$name.'" class="ns-select">';
    $html .= '<option value="">-- Select '.$type.' --</option>';
    foreach($entities as $ent) {
        $selected = ($current_val == $ent['id']) ? 'selected' : '';
        $label = ($type == 'Customer') ? $ent['full_name'] : $ent['company_name'];
        $code = ($type == 'Customer') ? $ent['customer_code'] : $ent['vendor_code'];
        $html .= '<option value="'.$ent['id'].'" '.$selected.'>['.$code.'] '.$label.'</option>';
    }
    $html .= '</select>';
    return $html;
}
?>

<form id="accounting-prefs-form" method="POST" action="api/system_settings.php" onsubmit="return handlePrefsSave(event)">
    <div class="ns-form-header">
        <div class="ns-form-title"><i class="fas fa-file-contract"></i> Accounting Preferences</div>
        <div class="ns-page-actions">
            <button type="button" class="ns-btn" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
            <button type="submit" class="ns-btn ns-btn-primary" id="save-prefs-btn">Save Preferences</button>
        </div>
    </div>

    <div class="ns-content">
        <div class="ns-form-container">
            
            <div class="ns-section-title">Core Accounts (Receivables & Payables)</div>
            <div class="ns-form-row">
                <div class="ns-form-group">
                    <label class="ns-label">Default AR <span class="ns-required">*</span></label>
                    <?php echo account_select('default_ar_account', $settings['default_ar_account'] ?? '', $accounts); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Default AP <span class="ns-required">*</span></label>
                    <?php echo account_select('default_ap_account', $settings['default_ap_account'] ?? '', $accounts); ?>
                </div>
            </div>

            <div class="ns-section-title">Inventory & COGS</div>
            <div class="ns-form-row">
                <div class="ns-form-group">
                    <label class="ns-label">Default Inventory</label>
                    <?php echo account_select('default_asset_account', $settings['default_asset_account'] ?? '', $accounts); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Default COGS</label>
                    <?php echo account_select('default_cogs_account', $settings['default_cogs_account'] ?? '', $accounts); ?>
                </div>
            </div>
            <div class="ns-form-row">
                <div class="ns-form-group">
                    <label class="ns-label">Default Income</label>
                    <?php echo account_select('default_income_account', $settings['default_income_account'] ?? '', $accounts); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Default Expense</label>
                    <?php echo account_select('default_expense_account', $settings['default_expense_account'] ?? '', $accounts); ?>
                </div>
            </div>

            <div class="ns-section-title">Taxes & Adjustments</div>
            <div class="ns-form-row">
                <div class="ns-form-group">
                    <label class="ns-label">Default Tax (VAT)</label>
                    <?php echo account_select('default_tax_account', $settings['default_tax_account'] ?? '', $accounts); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Default Discount</label>
                    <?php echo account_select('default_discount_account', $settings['default_discount_account'] ?? '', $accounts); ?>
                </div>
            </div>
            <div class="ns-form-row">
                <div class="ns-form-group">
                    <label class="ns-label">Profit/Loss Adj.</label>
                    <?php echo account_select('default_profit_account', $settings['default_profit_account'] ?? '', $accounts); ?>
                </div>
            </div>

            <div class="ns-section-title">Banking & Cash Management</div>
            <div class="ns-form-row">
                <div class="ns-form-group">
                    <label class="ns-label">Default Bank</label>
                    <?php echo account_select('default_bank_account', $settings['default_bank_account'] ?? '', $accounts); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Default Cash</label>
                    <?php echo account_select('default_cash_account', $settings['default_cash_account'] ?? '', $accounts); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Default Change/Return</label>
                    <?php echo account_select('default_change_account', $settings['default_change_account'] ?? '', $accounts); ?>
                </div>
            </div>

            <div class="ns-section-title">Transactional Entities</div>
            <div class="ns-form-row">
                <div class="ns-form-group">
                    <label class="ns-label">Default POS Customer</label>
                    <?php echo entity_select('default_customer_id', $settings['default_customer_id'] ?? '', $customers, 'Customer'); ?>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label">Default Vendor</label>
                    <?php echo entity_select('default_vendor_id', $settings['default_vendor_id'] ?? '', $vendors, 'Vendor'); ?>
                </div>
            </div>

        </div>
    </div>
</form>

<script>
function handlePrefsSave(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    console.log('Accounting Preferences: Submitting form...');
    
    const $form = $('#accounting-prefs-form');
    const formData = $form.serialize();
    const $btn = $('#save-prefs-btn');
    const originalText = $btn.html();

    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

    $.ajax({
        url: 'api/system_settings.php',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(data) {
            console.log('Accounting Preferences: Server response:', data);
            if (data.status === 'success') {
                if (typeof nsNotify === 'function') {
                    nsNotify('Accounting preferences saved successfully');
                } else {
                    alert('Accounting preferences saved successfully');
                }
                
                // Redirect to dashboard
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 1000);
            } else {
                if (typeof nsNotify === 'function') {
                    nsNotify('Error: ' + data.message, 'error');
                } else {
                    alert('Error: ' + data.message);
                }
                $btn.prop('disabled', false).html(originalText);
            }
        },
        error: function(xhr, status, error) {
            console.error('Accounting Preferences: AJAX error:', status, error);
            if (typeof nsNotify === 'function') {
                nsNotify('An error occurred while saving.', 'error');
            } else {
                alert('An error occurred while saving.');
            }
            $btn.prop('disabled', false).html(originalText);
        }
    });

    return false;
}

$(document).ready(function() {
    // Re-attach if needed, but onsubmit is primary
    $('#accounting-prefs-form').on('submit', handlePrefsSave);
});
</script>
