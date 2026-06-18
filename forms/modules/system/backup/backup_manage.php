<?php
$db = db();
$info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$settings = [];
foreach($info as $row) {
    $settings[$row['meta_field']] = $row['meta_value'];
}
?>

<div class="ns-card">
    <div class="ns-card-header">
        <h2 class="ns-card-title"><i class="fas fa-database" style="margin-right: 10px;"></i> Backup & Data Management</h2>
    </div>
    <div class="ns-card-body">
        <form id="backup-settings-form">
            <div class="ns-form-section">
                <h3 class="ns-form-section-title">Configuration</h3>
                <div class="ns-form-row">
                    <div class="ns-form-group" style="flex: 2;">
                        <label class="ns-label">Backup Folder Path</label>
                        <input type="text" name="backup_folder" class="ns-input" value="<?php echo $settings['backup_folder'] ?? 'database'; ?>" placeholder="e.g. C:\backups or relative path like 'database'">
                        <small class="ns-text-muted">Folder where database dumps will be saved/loaded from.</small>
                    </div>
                </div>
                <div class="ns-form-row">
                    <div class="ns-form-group">
                        <label class="ns-label">Git Username</label>
                        <input type="text" name="git_username" class="ns-input" value="<?php echo $settings['git_username'] ?? ''; ?>">
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label">Git Password / Token</label>
                        <input type="password" name="git_password" class="ns-input" value="<?php echo $settings['git_password'] ?? ''; ?>">
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <button type="submit" class="ns-btn ns-btn-sm"><i class="fas fa-save"></i> Save Configuration</button>
                </div>
            </div>
        </form>

        <div class="ns-form-section" style="margin-top: 30px;">
            <h3 class="ns-form-section-title">Database Operations</h3>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <button type="button" class="ns-btn ns-btn-primary" onclick="runOperation('export_db')">
                    <i class="fas fa-file-export"></i> Export All Data (Local to Folder)
                </button>
                <button type="button" class="ns-btn ns-btn-secondary" onclick="runOperation('import_latest')">
                    <i class="fas fa-file-import"></i> Import Latest (Folder to Local)
                </button>
            </div>
            <p class="ns-text-muted" style="margin-top: 10px; font-size: 12px;">
                <i class="fas fa-info-circle"></i> Exporting will create a .sql file in your specified backup folder with the current date and time.
            </p>
        </div>

        <div class="ns-form-section" style="margin-top: 30px;">
            <h3 class="ns-form-section-title">Git Cloud Sync</h3>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <button type="button" class="ns-btn" onclick="runOperation('git_push')" style="background: #24292e; color: white;">
                    <i class="fab fa-github"></i> Upload to Git (Push)
                </button>
                <button type="button" class="ns-btn" onclick="runOperation('git_pull')" style="background: #f6f8fa; border: 1px solid #d1d5da;">
                    <i class="fas fa-cloud-download-alt"></i> Download from Git (Pull)
                </button>
            </div>
            <p class="ns-text-muted" style="margin-top: 10px; font-size: 12px;">
                <i class="fas fa-exclamation-triangle" style="color: #e67e22;"></i> Ensure you have a Git repository initialized in your backup folder.
            </p>
        </div>

        <!-- Terminal Output Area -->
        <div id="terminal-output" style="margin-top: 30px; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; font-family: monospace; font-size: 13px; display: none; max-height: 300px; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px solid #333; padding-bottom: 5px;">
                <span style="color: #569cd6;">Operation Log</span>
                <span style="cursor: pointer;" onclick="this.parentElement.parentElement.style.display='none'">&times;</span>
            </div>
            <div id="log-content"></div>
        </div>
    </div>
</div>

<script>
document.getElementById('backup-settings-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('button');
    btn.disabled = true;
    
    fetch('api/system_settings.php', {
        method: 'POST',
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') nsNotify('Configuration saved!');
        else nsNotify('Error saving: ' + data.message, 'error');
    })
    .finally(() => btn.disabled = false);
});

function runOperation(op) {
    const log = document.getElementById('terminal-output');
    const content = document.getElementById('log-content');
    log.style.display = 'block';
    content.innerHTML = '<span style="color: #ce9178;">[' + new Date().toLocaleTimeString() + ']</span> Starting operation: ' + op + '...<br>';
    
    // Scroll to bottom
    log.scrollTop = log.scrollHeight;

    fetch('api/system_backup.php?action=' + op)
    .then(r => r.json())
    .then(data => {
        const color = data.status === 'success' ? '#6a9955' : '#f44747';
        content.innerHTML += '<span style="color: ' + color + ';">[' + new Date().toLocaleTimeString() + '] ' + data.message + '</span><br>';
        if (data.output) {
            content.innerHTML += '<pre style="margin-top: 10px; color: #888;">' + data.output + '</pre>';
        }
    })
    .catch(err => {
        content.innerHTML += '<span style="color: #f44747;">[' + new Date().toLocaleTimeString() + '] Network or Server Error: ' + err.message + '</span><br>';
    })
    .finally(() => {
        log.scrollTop = log.scrollHeight;
    });
}
</script>
