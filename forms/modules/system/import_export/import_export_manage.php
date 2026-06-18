<?php
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}
?>
<div class="ns-card">
    <div class="ns-card-header">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h1 style="margin:0; font-size:20px; color:var(--ns-text);"><i class="fas fa-file-import" style="margin-right:10px; color:var(--ns-primary);"></i>Data Management: Import & Export</h1>
                <p style="margin:5px 0 0; font-size:12px; color:#666;">Migrate your data using CSV files. Masters and Transactions supported.</p>
            </div>
        </div>
    </div>

    <div class="ns-tabs" style="margin-top:20px;">
        <div class="ns-tab-headers" style="border-bottom: 2px solid #eee; display: flex; gap: 30px;">
            <div class="ns-tab-header active" data-tab="import" style="padding: 10px 0; cursor: pointer; font-weight: 600; color: var(--ns-primary); border-bottom: 2px solid var(--ns-primary); margin-bottom: -2px;">
                <i class="fas fa-upload" style="margin-right:8px;"></i> Import Data
            </div>
            <div class="ns-tab-header" data-tab="export" style="padding: 10px 0; cursor: pointer; font-weight: 600; color: #888;">
                <i class="fas fa-download" style="margin-right:8px;"></i> Export Data
            </div>
        </div>

        <div class="ns-tab-content active" id="tab-import" style="padding: 30px 0;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px;">
                <!-- Step 1: Selection -->
                <div class="glass-card" style="padding: 20px; border: 1px solid #eee; background: #fcfcfc;">
                    <h3 style="margin-top:0; font-size:16px;"><span style="background:var(--ns-primary); color:white; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; margin-right:10px; font-size:12px;">1</span> Select Record Type</h3>
                    
                    <div class="form-group" style="margin-top:20px;">
                        <label>What would you like to import?</label>
                        <select id="import_type" class="ns-input" style="width:100%;" onchange="updateTemplateLink()">
                            <optgroup label="Master Records">
                                <option value="items">Items (Products)</option>
                                <option value="customers">Customers</option>
                                <option value="vendors">Vendors</option>
                                <option value="accounts">Chart of Accounts</option>
                            </optgroup>
                            <optgroup label="Transactions">
                                <option value="vendor_bills">Vendor Bills</option>
                                <option value="customer_invoices">Customer Invoices</option>
                                <option value="journal_entries">Journal Entries</option>
                                <option value="expenses">Expenses</option>
                            </optgroup>
                        </select>
                    </div>

                    <div style="margin-top:20px; padding:15px; background:rgba(var(--ns-primary-rgb), 0.05); border-radius:8px; border-left:4px solid var(--ns-primary);">
                        <div style="font-weight:600; font-size:13px; margin-bottom:5px;">Download Template</div>
                        <p style="font-size:12px; margin:0 0 10px; color:#555;">Use our standard CSV template to ensure your data matches the system requirements.</p>
                        <a href="#" id="download_template" class="ns-btn ns-btn-outline" style="font-size:11px; padding:6px 12px;"><i class="fas fa-file-csv"></i> Download CSV Template</a>
                    </div>
                </div>

                <!-- Step 2: Upload -->
                <div class="glass-card" style="padding: 20px; border: 1px solid #eee; background: #fcfcfc;">
                    <h3 style="margin-top:0; font-size:16px;"><span style="background:var(--ns-primary); color:white; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; margin-right:10px; font-size:12px;">2</span> Upload & Process</h3>
                    
                    <div id="drop_zone" style="margin-top:20px; border: 2px dashed #ccc; border-radius: 10px; padding: 40px; text-align: center; background: white; transition: all 0.3s; cursor: pointer;">
                        <i class="fas fa-cloud-upload-alt" style="font-size:40px; color:#ccc; margin-bottom:15px;"></i>
                        <div style="font-weight:600; color:#555;">Drag and drop your CSV file here</div>
                        <div style="font-size:12px; color:#888; margin-top:5px;">or click to browse from your computer</div>
                        <input type="file" id="file_input" accept=".csv" style="display:none;">
                    </div>

                    <div id="file_info" style="display:none; margin-top:15px; padding:10px; background:#e8f4fd; border-radius:5px; align-items:center; justify-content:space-between;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <i class="fas fa-file-csv" style="color:var(--ns-primary);"></i>
                            <span id="filename" style="font-size:13px; font-weight:600;">file.csv</span>
                        </div>
                        <button onclick="clearFile()" style="background:none; border:none; color:#e74c3c; cursor:pointer;"><i class="fas fa-times"></i></button>
                    </div>

                    <div style="margin-top:30px;">
                        <button id="btn_import" class="ns-btn ns-btn-primary" style="width:100%; padding:12px;" onclick="startImport()" disabled>
                            <i class="fas fa-play" style="margin-right:8px;"></i> Start Import Process
                        </button>
                    </div>
                </div>
            </div>

            <!-- Progress Overlay (Hidden initially) -->
            <div id="import_progress_container" style="display:none; margin-top:40px; padding:30px; border:1px solid #eee; border-radius:12px; background:white; box-shadow:0 10px 30px rgba(0,0,0,0.05);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <div>
                        <h4 style="margin:0; font-size:16px;" id="progress_status">Processing records...</h4>
                        <p style="margin:5px 0 0; font-size:12px; color:#888;" id="progress_details">Reading file and validating data...</p>
                    </div>
                    <div id="progress_percent" style="font-size:24px; font-weight:700; color:var(--ns-primary);">0%</div>
                </div>
                <div style="height:10px; background:#eee; border-radius:5px; overflow:hidden;">
                    <div id="progress_bar" style="height:100%; width:0%; background:var(--ns-primary); transition: width 0.3s;"></div>
                </div>
                <div style="margin-top:20px; display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; text-align:center;">
                    <div style="padding:15px; background:#f9f9f9; border-radius:8px;">
                        <div style="font-size:11px; color:#888; text-transform:uppercase; letter-spacing:1px;">Total</div>
                        <div id="stat_total" style="font-size:20px; font-weight:700;">0</div>
                    </div>
                    <div style="padding:15px; background:rgba(46, 204, 113, 0.1); border-radius:8px;">
                        <div style="font-size:11px; color:#27ae60; text-transform:uppercase; letter-spacing:1px;">Success</div>
                        <div id="stat_success" style="font-size:20px; font-weight:700; color:#2ecc71;">0</div>
                    </div>
                    <div style="padding:15px; background:rgba(231, 76, 60, 0.1); border-radius:8px;">
                        <div style="font-size:11px; color:#c0392b; text-transform:uppercase; letter-spacing:1px;">Failed</div>
                        <div id="stat_failed" style="font-size:20px; font-weight:700; color:#e74c3c;">0</div>
                    </div>
                </div>
                <div id="error_log" style="margin-top:20px; display:none;">
                    <div style="font-weight:600; font-size:13px; margin-bottom:10px; color:#e74c3c;">Error Log:</div>
                    <div id="error_list" style="max-height:150px; overflow-y:auto; background:#fff5f5; border:1px solid #fed7d7; border-radius:5px; padding:10px; font-size:12px; font-family:monospace; line-height:1.6;">
                    </div>
                </div>
            </div>
        </div>

        <div class="ns-tab-content" id="tab-export" style="display:none; padding:30px 0;">
            <div style="max-width:600px; margin:0 auto;">
                <div class="glass-card" style="padding:30px; border:1px solid #eee; background:#fcfcfc;">
                    <h3 style="margin-top:0; font-size:18px;"><i class="fas fa-file-export" style="margin-right:10px; color:var(--ns-primary);"></i> Export Configuration</h3>
                    
                    <div class="form-group" style="margin-top:25px;">
                        <label>Select Data to Export</label>
                        <select id="export_type" class="ns-input" style="width:100%;">
                            <optgroup label="Master Records">
                                <option value="items">Items (Products)</option>
                                <option value="customers">Customers</option>
                                <option value="vendors">Vendors</option>
                                <option value="accounts">Chart of Accounts</option>
                                <option value="users">Users & Employees</option>
                            </optgroup>
                            <optgroup label="Transactions">
                                <option value="vendor_bills">Vendor Bills</option>
                                <option value="customer_invoices">Customer Invoices</option>
                                <option value="payments">Payments</option>
                                <option value="journal_entries">Journal Entries</option>
                                <option value="expenses">Expenses</option>
                            </optgroup>
                        </select>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px;">
                        <div class="form-group">
                            <label>From Date</label>
                            <input type="date" id="export_from" class="ns-input" style="width:100%;" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="form-group">
                            <label>To Date</label>
                            <input type="date" id="export_to" class="ns-input" style="width:100%;" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div style="margin-top:30px;">
                        <button class="ns-btn ns-btn-primary" style="width:100%; padding:12px;" onclick="startExport()">
                            <i class="fas fa-download" style="margin-right:8px;"></i> Export to CSV
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .glass-card {
        border-radius: 12px;
        transition: transform 0.2s;
    }
    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #444;
    }
    #drop_zone.dragover {
        border-color: var(--ns-primary);
        background: rgba(var(--ns-primary-rgb), 0.05);
    }
    .ns-tab-header:hover {
        color: var(--ns-primary) !important;
    }
