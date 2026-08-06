<?php
$db = db();
$info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info WHERE meta_field LIKE 'ref_%'");
$refs = [];
foreach($info as $row) {
    $refs[$row['meta_field']] = $row['meta_value'];
}

$txn_types = [
    // Sales & Receivables
    'customer_invoice'     => ['label' => 'Sales Invoice', 'default_prefix' => 'SI', 'icon' => 'fa-file-invoice-dollar', 'group' => 'Sales & Receivables'],
    'customer_payment'     => ['label' => 'Customer Payment', 'default_prefix' => 'CPAY', 'icon' => 'fa-money-check-alt', 'group' => 'Sales & Receivables'],
    'credit_memo'          => ['label' => 'Credit Memo / Return', 'default_prefix' => 'CM', 'icon' => 'fa-undo-alt', 'group' => 'Sales & Receivables'],
    'pos_entry'            => ['label' => 'POS Transaction', 'default_prefix' => 'POS', 'icon' => 'fa-cash-register', 'group' => 'Sales & Receivables'],

    // Purchases & Payables
    'vendor_bill'          => ['label' => 'Vendor Bill', 'default_prefix' => 'VI', 'icon' => 'fa-file-invoice', 'group' => 'Purchases & Payables'],
    'vendor_payment'       => ['label' => 'Vendor Payment', 'default_prefix' => 'VPAY', 'icon' => 'fa-money-bill-wave', 'group' => 'Purchases & Payables'],
    'vendor_credit'        => ['label' => 'Vendor Credit / Debit Memo', 'default_prefix' => 'VC', 'icon' => 'fa-file-signature', 'group' => 'Purchases & Payables'],

    // Inventory & Stock
    'inventory_transfer'   => ['label' => 'Stock Transfer', 'default_prefix' => 'ITR', 'icon' => 'fa-exchange-alt', 'group' => 'Inventory & Stock'],
    'inventory_adjustment' => ['label' => 'Stock Adjustment', 'default_prefix' => 'ADJ', 'icon' => 'fa-sliders-h', 'group' => 'Inventory & Stock'],

    // Financials & Banking
    'journal_entry'        => ['label' => 'Journal Entry', 'default_prefix' => 'JV', 'icon' => 'fa-book', 'group' => 'Financials & Banking'],
    'expense'              => ['label' => 'Expense Record', 'default_prefix' => 'EXP', 'icon' => 'fa-receipt', 'group' => 'Financials & Banking'],
    'account_transfer'     => ['label' => 'Fund / Bank Transfer', 'default_prefix' => 'XFER', 'icon' => 'fa-university', 'group' => 'Financials & Banking'],

    // Master Records
    'item'                 => ['label' => 'Item / Product IDs', 'default_prefix' => 'ITM', 'icon' => 'fa-box', 'group' => 'Master Records'],
    'customer'             => ['label' => 'Customer IDs', 'default_prefix' => 'CUST', 'icon' => 'fa-users', 'group' => 'Master Records'],
    'vendor'               => ['label' => 'Vendor / Supplier IDs', 'default_prefix' => 'VEND', 'icon' => 'fa-truck', 'group' => 'Master Records'],
    'account'              => ['label' => 'Chart of Accounts', 'default_prefix' => 'ACC', 'icon' => 'fa-sitemap', 'group' => 'Master Records'],
    'location'             => ['label' => 'Location / Warehouse Codes', 'default_prefix' => 'LOC', 'icon' => 'fa-map-marker-alt', 'group' => 'Master Records'],
];
?>

<div class="ns-form-header">
    <div class="ns-form-title"><i class="fas fa-list-ol"></i> Auto-Generated Numbering</div>
    <div class="ns-page-actions">
        <button type="button" class="ns-btn" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
        <button type="submit" form="ref-form" class="ns-btn ns-btn-primary" id="save-ref-btn">Save Numbering Rules</button>
    </div>
</div>

