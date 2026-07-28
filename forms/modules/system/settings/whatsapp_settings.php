<?php
$db = db();
$info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$settings = [];
foreach($info as $row) {
    $settings[$row['meta_field']] = $row['meta_value'];
}

$default_template = "Dear {customer_name},\n\nPlease find your account statement summary for the period {from_date} to {to_date}:\n\n• Opening Balance: {currency} {opening_balance}\n• New Charges: {currency} {new_charges}\n• Payments Received: {currency} {payments}\n• Ending Balance Due: {currency} {ending_balance}\n\nThank you for doing business with us!\n{company_name}";
$current_template = $settings['whatsapp_statement_template'] ?? $default_template;
?>

<div class="ns-form-header" style="margin-bottom: 20px;">
    <div class="ns-form-title" style="display: flex; align-items: center; gap: 10px; font-size: 18px; font-weight: 700; color: #1e293b;">
        <i class="fab fa-whatsapp" style="color: #25D366; font-size: 24px;"></i> WhatsApp Integration Settings
    </div>
    <div class="ns-page-actions">
        <button type="button" class="ns-btn" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
        <button type="submit" form="whatsapp-settings-form" class="ns-btn ns-btn-primary" id="save-wa-btn"><i class="fas fa-save"></i> Save Settings</button>
    </div>
</div>

<div class="ns-content" style="max-width: 1000px; margin: 0 auto;">
    <form id="whatsapp-settings-form" method="POST" action="api/system_settings.php" onsubmit="return handleWaSave(event)">
        <div class="ns-portlet" style="background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-plug" style="color: #3b82f6;"></i> Gateway Configuration
            </div>

            <div class="ns-form-row" style="display: flex; gap: 20px; margin-bottom: 16px;">
                <div class="ns-form-group" style="flex: 1;">
                    <label class="ns-label" style="font-weight: 600; margin-bottom: 6px; display: block;">Integration Status</label>
                    <select name="whatsapp_enabled" class="ns-select" style="width: 100%; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 12px;">
                        <option value="1" <?php echo ($settings['whatsapp_enabled'] ?? '1') == '1' ? 'selected' : ''; ?>>Enabled</option>
                        <option value="0" <?php echo ($settings['whatsapp_enabled'] ?? '') == '0' ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                </div>
                <div class="ns-form-group" style="flex: 1;">
                    <label class="ns-label" style="font-weight: 600; margin-bottom: 6px; display: block;">API Provider / Gateway Type</label>
                    <select name="whatsapp_api_provider" class="ns-select" style="width: 100%; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 12px;">
                        <option value="ultramsg" <?php echo ($settings['whatsapp_api_provider'] ?? '') == 'ultramsg' ? 'selected' : ''; ?>>UltraMsg API</option>
                        <option value="greenapi" <?php echo ($settings['whatsapp_api_provider'] ?? '') == 'greenapi' ? 'selected' : ''; ?>>Green API</option>
                        <option value="waboxapp" <?php echo ($settings['whatsapp_api_provider'] ?? '') == 'waboxapp' ? 'selected' : ''; ?>>Waboxapp</option>
                        <option value="twilio" <?php echo ($settings['whatsapp_api_provider'] ?? '') == 'twilio' ? 'selected' : ''; ?>>Twilio WhatsApp</option>
                        <option value="generic" <?php echo ($settings['whatsapp_api_provider'] ?? 'generic') == 'generic' ? 'selected' : ''; ?>>Generic / Custom Webhook Gateway</option>
                    </select>
                </div>
            </div>

            <div class="ns-form-row" style="display: flex; gap: 20px; margin-bottom: 16px;">
                <div class="ns-form-group" style="flex: 2;">
                    <label class="ns-label" style="font-weight: 600; margin-bottom: 6px; display: block;">API Endpoint URL</label>
                    <input type="url" name="whatsapp_api_url" class="ns-input" style="width: 100%; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 12px;" 
                           placeholder="https://api.ultramsg.com/instanceXXXX/messages/chat" 
                           value="<?php echo htmlspecialchars($settings['whatsapp_api_url'] ?? ''); ?>">
                    <span style="font-size: 11px; color: #64748b; margin-top: 4px; display: block;">The full URL endpoint provided by your WhatsApp gateway provider.</span>
                </div>
                <div class="ns-form-group" style="flex: 1;">
                    <label class="ns-label" style="font-weight: 600; margin-bottom: 6px; display: block;">Instance ID / Account SID</label>
                    <input type="text" name="whatsapp_instance_id" class="ns-input" style="width: 100%; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 12px;" 
                           placeholder="e.g. instance12345" 
                           value="<?php echo htmlspecialchars($settings['whatsapp_instance_id'] ?? ''); ?>">
                </div>
            </div>

            <div class="ns-form-row" style="display: flex; gap: 20px; margin-bottom: 8px;">
                <div class="ns-form-group" style="flex: 1;">
                    <label class="ns-label" style="font-weight: 600; margin-bottom: 6px; display: block;">API Token / Key</label>
                    <input type="password" name="whatsapp_api_token" class="ns-input" style="width: 100%; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 12px;" 
                           placeholder="Secret API Key or Token" 
                           value="<?php echo htmlspecialchars($settings['whatsapp_api_token'] ?? ''); ?>">
                </div>
                <div class="ns-form-group" style="flex: 1;">
                    <label class="ns-label" style="font-weight: 600; margin-bottom: 6px; display: block;">Business Sender Phone Number</label>
                    <input type="text" name="whatsapp_sender_number" class="ns-input" style="width: 100%; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 12px;" 
                           placeholder="e.g. 9779800000000" 
                           value="<?php echo htmlspecialchars($settings['whatsapp_sender_number'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <div class="ns-portlet" style="background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-file-invoice" style="color: #10b981;"></i> Customer Statement Message Template
            </div>

            <div class="ns-form-group" style="margin-bottom: 12px;">
                <label class="ns-label" style="font-weight: 600; margin-bottom: 6px; display: block;">Default Statement Message</label>
                <textarea name="whatsapp_statement_template" class="ns-input" style="width: 100%; height: 160px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 10px; font-family: monospace; font-size: 13px; line-height: 1.5;"><?php echo htmlspecialchars($current_template); ?></textarea>
            </div>
            
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; font-size: 12px; color: #475569;">
                <strong style="color: #1e293b;">Available Template Placeholders:</strong>
                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;">
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{customer_name}</code>
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{from_date}</code>
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{to_date}</code>
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{opening_balance}</code>
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{new_charges}</code>
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{payments}</code>
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{ending_balance}</code>
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{currency}</code>
                    <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{company_name}</code>
                </div>
            </div>
        </div>
    </form>

    <div class="ns-portlet" style="background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-vial" style="color: #f59e0b;"></i> Test WhatsApp Gateway Connection
        </div>

        <div style="display: flex; gap: 16px; align-items: flex-end;">
            <div style="flex: 1;">
                <label class="ns-label" style="font-weight: 600; margin-bottom: 6px; display: block;">Recipient Test Phone Number</label>
                <input type="text" id="test-wa-phone" class="ns-input" style="width: 100%; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 0 12px;" 
                       placeholder="e.g. 9800000000 or 9779800000000" value="<?php echo htmlspecialchars($settings['whatsapp_sender_number'] ?? ''); ?>">
            </div>
            <button type="button" class="ns-btn" onclick="sendTestWaMessage()" style="height: 38px; background: #25D366; color: white; border: none; font-weight: 600; padding: 0 16px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                <i class="fab fa-whatsapp"></i> Send Test Message
            </button>
        </div>
        <div id="test-wa-result" style="margin-top: 12px; display: none; padding: 12px; border-radius: 6px; font-size: 13px;"></div>
    </div>
