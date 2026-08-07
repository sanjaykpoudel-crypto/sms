<?php
// Shared helper: render a report filter bar
// Usage: include this file after setting $report_title and $filters array
// $filters = [['name'=>'date_from','label'=>'From','type'=>'date'], ...]

function rpt_header(string $title) {
    $db = db();
    $info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
    $sys = [];
    foreach($info as $row) $sys[$row['meta_field']] = $row['meta_value'];
    
    echo '<style>
        .rpt-toolbar { background: #f4f5f7; border: 1px solid #dde2e8; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; display: flex; align-items: center; flex-wrap: wrap; gap: 15px; }
        .rpt-title { font-size: 16px; font-weight: 700; color: #1e293b; flex: 1; min-width: 180px; display: flex; align-items: center; gap: 10px; }
        .rpt-title i { color: var(--ns-primary); }
        .rpt-filter-form { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        .rpt-filter-group { display: flex; align-items: center; gap: 6px; }
        .rpt-filter-group label { font-size: 12px; color: #64748b; font-weight: 600; white-space: nowrap; text-transform: uppercase; }
        .rpt-input { padding: 6px 10px !important; font-size: 13px !important; height: 34px !important; border: 1px solid #cbd5e1 !important; border-radius: 4px !important; }
        
        .rpt-summary { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; }
        .rpt-summary-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; flex: 1; min-width: 140px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .rpt-summary-card .val { font-size: 22px; font-weight: 800; color: var(--ns-primary); }
        .rpt-summary-card .lbl { font-size: 11px; color: #64748b; margin-top: 5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        
        @media print { 
            .rpt-main-print-wrapper { width: 100% !important; border-collapse: collapse !important; border: none !important; margin: 0 !important; padding: 0 !important; }
            .rpt-main-print-thead { display: table-header-group !important; }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
            tr { break-inside: avoid !important; page-break-inside: avoid !important; }
            .rpt-header-print { 
                display: flex !important; 
                align-items: center !important; 
                justify-content: space-between !important; 
                border-bottom: 2px solid #0f172a !important; 
                padding-bottom: 12px !important; 
                margin-bottom: 15px !important; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            } 
            .rpt-doc-title-print { 
                display: block !important; 
                text-align: center !important; 
                font-size: 16px !important; 
                font-weight: 800 !important; 
                margin: 12px 0 12px 0 !important; 
                text-transform: uppercase !important; 
                letter-spacing: 0.5px !important; 
                background: #f1f5f9 !important; 
                padding: 6px 0 !important; 
                border-radius: 4px !important; 
                border: 1px solid #cbd5e1 !important; 
                color: #003087 !important; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            }
            .rpt-filter-info-print { 
                display: block !important; 
                text-align: center !important; 
                font-size: 11px !important; 
                font-weight: 500 !important; 
                color: #334155 !important; 
                margin: -4px 0 15px 0 !important; 
                padding: 5px 12px !important; 
                background: #f8fafc !important; 
                border-radius: 4px !important; 
                border: 1px dashed #cbd5e1 !important; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            }
            .rpt-toolbar, .ns-header, .ns-nav, .rpt-summary, .ns-card-header, .ns-card-tools, form, .no-print, #whatsappModal, .modal, .modal-backdrop, .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate { display: none !important; } 
            .ns-card, .ns-portlet { border: none !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; } 
            .ns-portlet-content { padding: 0 !important; }
            body { background: #fff !important; margin: 0; padding: 15px; } 
            .ns-table, .ns-report-table-static { width: 100% !important; border-collapse: collapse !important; font-size: 11px !important; }
            .ns-table th, .ns-table td, .ns-report-table-static th, .ns-report-table-static td { border: 1px solid #cbd5e1 !important; padding: 6px 8px !important; }
            .ns-table th, .ns-report-table-static th, table.dataTable thead th { 
                background-color: #f1f5f9 !important; 
                font-weight: 700 !important; 
                color: #0f172a !important; 
                text-transform: uppercase !important; 
                border: 1px solid #cbd5e1 !important; 
                border-bottom: 2px solid #003087 !important; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            }
        }
        .ns-table th, .ns-report-table-static th, table.dataTable thead th { 
            background-color: #f8fafc !important; 
            border-bottom: 2px solid #003087 !important; 
            color: #0f172a !important; 
            font-weight: 700 !important; 
            text-transform: uppercase !important; 
        }
        .ns-report-table-static { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ns-report-table-static th { background: #f8f9fa; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; padding: 12px 15px; border-bottom: 2px solid #edf2f7; text-align: left; }
        .ns-report-table-static td { padding: 12px 15px; border-bottom: 1px solid #edf2f7; color: #334155; }
        .ns-report-table-static tr:hover { background: #f1f5f9; }
        .ms-container { position: relative; display: inline-block; }
        .ms-btn {
            height: 34px !important;
            padding: 4px 12px !important;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s;
            min-width: 220px;
        }
        .ms-btn:hover { border-color: #94a3b8; background: #f8fafc; }
        .ms-btn-text { text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 240px; font-weight: 600; }

        .ms-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            z-index: 9999;
            display: none;
            padding: 10px;
            text-align: left;
        }
        .ms-dropdown.open { display: block; animation: msFadeIn 0.15s ease-out; }
        @keyframes msFadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

        .ms-search { width: 100%; padding: 6px 10px 6px 26px !important; font-size: 12px !important; height: 30px !important; border: 1px solid #cbd5e1 !important; border-radius: 4px !important; outline: none; }
        .ms-search:focus { border-color: #3b82f6 !important; box-shadow: 0 0 0 2px rgba(59,130,246,0.15); }

        .ms-actions { display: flex; justify-content: space-between; align-items: center; padding: 4px 2px 8px 2px; border-bottom: 1px solid #f1f5f9; margin-bottom: 6px; font-size: 11px; }
        .ms-action-btn { background: none; border: none; color: #3b82f6; font-size: 11px; font-weight: 600; cursor: pointer; padding: 2px 4px; }
        .ms-action-btn:hover { text-decoration: underline; color: #1d4ed8; }

        .ms-options-list { max-height: 220px; overflow-y: auto; display: flex; flex-direction: column; gap: 2px; }
        .ms-option-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: #334155;
            cursor: pointer;
            user-select: none;
            transition: background 0.12s;
            margin: 0;
        }
        .ms-option-item:hover { background: #f1f5f9; color: #0f172a; }
        .ms-option-item input[type="checkbox"] { width: 15px; height: 15px; accent-color: #003087; cursor: pointer; flex-shrink: 0; }
        .ms-option-label { flex: 1; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; font-weight: 500; }
    </style>';
    echo '<script>
    function toggleMsDropdown(fieldName) {
        const dd = document.getElementById("ms-dropdown-" + fieldName);
        if (!dd) return;
        const isOpen = dd.classList.contains("open");
        document.querySelectorAll(".ms-dropdown.open").forEach(d => d.classList.remove("open"));
        if (!isOpen) dd.classList.add("open");
    }
    document.addEventListener("click", function(e) {
        if (!e.target.closest(".ms-container")) {
            document.querySelectorAll(".ms-dropdown.open").forEach(d => d.classList.remove("open"));
        }
    });
    function filterMsOptions(fieldName, query) {
        const q = query.toLowerCase().trim();
        const items = document.querySelectorAll("#ms-list-" + fieldName + " .ms-option-item");
        items.forEach(item => {
            const text = item.getAttribute("data-text") || "";
            item.style.display = text.includes(q) ? "flex" : "none";
        });
    }
    function selectMsAll(fieldName, selectAll) {
        const checkboxes = document.querySelectorAll("#ms-list-" + fieldName + " input[type=\"checkbox\"]");
        checkboxes.forEach(cb => {
            if (cb.closest(".ms-option-item").style.display !== "none") {
                cb.checked = selectAll;
            }
        });
        updateMsLabel(fieldName);
    }
    function updateMsLabel(fieldName) {
        const checkboxes = document.querySelectorAll("#ms-list-" + fieldName + " input[type=\"checkbox\"]");
        const checked = Array.from(checkboxes).filter(cb => cb.checked);
        const textEl = document.getElementById("ms-text-" + fieldName);
        if (!textEl) return;
        if (checked.length === 0 || checked.length === checkboxes.length) {
            textEl.textContent = "All Accounts";
        } else if (checked.length === 1) {
            const label = checked[0].closest(".ms-option-item").querySelector(".ms-option-label").textContent;
            textEl.textContent = label;
        } else {
            textEl.textContent = checked.length + " Accounts Selected";
        }
    }
    
    </script>';
    $sys_company_name    = $sys['name'] ?? ($sys['company_name'] ?? 'MNS LIQUORS (P) LTD.');
    $sys_company_address = $sys['address'] ?? ($sys['company_address'] ?? 'Kathmandu, Nepal');
    $sys_company_phone   = $sys['contact'] ?? ($sys['company_phone'] ?? ($sys['phone'] ?? ''));
    $sys_company_pan     = $sys['pan_no'] ?? ($sys['company_pan'] ?? ($sys['pan_vat_number'] ?? ($sys['pan'] ?? '')));
    $sys_company_email   = $sys['email'] ?? ($sys['company_email'] ?? '');
    $sys_short_name      = $sys['short_name'] ?? 'MNS';
    static $shutdown_registered = false;
    if (!$shutdown_registered) {
        $shutdown_registered = true;
        register_shutdown_function(function() {
            echo '</td></tr></tbody></table>';
        });
    }

    echo '<table class="rpt-main-print-wrapper" style="width:100%; border-collapse:collapse; border:none; margin:0; padding:0;">';
    echo '<thead class="rpt-main-print-thead" style="display:table-header-group;"><tr><td style="border:none; padding:0;">';

    echo '  <div class="rpt-header-print" style="display:none; align-items: center; justify-content: space-between; border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 15px;">';
    echo '    <div class="header-logo" style="flex: 0 0 140px; text-align: left;">';
    if (!empty($sys_logo) && (file_exists($sys_logo) || file_exists('../' . $sys_logo))) {
        echo '        <img src="'.htmlspecialchars($sys_logo).'" alt="Logo" style="max-height: 75px; max-width: 130px; object-fit: contain;">';
    } else {
        echo '        <div style="font-size: 26px; font-weight: 900; color: #003087; line-height: 1; letter-spacing: -0.5px;">'.htmlspecialchars($sys_short_name).'</div>';
    }
    echo '    </div>';
    echo '    <div class="header-details" style="flex: 1; text-align: right;">';
    echo '        <div class="company-name" style="font-size: 22px; font-weight: 800; color: #003087; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.5px;">'.htmlspecialchars($sys_company_name).'</div>';
    echo '        <div class="company-info" style="font-size: 12px; color: #475569; line-height: 1.3;">';
    echo '            '.nl2br(htmlspecialchars($sys_company_address)).'<br>';
    echo '            Phone: '.htmlspecialchars($sys_company_phone);
    if (!empty($sys_company_email)) {
        echo ' | Email: '.htmlspecialchars($sys_company_email);
    }
    echo '            <br>';
    echo '            <strong style="color: #0f172a;">PAN / VAT No: '.htmlspecialchars($sys_company_pan).'</strong>';
    echo '        </div>';
    echo '    </div>';
    echo '  </div>';

    echo '  <div class="rpt-doc-title-print" style="display:none; text-align: center; font-size: 16px; font-weight: 800; margin: 12px 0 18px 0; text-transform: uppercase; letter-spacing: 0.5px; background: #f1f5f9; padding: 6px 0; border-radius: 4px; border: 1px solid #cbd5e1; color: #003087;">';
    echo htmlspecialchars($title);
    echo '  </div>';

    echo '</td></tr></thead>';
    echo '<tbody><tr><td style="border:none; padding:0;">';
}

function rpt_get_current_fiscal_year_dates(): array {
    static $fy_dates = null;
    if ($fy_dates !== null) return $fy_dates;
    
    $today = date('Y-m-d');
    $db = db();
    $active_fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE ? BETWEEN start_date AND end_date LIMIT 1", [$today]);
    if (!$active_fy) {
        $active_fy = $db->fetchOne("SELECT * FROM fiscal_years WHERE status IN ('open', 'reopened') ORDER BY start_date DESC LIMIT 1");
    }
    if (!$active_fy) {
        $active_fy = $db->fetchOne("SELECT * FROM fiscal_years ORDER BY start_date DESC LIMIT 1");
    }
    
    if ($active_fy) {
        $fy_dates = [
            'start_date' => $active_fy['start_date'],
            'end_date'   => $active_fy['end_date'],
            'name'       => $active_fy['name'],
        ];
    } else {
        $m = (int)date('n'); $d = (int)date('j'); $y = (int)date('Y');
        if ($m > 7 || ($m == 7 && $d >= 16)) {
            $s = "{$y}-07-16"; $e = ($y+1) . "-07-15";
        } else {
            $s = ($y-1) . "-07-16"; $e = "{$y}-07-15";
        }
        $fy_dates = [
            'start_date' => $s,
            'end_date'   => $e,
            'name'       => 'Current FY',
        ];
    }
    return $fy_dates;
}

function rpt_filter_bar(string $title, array $filters, string $export_id = '', string $extra_html = '') {
    rpt_header($title);
    
    $fy_info = rpt_get_current_fiscal_year_dates();

    // Auto-default date filter fields to current active fiscal year if not provided or set to legacy monthly default
    foreach ($filters as &$f) {
        if (($f['type'] ?? '') === 'date') {
            if (($f['name'] ?? '') === 'date_from' || ($f['name'] ?? '') === 'from_date') {
                if (!isset($_GET[$f['name']]) && (empty($f['default']) || $f['default'] === date('Y-m-01') || $f['default'] === date('Y-01-01'))) {
                    $f['default'] = $fy_info['start_date'];
                }
            }
            if (($f['name'] ?? '') === 'date_to' || ($f['name'] ?? '') === 'to_date') {
                if (!isset($_GET[$f['name']]) && (empty($f['default']) || $f['default'] === date('Y-m-d'))) {
                    $f['default'] = $fy_info['end_date'];
                }
            }
        }
    }
    unset($f);

    // Build filter parameter summary string for printout
    $summary_parts = [];
    foreach ($filters as $f) {
        $lbl = $f['label'] ?? '';
        $fieldName = $f['name'] ?? '';
        $raw_val = $_GET[$fieldName] ?? ($f['default'] ?? '');
        
        if ($f['type'] === 'date') {
            if (!empty($raw_val)) {
                $summary_parts[] = '<strong>' . htmlspecialchars($lbl) . ':</strong> ' . htmlspecialchars((string)$raw_val);
            }
        } elseif (!empty($f['options'])) {
            $val_str = is_array($raw_val) ? implode(',', $raw_val) : (string)$raw_val;
            $opt_txt = $f['options'][$val_str] ?? ($val_str === '' ? 'All' : $val_str);
            $summary_parts[] = '<strong>' . htmlspecialchars($lbl) . ':</strong> ' . htmlspecialchars((string)$opt_txt);
        }
    }
    
    if (!empty($summary_parts)) {
        echo '<div class="rpt-filter-info-print" style="display:none; text-align: center; font-size: 11px; font-weight: 500; color: #475569; margin: -10px 0 15px 0; padding: 4px 10px; background: #f8fafc; border-radius: 4px; border: 1px dashed #cbd5e1;">';
        echo implode(' &nbsp;|&nbsp; ', $summary_parts);
        echo ' &nbsp;|&nbsp; <strong>Printed On:</strong> ' . date('Y-m-d h:i A');
        echo '</div>';
    }

    // Ensure location filter is present in filters array
    $has_loc_filter = false;
    foreach ($filters as $f) {
        if (($f['name'] ?? '') === 'location_id' || ($f['name'] ?? '') === 'location') {
            $has_loc_filter = true;
            break;
        }
    }
    if (!$has_loc_filter) {
        $filters[] = rpt_location_filter();
    }

    $today = date('Y-m-d');
    $month_start = date('Y-m-01');
    echo '<div class="rpt-toolbar">';
    echo '<div class="rpt-title"><i class="fas fa-chart-bar"></i> '.$title.'</div>';
    echo '<form method="GET" class="rpt-filter-form" id="rpt-filter-form">';
    echo '<input type="hidden" name="page" value="'.htmlspecialchars($_GET['page'] ?? '').'">';
    foreach ($filters as $f) {
        $raw_val = $_GET[$f['name']] ?? ($f['default'] ?? '');
        $val = is_array($raw_val) ? implode(',', array_map('htmlspecialchars', $raw_val)) : htmlspecialchars((string)$raw_val);
        echo '<div class="rpt-filter-group">';
        echo '<label>'.$f['label'].'</label>';
        if ($f['type'] === 'date') {
            echo '<input type="date" name="'.$f['name'].'" value="'.$val.'" class="ns-input rpt-input">';
        } elseif ($f['type'] === 'multiselect' || (!empty($f['multiple']) && $f['type'] === 'select')) {
            $fieldName = $f['name'];
            $selected_vals = isset($_GET[$fieldName]) ? $_GET[$fieldName] : ($f['default'] ?? []);
            if (!is_array($selected_vals)) {
                if (is_string($selected_vals) && strpos($selected_vals, ',') !== false) {
                    $selected_vals = array_filter(explode(',', $selected_vals));
                } elseif ($selected_vals !== '') {
                    $selected_vals = [$selected_vals];
                } else {
                    $selected_vals = [];
                }
            }
            $selected_vals = array_map('strval', $selected_vals);

            // Resolve options label text
            $selected_labels = [];
            $total_count = count($f['options'] ?? []);
            foreach ($f['options'] as $ov => $ol) {
                if ($ov === '') continue;
                if (in_array((string)$ov, $selected_vals, true)) {
                    $selected_labels[] = $ol;
                }
            }

            $sel_cnt = count($selected_labels);
            if ($sel_cnt === 0 || $sel_cnt === $total_count) {
                $btn_label = 'All Accounts';
            } elseif ($sel_cnt === 1) {
                $btn_label = $selected_labels[0];
            } else {
                $btn_label = $sel_cnt . ' Accounts Selected';
            }

            echo '<div class="ms-container" id="ms-wrapper-'.htmlspecialchars($fieldName).'">';
            echo '  <button type="button" class="ms-btn" onclick="toggleMsDropdown(\''.htmlspecialchars($fieldName).'\')">';
            echo '    <span class="ms-btn-text" id="ms-text-'.htmlspecialchars($fieldName).'">'.htmlspecialchars($btn_label).'</span>';
            echo '    <i class="fas fa-chevron-down" style="font-size: 10px; color: #64748b; margin-left: 8px;"></i>';
            echo '  </button>';
            echo '  <div class="ms-dropdown" id="ms-dropdown-'.htmlspecialchars($fieldName).'">';
            echo '    <div class="ms-search-wrap" style="position:relative; margin-bottom:6px;">';
            echo '      <i class="fas fa-search" style="position:absolute; left:8px; top:9px; font-size:11px; color:#94a3b8;"></i>';
            echo '      <input type="text" class="ms-search" placeholder="Search accounts..." onkeyup="filterMsOptions(\''.htmlspecialchars($fieldName).'\', this.value)">';
            echo '    </div>';
            echo '    <div class="ms-actions">';
            echo '      <button type="button" class="ms-action-btn" onclick="selectMsAll(\''.htmlspecialchars($fieldName).'\', true)">Select All</button>';
            echo '      <span style="color:#e2e8f0;">|</span>';
            echo '      <button type="button" class="ms-action-btn" onclick="selectMsAll(\''.htmlspecialchars($fieldName).'\', false)">Clear All</button>';
            echo '    </div>';
            echo '    <div class="ms-options-list" id="ms-list-'.htmlspecialchars($fieldName).'">';

            foreach ($f['options'] as $ov => $ol) {
                if ($ov === '') continue;
                $is_checked = in_array((string)$ov, $selected_vals, true);
                echo '      <label class="ms-option-item" data-text="'.htmlspecialchars(strtolower($ol)).'">';
                echo '        <input type="checkbox" name="'.htmlspecialchars($fieldName).'[]" value="'.htmlspecialchars($ov).'"'.($is_checked ? ' checked' : '').' onchange="updateMsLabel(\''.htmlspecialchars($fieldName).'\')">';
                echo '        <span class="ms-option-label">'.htmlspecialchars($ol).'</span>';
                echo '      </label>';
            }

            echo '    </div>';
            echo '  </div>';
            echo '</div>';
        } elseif ($f['type'] === 'select') {
            echo '<select name="'.$f['name'].'" class="ns-select rpt-input">';
            foreach ($f['options'] as $ov => $ol) {
                $sel = ($val == $ov) ? ' selected' : '';
                echo '<option value="'.htmlspecialchars($ov).'"'.$sel.'>'.htmlspecialchars($ol).'</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="text" name="'.$f['name'].'" value="'.$val.'" class="ns-input rpt-input">';
        }
        echo '</div>';
    }
    echo '<button type="submit" class="ns-btn ns-btn-primary"><i class="fas fa-search"></i> Run</button>';
    if ($export_id) {
        echo '<button type="button" class="ns-btn" onclick="exportTableToCSV(\''.$export_id.'\')"><i class="fas fa-file-csv"></i> CSV</button>';
        echo '<button type="button" class="ns-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>';
    }
    if (!empty($extra_html)) {
        echo ' ' . $extra_html;
    }
    echo '</form>';
    echo '</div>';
}

function rpt_currency(float $v): string {
    static $dp = null;
    if ($dp === null) {
        $db = db();
        $dp = (int)($db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'decimal_places'")['meta_value'] ?? 2);
    }
    return 'Rs '.number_format($v, $dp);
}

function rpt_date($date): string {
    static $df = null;
    if ($df === null) {
        $db = db();
        $df = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'date_format'")['meta_value'] ?? 'Y-m-d';
    }
    return date($df, strtotime($date));
}
function rpt_badge(string $text, string $color = '#888'): string {
    return '<span style="background:'.$color.';color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">'.$text.'</span>';
}

function rpt_get_location_options(): array {
    static $loc_options = null;
    if ($loc_options === null) {
        $db = db();
        $rows = $db->fetchAll("SELECT id, name FROM locations WHERE is_deleted = 0 AND is_active = 1 ORDER BY name ASC");
        $loc_options = ['' => 'All Locations'];
        foreach ($rows as $r) {
            $loc_options[$r['id']] = $r['name'];
        }
    }
    return $loc_options;
}

function rpt_location_filter(string $name = 'location_id', string $label = 'Location'): array {
    $default_loc = $_GET[$name] ?? ($_SESSION['location_id'] ?? (function_exists('get_user_default_location_id') ? get_user_default_location_id() : ''));
    return [
        'name' => $name,
        'label' => $label,
        'type' => 'select',
        'default' => $default_loc,
        'options' => rpt_get_location_options()
    ];
}
function rpt_location_sql(string $alias = 'h'): string {
    $loc_id = $_GET['location_id'] ?? ($_SESSION['location_id'] ?? (function_exists('get_user_default_location_id') ? get_user_default_location_id() : ''));
    if (!empty($loc_id) && $loc_id !== 'all') {
        $db = db();
        return " AND {$alias}.location_id = " . $db->getConnection()->quote($loc_id) . " ";
    }
    return "";
}

function get_customer_aging_summary($db, string $customer_id, ?string $as_of_date = null, ?string $location_id = null): array {
    if (!$as_of_date) $as_of_date = date('Y-m-d');
    $today = date('Y-m-d');
    if ($as_of_date > $today) {
        $as_of_date = $today;
    }

    $loc_sql = "";
    if ($location_id !== null && $location_id !== '' && $location_id !== 'all') {
        $loc_sql = " AND th.location_id = " . $db->getConnection()->quote($location_id) . " ";
    } else {
        $loc_sql = rpt_location_sql('th');
    }
    
    // 1. Calculate Total Customer Net Balance as of date (Opening Balances + Invoices + Debit Journals + Customer Refunds - Received Payments - Credit Memos - Credit Journals)
    $inv_total = (float)($db->fetchOne("
        SELECT COALESCE(SUM(ci.total_amount), 0) as total 
        FROM customer_invoices ci 
        JOIN transaction_headers th ON ci.header_id = th.id 
        WHERE ci.customer_id = ? AND th.txn_date <= ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 {$loc_sql}
    ", [$customer_id, $as_of_date])['total'] ?? 0);
    
    $jour_debits = (float)($db->fetchOne("
        SELECT COALESCE(SUM(j.amount), 0) as total 
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id 
        WHERE (j.party_id = CAST(? AS CHAR) OR th.party_id = CAST(? AS CHAR)) AND (j.party_type = 'customer' OR j.party_type IS NULL) 
          AND j.entry_type = 'debit'
          AND th.txn_date <= ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 AND th.txn_type IN ('Journal', 'journal_entry') {$loc_sql}
    ", [$customer_id, $customer_id, $as_of_date])['total'] ?? 0);

    $jour_credits = (float)($db->fetchOne("
        SELECT COALESCE(SUM(j.amount), 0) as total 
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id 
        WHERE (j.party_id = CAST(? AS CHAR) OR th.party_id = CAST(? AS CHAR)) AND (j.party_type = 'customer' OR j.party_type IS NULL) 
          AND j.entry_type = 'credit'
          AND th.txn_date <= ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 AND th.txn_type IN ('Journal', 'journal_entry') {$loc_sql}
    ", [$customer_id, $customer_id, $as_of_date])['total'] ?? 0);

    // Payments Received (Money IN)
    $pay_total = (float)($db->fetchOne("
        SELECT COALESCE(SUM(p.amount), 0) as total 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id
        WHERE p.customer_id = ? AND p.payment_date <= ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
          AND th.id NOT IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ", [$customer_id, $as_of_date])['total'] ?? 0);

    // Customer Refunds (Money OUT to customer for Credit Memo / Return)
    $refund_total = (float)($db->fetchOne("
        SELECT COALESCE(SUM(p.amount), 0) as total 
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id
        WHERE p.customer_id = ? AND p.payment_date <= ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
          AND th.id IN (
              SELECT tl.parent_id FROM transaction_links tl
              JOIN transaction_headers ch ON tl.child_id = ch.id
              WHERE ch.txn_type IN ('credit_memo', 'Credit Memo') OR tl.link_type LIKE 'payment:-%'
          )
    ", [$customer_id, $as_of_date])['total'] ?? 0);

    $cm_total = (float)($db->fetchOne("
        SELECT COALESCE(SUM(COALESCE(cm.total_amount, th.net_amount)), 0) as total 
        FROM transaction_headers th
        LEFT JOIN credit_memos cm ON cm.header_id = th.id 
        WHERE (cm.customer_id = ? OR (th.party_id = CAST(? AS CHAR) AND (th.party_type = 'customer' OR th.party_type IS NULL)))
          AND th.txn_type IN ('credit_memo', 'Credit Memo')
          AND th.txn_date <= ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 {$loc_sql}
    ", [$customer_id, $customer_id, $as_of_date])['total'] ?? 0);

    // Fetch opening balance entries before or on as_of_date
    $jour_op = (float)($db->fetchOne("
        SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END), 0) as total 
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id 
        WHERE (j.party_id = CAST(? AS CHAR) OR th.party_id = CAST(? AS CHAR)) AND (j.party_type = 'customer' OR j.party_type IS NULL) 
          AND th.txn_type IN ('Opening Balance', 'Opening_Balance', 'opening_balance')
          AND th.txn_date <= ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 {$loc_sql}
    ", [$customer_id, $customer_id, $as_of_date])['total'] ?? 0);

    $net_customer_balance = ($jour_op + $inv_total + $jour_debits + $refund_total) - ($pay_total + $cm_total + $jour_credits);

    $aging7  = ['current' => 0.0, '1_7' => 0.0, '8_14' => 0.0, '15_21' => 0.0, 'over_21' => 0.0];
    $aging30 = ['current' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'over_90' => 0.0];

    if ($net_customer_balance <= 0.001) {
        $aging7['current'] = round($net_customer_balance, 2);
        $aging30['current'] = round($net_customer_balance, 2);
        return [
            'aging7' => $aging7,
            'aging30' => $aging30,
            'total_due' => round($net_customer_balance, 2)
        ];
    }

    // 2. Fetch all debit documents sorted by date DESC (Newest to Oldest)
    $debit_docs = [];

    $invoices = $db->fetchAll("
        SELECT th.txn_date as doc_date, GREATEST(0, ci.total_amount - ci.amount_paid) as amount
        FROM customer_invoices ci
        JOIN transaction_headers th ON ci.header_id = th.id
        WHERE ci.customer_id = ? AND th.txn_date <= ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0
          AND (ci.total_amount - ci.amount_paid) > 0.001 {$loc_sql}
        ORDER BY th.txn_date DESC
    ", [$customer_id, $as_of_date]);
    foreach ($invoices as $i) { $debit_docs[] = ['doc_date' => $i['doc_date'], 'amount' => (float)$i['amount']]; }

    $journals = $db->fetchAll("
        SELECT th.txn_date as doc_date, j.amount
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (j.party_id = CAST(? AS CHAR) OR th.party_id = CAST(? AS CHAR)) AND (j.party_type = 'customer' OR j.party_type IS NULL)
          AND j.entry_type = 'debit'
          AND th.txn_type IN ('Journal', 'journal_entry')
          AND th.txn_date <= ? AND th.status NOT IN ('void', 'voided', 'draft') AND th.is_deleted = 0 {$loc_sql}
        ORDER BY th.txn_date DESC
    ", [$customer_id, $customer_id, $as_of_date]);
    foreach ($journals as $j) { $debit_docs[] = ['doc_date' => $j['doc_date'], 'amount' => (float)$j['amount']]; }

    usort($debit_docs, function($a, $b) {
        return strtotime($b['doc_date']) - strtotime($a['doc_date']);
    });

    $remaining_balance = $net_customer_balance;

    foreach ($debit_docs as $doc) {
        if ($remaining_balance <= 0.001) break;
        $alloc = min($doc['amount'], $remaining_balance);
        $remaining_balance -= $alloc;

        $days = (int)floor((strtotime($as_of_date) - strtotime($doc['doc_date'])) / 86400);

        if ($days <= 0)      $aging7['current'] += $alloc;
        elseif ($days <= 7)  $aging7['1_7']     += $alloc;
        elseif ($days <= 14) $aging7['8_14']    += $alloc;
        elseif ($days <= 21) $aging7['15_21']   += $alloc;
        else                 $aging7['over_21'] += $alloc;

        if ($days <= 0)       $aging30['current'] += $alloc;
        elseif ($days <= 30)  $aging30['b30']     += $alloc;
        elseif ($days <= 60)  $aging30['b60']     += $alloc;
        elseif ($days <= 90)  $aging30['b90']     += $alloc;
        else                  $aging30['over_90'] += $alloc;
    }

    if ($remaining_balance > 0.001) {
        $aging7['over_21'] += $remaining_balance;
        $aging30['over_90'] += $remaining_balance;
    }

    return [
        'aging7' => $aging7,
        'aging30' => $aging30,
        'total_due' => round($net_customer_balance, 2)
    ];
}

function get_vendor_aging_summary($db, string $vendor_id, ?string $as_of_date = null): array {
    if (!$as_of_date) $as_of_date = date('Y-m-d');
    $today = date('Y-m-d');
    if ($as_of_date > $today) {
        $as_of_date = $today;
    }
    
    $bills_total = (float)($db->fetchOne("
        SELECT COALESCE(SUM(vb.total_amount), 0) as total
        FROM vendor_bills vb
        JOIN transaction_headers th ON vb.header_id = th.id
        WHERE vb.vendor_id = ? AND th.txn_date <= ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ", [$vendor_id, $as_of_date])['total'] ?? 0);

    $jour_credits = (float)($db->fetchOne("
        SELECT COALESCE(SUM(j.amount), 0) as total
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (j.party_id = ? OR th.party_id = ?) AND (j.party_type = 'vendor' OR j.party_type IS NULL)
          AND j.entry_type = 'credit'
          AND th.txn_date <= ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    ", [$vendor_id, $vendor_id, $as_of_date])['total'] ?? 0);

    $jour_debits = (float)($db->fetchOne("
        SELECT COALESCE(SUM(j.amount), 0) as total
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (j.party_id = ? OR th.party_id = ?) AND (j.party_type = 'vendor' OR j.party_type IS NULL)
          AND j.entry_type = 'debit'
          AND th.txn_date <= ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft') AND th.txn_type IN ('Journal', 'journal_entry')
    ", [$vendor_id, $vendor_id, $as_of_date])['total'] ?? 0);

    $jour_op = (float)($db->fetchOne("
        SELECT COALESCE(SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END), 0) as total
        FROM journal_entries j
        JOIN transaction_headers th ON j.header_id = th.id
        WHERE (j.party_id = ? OR th.party_id = ?) AND (j.party_type = 'vendor' OR j.party_type IS NULL)
          AND th.txn_type IN ('Opening Balance', 'Opening_Balance', 'opening_balance')
          AND th.txn_date <= ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ", [$vendor_id, $vendor_id, $as_of_date])['total'] ?? 0);

    $pay_total = (float)($db->fetchOne("
        SELECT COALESCE(SUM(p.amount), 0) as total
        FROM payments p
        JOIN transaction_headers th ON p.header_id = th.id
        WHERE (p.vendor_id = ? OR th.party_id = ?) AND p.payment_date <= ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ", [$vendor_id, $vendor_id, $as_of_date])['total'] ?? 0);

    $net_vendor_balance = ($jour_op + $bills_total + $jour_credits) - ($pay_total + $jour_debits);

    return [
        'total_due' => round($net_vendor_balance, 2)
    ];
}
?>
