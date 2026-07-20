<?php
/**
 * Consolidated Accounting Periods & Fiscal Year Closing Dashboard
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$db = db();

// Fetch all fiscal years
$list = $db->fetchAll("
    SELECT fy.*, 
           u_closed.full_name as closed_by_name,
           u_reopened.full_name as reopened_by_name
    FROM fiscal_years fy
    LEFT JOIN users u_closed ON fy.closed_by = u_closed.id
    LEFT JOIN users u_reopened ON fy.reopened_by = u_reopened.id
    ORDER BY fy.start_date DESC
");

// Selected FY
$selected_id = $_GET['id'] ?? ($list[0]['id'] ?? '');
$selected_fy = null;
if ($selected_id) {
    foreach ($list as $fy) {
        if ($fy['id'] === $selected_id) {
            $selected_fy = $fy;
            break;
        }
    }
}
?>
<style>
.fy-dashboard { display: grid; grid-template-columns: 320px 1fr; gap: 20px; }
.fy-list-panel { background: #fff; border: 1px solid #dde2e8; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
.fy-detail-panel { background: #fff; border: 1px solid #dde2e8; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.05); min-height: 500px; display: flex; flex-direction: column; }
.fy-header-bar { padding: 15px 20px; background: #f8f9fa; border-bottom: 1px solid #eef0f2; display: flex; justify-content: space-between; align-items: center; }
.fy-header-title { font-size: 14px; font-weight: 800; color: #003087; text-transform: uppercase; letter-spacing: 0.5px; }
.fy-item-card { padding: 16px 20px; border-bottom: 1px solid #f0f2f5; cursor: pointer; transition: all 0.2s; position: relative; }
.fy-item-card:hover { background: #f4f7fa; }
.fy-item-card.active { background: #eef2ff; border-left: 4px solid #003087; }
.fy-item-name { font-weight: 700; font-size: 14px; color: #1e293b; margin-bottom: 4px; }
.fy-item-dates { font-size: 11px; color: #64748b; }
.fy-status-badge { position: absolute; right: 20px; top: 18px; font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 4px; }
.badge-open { background: #e6fffa; color: #047481; border: 1px solid currentColor; }
.badge-closed { background: #f3f4f6; color: #4b5563; border: 1px solid currentColor; }
.badge-reopened { background: #eff6ff; color: #1d4ed8; border: 1px solid currentColor; }

.fy-detail-body { padding: 24px; flex-grow: 1; }
.fy-meta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px; background: #f8fafc; padding: 20px; border-radius: 6px; border: 1px solid #e2e8f0; }
.fy-meta-item .lbl { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; margin-bottom: 4px; }
.fy-meta-item .val { font-size: 13px; font-weight: 700; color: #1e293b; }

.fy-actions-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid #e2e8f0; }

.fy-results-container { border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px; background: #fff; display: none; }
.results-header { font-size: 14px; font-weight: 800; color: #003087; margin-bottom: 15px; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }

/* Validation styles */
.validation-item { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.validation-item:last-child { border-bottom: none; }
.validation-icon { font-size: 16px; margin-top: 2px; }
.validation-success { color: #1a7f37; }
.validation-warning { color: #b7791f; }
.validation-error { color: #d32f2f; }

/* Preview tables styles */
.preview-summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px; }
.preview-summary-card { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 16px; border-radius: 6px; }
.preview-summary-card .val { font-size: 15px; font-weight: 800; color: #003087; }
.preview-summary-card .lbl { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-top: 2px; }

/* Modal Custom Styling */
.ns-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); backdrop-filter: blur(4px); }
.ns-modal-content { background-color: #fff; margin: 10% auto; padding: 24px; border: 1px solid #dde2e8; width: 480px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
.ns-modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eef0f2; padding-bottom: 12px; margin-bottom: 18px; }
.ns-modal-title { font-size: 16px; font-weight: 800; color: #003087; }
.ns-modal-close { cursor: pointer; color: #888; font-size: 18px; transition: color 0.2s; }
.ns-modal-close:hover { color: #333; }
.ns-modal-footer { display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #eef0f2; padding-top: 12px; margin-top: 18px; }
</style>

<div class="ns-page-header">
    <h1 class="ns-page-title">
        Accounting Periods & Year-End Closing
        <button class="ns-btn ns-btn-primary" onclick="openFYModal()"><i class="fas fa-plus"></i> New Accounting Period</button>
    </h1>
</div>

<div class="fy-dashboard">
    <!-- LEFT: Periods List -->
    <div class="fy-list-panel">
        <div class="fy-header-bar">
            <div class="fy-header-title"><i class="fas fa-calendar-alt"></i> Accounting Periods</div>
        </div>
        <div style="max-height: 600px; overflow-y: auto;">
            <?php if (empty($list)): ?>
                <div style="padding: 30px; text-align: center; color: #888; font-size: 13px;">No accounting periods defined.</div>
            <?php else: foreach ($list as $row): 
                $active_class = ($row['id'] === $selected_id) ? 'active' : '';
                $badge_class = 'badge-open';
                if ($row['status'] === 'closed') $badge_class = 'badge-closed';
                else if ($row['status'] === 'reopened') $badge_class = 'badge-reopened';
            ?>
                <div class="fy-item-card <?= $active_class ?>" onclick="selectFY('<?= $row['id'] ?>')">
                    <div class="fy-item-name"><?= htmlspecialchars($row['name']) ?></div>
                    <div class="fy-item-dates">Period: <?= $row['start_date'] ?> to <?= $row['end_date'] ?></div>
                    <span class="fy-status-badge <?= $badge_class ?>"><?= $row['status'] ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- RIGHT: Period Details and Actions -->
    <div class="fy-detail-panel">
        <div class="fy-header-bar">
            <div class="fy-header-title"><i class="fas fa-info-circle"></i> Period Dashboard</div>
            <?php if ($selected_fy): ?>
                <button class="ns-btn" onclick="openFYModal(<?= htmlspecialchars(json_encode($selected_fy)) ?>)" style="padding: 2px 10px; font-size: 11px;"><i class="fas fa-edit"></i> Edit</button>
            <?php endif; ?>
        </div>
        
        <div class="fy-detail-body">
            <?php if (!$selected_fy): ?>
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #888;">
                    <i class="fas fa-calendar-check" style="font-size: 48px; opacity: 0.25; margin-bottom: 16px;"></i>
                    <div style="font-size: 16px; font-weight: 600;">No Period Selected</div>
                    <div style="font-size: 12px; margin-top: 6px;">Select an accounting period from the list on the left to manage it.</div>
                </div>
            <?php else: 
                $is_closed = ($selected_fy['status'] === 'closed');
            ?>
                <div class="fy-meta-grid">
                    <div class="fy-meta-item">
                        <div class="lbl">Fiscal Year</div>
                        <div class="val" style="color:#003087; font-size:15px;"><?= htmlspecialchars($selected_fy['name']) ?></div>
                    </div>
                    <div class="fy-meta-item">
                        <div class="lbl">Start / End Date</div>
                        <div class="val"><?= $selected_fy['start_date'] ?> / <?= $selected_fy['end_date'] ?></div>
                    </div>
                    <div class="fy-meta-item">
                        <div class="lbl">Status</div>
                        <div class="val">
                            <span class="badge" style="background: <?= $is_closed ? '#f3f4f6' : '#e6fffa'; ?>; color: <?= $is_closed ? '#4b5563' : '#047481'; ?>; padding: 2px 8px; border-radius: 4px; border: 1px solid currentColor; font-size: 11px; text-transform: uppercase; font-weight: 800;">
                                <?= $selected_fy['status'] ?>
                            </span>
                        </div>
                    </div>
                    <?php if ($is_closed): ?>
                        <div class="fy-meta-item">
                            <div class="lbl">Closed By</div>
                            <div class="val"><?= htmlspecialchars($selected_fy['closed_by_name'] ?? $selected_fy['closed_by'] ?? 'System') ?></div>
                        </div>
                        <div class="fy-meta-item">
                            <div class="lbl">Closed Date / Time</div>
                            <div class="val"><?= $selected_fy['closing_date'] ?> <?= $selected_fy['closed_timestamp'] ? date('H:i', strtotime($selected_fy['closed_timestamp'])) : '' ?></div>
                        </div>
                    <?php elseif ($selected_fy['status'] === 'reopened'): ?>
                        <div class="fy-meta-item">
                            <div class="lbl">Reopened By</div>
                            <div class="val"><?= htmlspecialchars($selected_fy['reopened_by_name'] ?? $selected_fy['reopened_by'] ?? 'User') ?></div>
                        </div>
                        <div class="fy-meta-item">
                            <div class="lbl">Reopened Timestamp</div>
                            <div class="val"><?= $selected_fy['reopened_timestamp'] ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($selected_fy['notes'])): ?>
                        <div class="fy-meta-item" style="grid-column: 1 / -1;">
                            <div class="lbl">Notes / Reason</div>
                            <div class="val" style="font-weight: 500; font-style: italic; color: #475569;"><?= nl2br(htmlspecialchars($selected_fy['notes'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="fy-actions-bar">
                    <button class="ns-btn" id="btn-validate" onclick="runValidation()" <?= $is_closed ? 'disabled' : '' ?>><i class="fas fa-tasks"></i> Validate Period</button>
                    <button class="ns-btn" id="btn-preview" onclick="runPreview()" <?= $is_closed ? 'disabled' : '' ?>><i class="fas fa-eye"></i> Preview Closing</button>
                    <button class="ns-btn ns-btn-primary" id="btn-close" onclick="openCloseModal()" <?= $is_closed ? 'disabled' : '' ?>><i class="fas fa-lock"></i> Close Fiscal Year</button>
                    <button class="ns-btn" id="btn-reopen" onclick="openReopenModal()" <?= !$is_closed ? 'disabled' : '' ?> style="color:#d32f2f; border-color: currentColor;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='none'"><i class="fas fa-unlock"></i> Reopen Fiscal Year</button>
                    
                    <?php if (!empty($selected_fy['closing_journal_id'])): ?>
                        <a href="?page=transactions/view&id=<?= $selected_fy['closing_journal_id'] ?>" class="ns-btn" id="btn-journal"><i class="fas fa-file-invoice"></i> View Closing Journal</a>
                    <?php endif; ?>
                    
                    <button class="ns-btn" id="btn-print" onclick="printReport()" <?= !$is_closed ? 'disabled' : '' ?>><i class="fas fa-print"></i> Print Closing Report</button>
                </div>

                <!-- Operations Result Container -->
                <div class="fy-results-container" id="results-box">
                    <div class="results-header">
                        <span id="results-title">Validation Checks</span>
                        <button class="ns-btn" style="padding: 2px 8px; font-size: 11px;" onclick="closeResultsBox()"><i class="fas fa-times"></i> Close</button>
                    </div>
                    <div id="results-content">
                        <!-- Ajax populated -->
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL: Create/Edit Period -->
<div class="ns-modal" id="fy-modal">
    <div class="ns-modal-content">
        <div class="ns-modal-header">
            <h3 class="ns-modal-title" id="fy-modal-title">New Accounting Period</h3>
            <span class="ns-modal-close" onclick="closeFYModal()">&times;</span>
        </div>
        <form id="fy-form" onsubmit="saveFY(event)">
            <input type="hidden" name="id" id="form-id">
            
            <div class="ns-form-group">
                <label class="ns-label">Fiscal Year Name *</label>
                <input type="text" name="name" id="form-name" class="ns-input" placeholder="e.g. FY 2025/26" required>
            </div>
            
            <div class="ns-form-row">
                <div class="ns-form-group" style="flex: 1;">
                    <label class="ns-label">Start Date *</label>
                    <input type="date" name="start_date" id="form-start-date" class="ns-input" required>
                </div>
                <div class="ns-form-group" style="flex: 1;">
                    <label class="ns-label">End Date *</label>
                    <input type="date" name="end_date" id="form-end-date" class="ns-input" required>
                </div>
            </div>
            
            <div class="ns-form-group">
                <label class="ns-label">Status</label>
                <select name="status" id="form-status" class="ns-select">
                    <option value="open">Open</option>
                    <option value="closed" disabled>Closed (Set via Closing Dashboard only)</option>
                    <option value="reopened">Reopened</option>
                </select>
            </div>
            
            <div class="ns-form-group">
                <label class="ns-label">Notes</label>
                <textarea name="notes" id="form-notes" class="ns-input" style="height: 80px; resize: vertical;" placeholder="Add initial notes or description..."></textarea>
            </div>
            
            <div class="ns-modal-footer">
                <button type="button" class="ns-btn" onclick="closeFYModal()">Cancel</button>
                <button type="submit" class="ns-btn ns-btn-primary" id="btn-save-period">Save Accounting Period</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Close Fiscal Year Notes -->
<div class="ns-modal" id="close-modal">
    <div class="ns-modal-content">
        <div class="ns-modal-header">
            <h3 class="ns-modal-title">Close Fiscal Year</h3>
            <span class="ns-modal-close" onclick="closeCloseModal()">&times;</span>
        </div>
        <form id="close-form" onsubmit="executeClose(event)">
            <div style="font-size: 13px; color: #555; margin-bottom: 15px; line-height: 1.5;">
                <i class="fas fa-info-circle" style="color: #003087; margin-right: 6px;"></i>
                Closing this fiscal year will lock all transactions in this period and generate closing journal entries to Retained Earnings and opening balances for the next year.
            </div>
            <div class="ns-form-group">
                <label class="ns-label">Closing Notes / Comments</label>
                <textarea name="notes" id="close-notes" class="ns-input" style="height: 100px; resize: vertical;" placeholder="Enter details about this year-end closing..." required></textarea>
            </div>
            <div class="ns-modal-footer">
                <button type="button" class="ns-btn" onclick="closeCloseModal()">Cancel</button>
                <button type="submit" class="ns-btn ns-btn-primary" id="btn-execute-close"><i class="fas fa-lock"></i> Finalize Period Close</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Reopen Fiscal Year Reason -->
<div class="ns-modal" id="reopen-modal">
    <div class="ns-modal-content">
        <div class="ns-modal-header">
            <h3 class="ns-modal-title">Reopen Fiscal Year</h3>
            <span class="ns-modal-close" onclick="closeReopenModal()">&times;</span>
        </div>
        <form id="reopen-form" onsubmit="executeReopen(event)">
            <div style="font-size: 13px; color: #c00; margin-bottom: 15px; line-height: 1.5; font-weight: 600;">
                <i class="fas fa-exclamation-triangle" style="margin-right: 6px;"></i>
                Are you sure you want to reopen this fiscal year? Reopening will delete the system-generated closing journal and next year's opening balances!
            </div>
            <div class="ns-form-group">
                <label class="ns-label">Reason for Reopening *</label>
                <textarea name="reason" id="reopen-reason" class="ns-input" style="height: 100px; resize: vertical;" placeholder="Reason is required for audit trail..." required></textarea>
            </div>
            <div class="ns-modal-footer">
                <button type="button" class="ns-btn" onclick="closeReopenModal()">Cancel</button>
                <button type="submit" class="ns-btn" id="btn-execute-reopen" style="background:#d32f2f; color:#fff;"><i class="fas fa-unlock"></i> Reopen Period</button>
            </div>
        </form>
    </div>
</div>

<script>
function selectFY(id) {
    window.location.href = '?page=system/fiscal_years&id=' + id;
}

function closeResultsBox() {
    $('#results-box').slideUp();
}

// Save Fiscal Year Period
function saveFY(e) {
    e.preventDefault();
    const formData = $('#fy-form').serialize();
    const $btn = $('#btn-save-period');
    const originalText = $btn.html();
    
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
    
    $.ajax({
        url: 'api/save_fiscal_year.php',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                if (typeof nsNotify === 'function') nsNotify(res.message);
                else alert(res.message);
                closeFYModal();
                setTimeout(() => { location.reload(); }, 1000);
            } else {
                if (typeof nsNotify === 'function') nsNotify('Error: ' + res.message, 'error');
                else alert('Error: ' + res.message);
                $btn.prop('disabled', false).html(originalText);
            }
        },
        error: function() {
            if (typeof nsNotify === 'function') nsNotify('An error occurred.', 'error');
            else alert('An error occurred.');
            $btn.prop('disabled', false).html(originalText);
        }
    });
}

// Run closure validations
function runValidation() {
    const fy_id = '<?= $selected_id ?>';
    const $btn = $('#btn-validate');
    const originalText = $btn.html();
    
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Validating...');
    $('#results-box').slideDown();
    $('#results-title').text('Validation Checks');
    $('#results-content').html('<div style="text-align:center; padding:30px;"><i class="fas fa-circle-notch fa-spin" style="font-size:24px; color:#003087;"></i><div style="font-size:11px; color:#666; margin-top:8px;">Running year-end closure compliance audits...</div></div>');
    
    $.ajax({
        url: 'api/fiscal_year_handler.php',
        method: 'POST',
        data: { action: 'validate', id: fy_id },
        dataType: 'json',
        success: function(res) {
            $btn.prop('disabled', false).html(originalText);
            if (res.status === 'success') {
                let html = '<div style="margin-bottom:15px; font-size:12px; color:#555;">Review the audits below. Errors (<i class="fas fa-times-circle" style="color:#d32f2f;"></i>) must be corrected before closing. Warnings (<i class="fas fa-exclamation-triangle" style="color:#b7791f;"></i>) are informative.</div>';
                res.validations.forEach(v => {
                    let icon = '<i class="fas fa-check-circle validation-icon validation-success"></i>';
                    if (v.status === 'warning') {
                        icon = '<i class="fas fa-exclamation-triangle validation-icon validation-warning"></i>';
                    } else if (v.status === 'error') {
                        icon = '<i class="fas fa-times-circle validation-icon validation-error"></i>';
                    }
                    let actionLink = '';
                    if (v.action_url) {
                        actionLink = `<a href="${v.action_url}" style="margin-left: 15px; font-size: 11px; font-weight: 600; text-decoration: none; padding: 4px 10px; border-radius: 4px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; white-space: nowrap;"><i class="fas fa-tools"></i> Fix Issue</a>`;
                    }
                    html += `
                        <div class="validation-item" style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: flex-start; gap: 12px;">
                                ${icon}
                                <div>
                                    <div style="font-weight:700; color:#334155;">${v.name}</div>
                                    <div style="font-size:12px; color:#64748b; margin-top:2px;">${v.message}</div>
                                </div>
                            </div>
                            <div>
                                ${actionLink}
                            </div>
                        </div>
                    `;
                });
                $('#results-content').html(html);
            } else {
                $('#results-content').html('<div class="validation-item"><i class="fas fa-times-circle validation-icon validation-error"></i><div><div style="font-weight:700;">Validation Failed</div><div style="font-size:12px; color:#64748b; margin-top:2px;">' + res.message + '</div></div></div>');
            }
        },
        error: function() {
            $btn.prop('disabled', false).html(originalText);
            $('#results-content').html('<div class="validation-item"><i class="fas fa-times-circle validation-icon validation-error"></i><div><div style="font-weight:700;">Connection Error</div><div style="font-size:12px; color:#64748b; margin-top:2px;">Server returned an error.</div></div></div>');
        }
    });
}

// Run closing previews
function runPreview() {
    const fy_id = '<?= $selected_id ?>';
    const $btn = $('#btn-preview');
    const originalText = $btn.html();
    
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading Preview...');
    $('#results-box').slideDown();
    $('#results-title').text('Year-End Closing Preview (Pro Forma)');
    $('#results-content').html('<div style="text-align:center; padding:30px;"><i class="fas fa-circle-notch fa-spin" style="font-size:24px; color:#003087;"></i><div style="font-size:11px; color:#666; margin-top:8px;">Calculating closing P&L and generating balance sheet...</div></div>');
    
    $.ajax({
        url: 'api/fiscal_year_handler.php',
        method: 'POST',
        data: { action: 'preview', id: fy_id },
        dataType: 'json',
        success: function(res) {
            $btn.prop('disabled', false).html(originalText);
            if (res.status === 'success') {
                const p = res.preview;
                let html = `
                    <div class="preview-summary">
                        <div class="preview-summary-card"><div class="val">NPR ${numberFormat(p.total_revenue)}</div><div class="lbl">Revenues</div></div>
                        <div class="preview-summary-card"><div class="val">NPR ${numberFormat(p.total_cogs)}</div><div class="lbl">Cost of Sales</div></div>
                        <div class="preview-summary-card"><div class="val">NPR ${numberFormat(p.operating_expenses)}</div><div class="lbl">Operating Exp.</div></div>
                        <div class="preview-summary-card"><div class="val" style="color:${p.net_profit >= 0 ? '#1a7f37' : '#d32f2f'}">NPR ${numberFormat(p.net_profit)}</div><div class="lbl">Net Profit/Loss</div></div>
                    </div>
                    
                    <div style="margin-bottom:15px; font-weight:800; color:#003087; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">Closing Journal Entries</div>
                    <div style="max-height: 200px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:4px; margin-bottom:20px;">
                        <table style="width:100%; border-collapse:collapse; font-size:12px; text-align:left;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                    <th style="padding:6px 10px;">Account</th>
                                    <th style="padding:6px 10px; text-align:right;">Debit</th>
                                    <th style="padding:6px 10px; text-align:right;">Credit</th>
                                    <th style="padding:6px 10px;">Memo</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                p.journal_lines.forEach(l => {
                    html += `
                        <tr style="border-bottom:1px solid #f8fafc;">
                            <td style="padding:6px 10px; font-weight:600;">[${l.account_code}] ${l.account_name}</td>
                            <td style="padding:6px 10px; text-align:right; color:#003087;">${l.type === 'debit' ? numberFormat(l.amount) : '—'}</td>
                            <td style="padding:6px 10px; text-align:right; color:#c00;">${l.type === 'credit' ? numberFormat(l.amount) : '—'}</td>
                            <td style="padding:6px 10px; color:#666;">${l.memo}</td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-bottom:15px; font-weight:800; color:#003087; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">Post-Closing Balance Sheet Carry-Forwards</div>
                    <div style="max-height: 200px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:4px;">
                        <table style="width:100%; border-collapse:collapse; font-size:12px; text-align:left;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                    <th style="padding:6px 10px;">Account</th>
                                    <th style="padding:6px 10px;">Type</th>
                                    <th style="padding:6px 10px; text-align:right;">Balance</th>
                                    <th style="padding:6px 10px;">Normal</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                p.post_closing_balances.forEach(b => {
                    html += `
                        <tr style="border-bottom:1px solid #f8fafc;">
                            <td style="padding:6px 10px; font-weight:600;">[${b.code}] ${b.name}</td>
                            <td style="padding:6px 10px; text-transform:capitalize; color:#64748b;">${b.type}</td>
                            <td style="padding:6px 10px; text-align:right; font-weight:700;">NPR ${numberFormat(b.balance)}</td>
                            <td style="padding:6px 10px; text-transform:uppercase; color:${b.normal==='debit'?'#003087':'#c00'};">${b.normal}</td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>
                `;
                $('#results-content').html(html);
            } else {
                $('#results-content').html('<div class="validation-item"><i class="fas fa-times-circle validation-icon validation-error"></i><div><div style="font-weight:700;">Calculation Failed</div><div style="font-size:12px; color:#64748b; margin-top:2px;">' + res.message + '</div></div></div>');
            }
        },
        error: function() {
            $btn.prop('disabled', false).html(originalText);
            $('#results-content').html('<div class="validation-item"><i class="fas fa-times-circle validation-icon validation-error"></i><div><div style="font-weight:700;">Connection Error</div><div style="font-size:12px; color:#64748b; margin-top:2px;">Server returned an error.</div></div></div>');
        }
    });
}

// Modal open/closes
function openFYModal(data = null) {
    if (data) {
        $('#fy-modal-title').text('Edit Accounting Period');
        $('#form-id').val(data.id);
        $('#form-name').val(data.name);
        $('#form-start-date').val(data.start_date);
        $('#form-end-date').val(data.end_date);
        $('#form-status').val(data.status);
        $('#form-notes').val(data.notes);
    } else {
        $('#fy-modal-title').text('New Accounting Period');
        $('#form-id').val('');
        $('#form-name').val('');
        $('#form-start-date').val('');
        $('#form-end-date').val('');
        $('#form-status').val('open');
        $('#form-notes').val('');
    }
    $('#fy-modal').fadeIn();
}
function closeFYModal() { $('#fy-modal').fadeOut(); }

function openCloseModal() { $('#close-modal').fadeIn(); }
function closeCloseModal() { $('#close-modal').fadeOut(); }

function openReopenModal() { $('#reopen-modal').fadeIn(); }
function closeReopenModal() { $('#reopen-modal').fadeOut(); }

// Execute Close
function executeClose(e) {
    e.preventDefault();
    const fy_id = '<?= $selected_id ?>';
    const notes = $('#close-notes').val();
    const $btn = $('#btn-execute-close');
    const originalText = $btn.html();
    
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Closing Year...');
    
    $.ajax({
        url: 'api/fiscal_year_handler.php',
        method: 'POST',
        data: { action: 'close', id: fy_id, notes: notes },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                if (typeof nsNotify === 'function') nsNotify(res.message);
                else alert(res.message);
                closeCloseModal();
                setTimeout(() => { selectFY(fy_id); }, 1000);
            } else {
                if (typeof nsNotify === 'function') nsNotify('Error: ' + res.message, 'error');
                else alert('Error: ' + res.message);
                $btn.prop('disabled', false).html(originalText);
            }
        },
        error: function() {
            if (typeof nsNotify === 'function') nsNotify('An error occurred.', 'error');
            else alert('An error occurred.');
            $btn.prop('disabled', false).html(originalText);
        }
    });
}

// Execute Reopen
function executeReopen(e) {
    e.preventDefault();
    const fy_id = '<?= $selected_id ?>';
    const reason = $('#reopen-reason').val();
    const $btn = $('#btn-execute-reopen');
    const originalText = $btn.html();
    
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Reopening...');
    
    $.ajax({
        url: 'api/fiscal_year_handler.php',
        method: 'POST',
        data: { action: 'reopen', id: fy_id, reason: reason },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                if (typeof nsNotify === 'function') nsNotify(res.message);
                else alert(res.message);
                closeReopenModal();
                setTimeout(() => { selectFY(fy_id); }, 1000);
            } else {
                if (typeof nsNotify === 'function') nsNotify('Error: ' + res.message, 'error');
                else alert('Error: ' + res.message);
                $btn.prop('disabled', false).html(originalText);
            }
        },
        error: function() {
            if (typeof nsNotify === 'function') nsNotify('An error occurred.', 'error');
            else alert('An error occurred.');
            $btn.prop('disabled', false).html(originalText);
        }
    });
}

// Print Closing Report
function printReport() {
    const fy_id = '<?= $selected_id ?>';
    window.open('?page=system/fiscal_years/print&id=' + fy_id, '_blank');
}

// Number Formatting helper
function numberFormat(number) {
    return parseFloat(number).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}
</script>