</div>

<script>
function handleWaSave(e) {
    e.preventDefault();
    const btn = document.getElementById('save-wa-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const formData = new FormData(document.getElementById('whatsapp-settings-form'));

    fetch('api/system_settings.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Settings';
        if (res.status === 'success' || res.success) {
            alert('WhatsApp Settings saved successfully!');
        } else {
            alert(res.message || 'Saved settings successfully!');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Settings';
        alert('Settings saved.');
    });

    return false;
}

function sendTestWaMessage() {
    const phone = document.getElementById('test-wa-phone').value.trim();
    if (!phone) {
        alert('Please enter a recipient test phone number.');
        return;
    }

    const resDiv = document.getElementById('test-wa-result');
    resDiv.style.display = 'block';
    resDiv.style.background = '#f1f5f9';
    resDiv.style.color = '#334155';
    resDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending test message via WhatsApp API...';

    fetch('api/send_whatsapp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            to: phone,
            message: 'Hello! This is a test message from your SMS accounting system WhatsApp integration.'
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            resDiv.style.background = '#dcfce7';
            resDiv.style.color = '#166534';
            resDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + (res.message || 'Test message sent successfully!');
        } else {
            resDiv.style.background = '#fee2e2';
            resDiv.style.color = '#991b1b';
            resDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + (res.message || 'Failed to send test message.');
        }
    })
    .catch(err => {
        resDiv.style.background = '#fee2e2';
        resDiv.style.color = '#991b1b';
        resDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error connecting to server endpoint: ' + err.message;
    });
}
</script>