<div class="ns-content">
    <div class="ns-form-container" style="padding: 0; overflow: hidden;">
        <form id="ref-form" onsubmit="return handleRefSave(event)">
            <table class="ns-settings-table" style="margin: 0; border: none; border-radius: 0;">
                <thead>
                    <tr>
                        <th style="padding-left: 24px;">Transaction / Entity Type</th>
                        <th width="120">Prefix</th>
                        <th width="130">Location Tag</th>
                        <th width="80">Separator</th>
                        <th width="100">Next Number</th>
                        <th width="120">Padding</th>
                        <th width="120">Suffix</th>
                        <th width="200" style="padding-right: 24px; text-align: right;">Live Preview</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $current_group = '';
                    foreach($txn_types as $type => $meta): 
                        if ($current_group != $meta['group']):
                            $current_group = $meta['group'];
                    ?>
                        <tr style="background: #f8f9fa;">
                            <td colspan="8" style="padding: 10px 24px; font-weight: 800; color: var(--ns-primary-light); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee;">
                                <?php echo $current_group; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php 
                        $prefix  = $refs["ref_{$type}_prefix"] ?? $meta['default_prefix'];
                        $loc_mode= $refs["ref_{$type}_loc"]    ?? 'none';
                        $sep     = $refs["ref_{$type}_sep"]    ?? '-';
                        $next    = $refs["ref_{$type}_next"]   ?? '1';
                        $pad     = $refs["ref_{$type}_pad"]    ?? '5';
                        $suffix  = $refs["ref_{$type}_suffix"] ?? '';
                        
                        $sampleLoc = 'GOKA';
                        $parts = [];
                        if ($loc_mode === 'prefix') $parts[] = $sampleLoc;
                        if (!empty($prefix))       $parts[] = $prefix;
                        if ($loc_mode === 'mid')    $parts[] = $sampleLoc;
                        $parts[] = str_pad($next, (int)$pad, '0', STR_PAD_LEFT);
                        if (!empty($suffix))        $parts[] = str_replace('{LOC}', $sampleLoc, $suffix);
                        if ($loc_mode === 'suffix') $parts[] = $sampleLoc;

                        $preview = implode($sep, array_filter($parts, function($v) { return $v !== ''; }));
                    ?>
                    <tr class="ref-row">
                        <td style="padding-left: 24px;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="width: 32px; height: 32px; background: #eef2f7; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--ns-primary);">
                                    <i class="fas <?php echo $meta['icon']; ?>"></i>
                                </div>
                                <span style="font-weight: 600; font-size: 13px;"><?php echo $meta['label']; ?></span>
                            </div>
                        </td>
                        <td>
                            <input type="text" name="ref_<?php echo $type; ?>_prefix" class="ns-input ref-input" 
                                   value="<?php echo htmlspecialchars($prefix); ?>" oninput="updateRefPreview(this)" style="text-transform: uppercase;" placeholder="PREFIX">
                        </td>
                        <td>
                            <select name="ref_<?php echo $type; ?>_loc" class="ns-select ref-input" onchange="updateRefPreview(this)" style="font-size: 12px;">
                                <option value="none" <?php echo $loc_mode === 'none' ? 'selected' : ''; ?>>None</option>
                                <option value="prefix" <?php echo $loc_mode === 'prefix' ? 'selected' : ''; ?>>In Prefix</option>
                                <option value="mid" <?php echo $loc_mode === 'mid' ? 'selected' : ''; ?>>After Prefix</option>
                                <option value="suffix" <?php echo $loc_mode === 'suffix' ? 'selected' : ''; ?>>In Suffix</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="ref_<?php echo $type; ?>_sep" class="ns-input ref-input" 
                                   value="<?php echo htmlspecialchars($sep); ?>" style="text-align: center;" oninput="updateRefPreview(this)">
                        </td>
                        <td>
                            <input type="number" name="ref_<?php echo $type; ?>_next" class="ns-input ref-input ns-input-num" 
                                   value="<?php echo $next; ?>" min="1" oninput="updateRefPreview(this)">
                        </td>
                        <td>
                            <select name="ref_<?php echo $type; ?>_pad" class="ns-select ref-input" onchange="updateRefPreview(this)">
                                <?php for($i=1; $i<=10; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $pad == $i ? 'selected' : ''; ?>><?php echo $i; ?> Digits</option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="ref_<?php echo $type; ?>_suffix" class="ns-input ref-input" 
                                   value="<?php echo htmlspecialchars($suffix); ?>" oninput="updateRefPreview(this)" style="text-transform: uppercase;" placeholder="SUFFIX">
                        </td>
                        <td style="padding-right: 24px; text-align: right;">
                            <div class="ref-preview" style="background: linear-gradient(135deg, #f0f4f8 0%, #d9e2ec 100%); padding: 6px 14px; border-radius: 20px; font-family: 'JetBrains Mono', 'Courier New', monospace; font-weight: 800; color: var(--ns-primary); display: inline-block; border: 1px solid #cbd5e0; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); font-size: 13px; letter-spacing: 1px;">
                                <?php echo $preview; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    </div>
</div>

<style>
.ref-row:hover {
    background-color: #fcfdfe !important;
}
.ref-input {
    border-color: transparent !important;
    background-color: transparent !important;
}
.ref-row:hover .ref-input {
    border-color: var(--ns-border-color) !important;
    background-color: #fff !important;
}
.ref-input:focus {
    background-color: #fff !important;
    border-color: var(--ns-primary) !important;
    box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1) !important;
}
</style>

<script>
function updateRefPreview(el) {
    const row = el.closest('tr');
    const prefix = row.querySelector('[name*="_prefix"]').value.toUpperCase();
    const locSelect = row.querySelector('[name*="_loc"]');
    const locMode = locSelect ? locSelect.value : 'none';
    const sep = row.querySelector('[name*="_sep"]').value;
    const next = row.querySelector('[name*="_next"]').value;
    const pad = parseInt(row.querySelector('[name*="_pad"]').value) || 5;
    const suffixEl = row.querySelector('[name*="_suffix"]');
    const suffix = suffixEl ? suffixEl.value.toUpperCase() : '';
    
    // Auto uppercase prefix & suffix
    if (el.name.includes('_prefix')) el.value = prefix;
    if (el.name.includes('_suffix')) el.value = suffix;

    const sampleLoc = 'GOKA';
    let parts = [];

    if (locMode === 'prefix') parts.push(sampleLoc);
    if (prefix) parts.push(prefix);
    if (locMode === 'mid') parts.push(sampleLoc);

    const paddedNext = (next || '1').toString().padStart(pad, '0');
    parts.push(paddedNext);

    if (suffix) {
        parts.push(suffix.replace('{LOC}', sampleLoc));
    }
    if (locMode === 'suffix') parts.push(sampleLoc);

    const preview = parts.filter(p => p !== '').join(sep);
    row.querySelector('.ref-preview').innerText = preview;
}

function handleRefSave(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    const $btn = $('#save-ref-btn');
    const originalText = $btn.html();
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
    
    const formData = $('#ref-form').serialize();
    
    $.ajax({
        url: 'api/system_settings.php',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(data) {
            if (data.status === 'success') {
                if (typeof nsNotify === 'function') {
                    nsNotify('Auto-numbering rules updated successfully');
                }
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
        error: function() {
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
    // Already handled by onsubmit, but ensuring click also triggers it if needed
    $('#ref-form').on('submit', handleRefSave);
});
</script>