</style>

<script>
    // Tab switching logic
    document.querySelectorAll('.ns-tab-header').forEach(header => {
        header.addEventListener('click', function() {
            document.querySelectorAll('.ns-tab-header').forEach(h => {
                h.classList.remove('active');
                h.style.color = '#888';
                h.style.borderBottom = 'none';
            });
            document.querySelectorAll('.ns-tab-content').forEach(c => c.style.display = 'none');
            
            this.classList.add('active');
            this.style.color = 'var(--ns-primary)';
            this.style.borderBottom = '2px solid var(--ns-primary)';
            
            const tabId = this.getAttribute('data-tab');
            document.getElementById('tab-' + tabId).style.display = 'block';
        });
    });

    // File handling
    const dropZone = document.getElementById('drop_zone');
    const fileInput = document.getElementById('file_input');
    const fileInfo = document.getElementById('file_info');
    const filename = document.getElementById('filename');
    const btnImport = document.getElementById('btn_import');

    dropZone.onclick = () => fileInput.click();

    dropZone.ondragover = (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    };

    dropZone.ondragleave = () => {
        dropZone.classList.remove('dragover');
    };

    dropZone.ondrop = (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    };

    fileInput.onchange = () => handleFiles(fileInput.files);

    function handleFiles(files) {
        if (files.length > 0) {
            const file = files[0];
            if (file.name.endsWith('.csv')) {
                filename.innerText = file.name;
                fileInfo.style.display = 'flex';
                btnImport.disabled = false;
                dropZone.style.display = 'none';
            } else {
                nsNotify('Please upload a valid CSV file', 'error');
            }
        }
    }

    function clearFile() {
        fileInput.value = '';
        fileInfo.style.display = 'none';
        dropZone.style.display = 'block';
        btnImport.disabled = true;
    }

    function updateTemplateLink() {
        const type = document.getElementById('import_type').value;
        const link = document.getElementById('download_template');
        link.href = 'api/export_handler.php?template=1&type=' + type;
    }
    updateTemplateLink();

    // Import Logic
    function startImport() {
        const type = document.getElementById('import_type').value;
        const file = fileInput.files[0];
        
        const formData = new FormData();
        formData.append('type', type);
        formData.append('file', file);

        document.getElementById('import_progress_container').style.display = 'block';
        btnImport.disabled = true;
        
        // Reset stats
        document.getElementById('stat_total').innerText = '0';
        document.getElementById('stat_success').innerText = '0';
        document.getElementById('stat_failed').innerText = '0';
        document.getElementById('progress_bar').style.width = '0%';
        document.getElementById('progress_percent').innerText = '0%';
        document.getElementById('error_log').style.display = 'none';
        document.getElementById('error_list').innerHTML = '';

        fetch('api/import_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            
            function read() {
                reader.read().then(({ done, value }) => {
                    if (done) {
                        document.getElementById('progress_status').innerText = 'Import Complete';
                        btnImport.disabled = false;
                        return;
                    }
                    
                    const chunk = decoder.decode(value, { stream: true });
                    const lines = chunk.split('\n');
                    
                    lines.forEach(line => {
                        if (!line.trim()) return;
                        try {
                            const data = JSON.parse(line);
                            if (data.status === 'progress') {
                                document.getElementById('progress_bar').style.width = data.percent + '%';
                                document.getElementById('progress_percent').innerText = Math.round(data.percent) + '%';
                                document.getElementById('stat_total').innerText = data.total;
                                document.getElementById('stat_success').innerText = data.success;
                                document.getElementById('stat_failed').innerText = data.failed;
                                document.getElementById('progress_details').innerText = 'Processing row ' + data.current + ' of ' + data.total;
                                
                                if (data.errors && data.errors.length > 0) {
                                    document.getElementById('error_log').style.display = 'block';
                                    data.errors.forEach(err => {
                                        const div = document.createElement('div');
                                        div.innerText = 'Row ' + err.row + ': ' + err.message;
                                        document.getElementById('error_list').appendChild(div);
                                    });
                                }
                            }
                        } catch (e) { console.error('Parse error', e, line); }
                    });
                    read();
                });
            }
            read();
        })
        .catch(err => {
            nsNotify('Import failed: ' + err.message, 'error');
            btnImport.disabled = false;
        });
    }

    // Export Logic
    function startExport() {
        const type = document.getElementById('export_type').value;
        const from = document.getElementById('export_from').value;
        const to = document.getElementById('export_to').value;
        
        window.location.href = `api/export_handler.php?type=${type}&from=${from}&to=${to}`;
    }
</script>
