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
            .rpt-header-print { display: block !important; } 
            .rpt-toolbar, .ns-header, .ns-nav, .rpt-summary, .ns-card-header, .ns-card-tools, form { display: none !important; } 
            .ns-card, .ns-portlet { border: none !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; } 
            .ns-portlet-content { padding: 0 !important; }
            body { background: #fff !important; margin: 0; padding: 0; } 
            .ns-table { width: 100% !important; border-collapse: collapse !important; }
            .ns-table th, .ns-table td { border: 1px solid #eee !important; padding: 8px !important; }
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
    echo '<div class="rpt-header-print" style="display:none; text-align:center; margin-bottom: 10px; border-bottom: 2px solid #333; padding-bottom: 6px;">';
    if (!empty($sys['logo'])) {
        echo '<img src="'.$sys['logo'].'" style="max-height: 40px; margin-bottom: 4px;"><br>';
    }
    echo '<h2 style="margin:0; text-transform:uppercase; font-size: 16px;">'.htmlspecialchars($sys['name'] ?? 'MNS LIQUORS').'</h2>';
    echo '<p style="margin:2px 0; font-size:11px; line-height: 1.3;">'.nl2br(htmlspecialchars($sys['address'] ?? '')).'</p>';
    echo '<p style="margin:2px 0; font-size:11px;">PAN: '.htmlspecialchars($sys['pan_no'] ?? '').' | Phone: '.htmlspecialchars($sys['contact'] ?? '').'</p>';
    echo '<h3 style="margin:6px 0 2px 0; border-top: 1px solid #ddd; padding-top: 4px; font-size: 14px;">'.$title.'</h3>';
    echo '</div>';
}

function rpt_filter_bar(string $title, array $filters, string $export_id = '') {
    rpt_header($title);
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

            $total_count = 0;
            $selected_labels = [];
            foreach ($f['options'] as $ov => $ol) {
                if ($ov === '') continue;
                $total_count++;
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
?>
