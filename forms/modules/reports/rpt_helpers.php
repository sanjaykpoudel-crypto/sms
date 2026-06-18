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
        
        .rpt-summary { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .rpt-summary-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; flex: 1; min-width: 160px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
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
        .ns-report-table-static tfoot { font-weight: 700; background: #f8f9fa; }
    </style>';
    echo '<div class="rpt-header-print" style="display:none; text-align:center; margin-bottom: 25px; border-bottom: 2px solid #333; padding-bottom: 15px;">';
    if (!empty($sys['logo'])) {
        echo '<img src="'.$sys['logo'].'" style="max-height: 70px; margin-bottom: 12px;"><br>';
    }
    echo '<h2 style="margin:0; text-transform:uppercase; font-size: 24px;">'.htmlspecialchars($sys['name'] ?? 'MNS LIQUORS').'</h2>';
    echo '<p style="margin:5px 0; font-size:14px; line-height: 1.4;">'.nl2br(htmlspecialchars($sys['address'] ?? '')).'</p>';
    echo '<p style="margin:5px 0; font-size:14px;">PAN: '.htmlspecialchars($sys['pan_no'] ?? '').' | Phone: '.htmlspecialchars($sys['contact'] ?? '').'</p>';
    echo '<h3 style="margin:20px 0 5px 0; border-top: 1px solid #ddd; padding-top: 15px; font-size: 20px;">'.$title.'</h3>';
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
        $val = htmlspecialchars($_GET[$f['name']] ?? ($f['default'] ?? ''));
        echo '<div class="rpt-filter-group">';
        echo '<label>'.$f['label'].'</label>';
        if ($f['type'] === 'date') {
            echo '<input type="date" name="'.$f['name'].'" value="'.$val.'" class="ns-input rpt-input">';
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
    $db = db();
    $dp = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'decimal_places'")['meta_value'] ?? 2;
    return 'Rs '.number_format($v, (int)$dp);
}

function rpt_date($date): string {
    $db = db();
    $df = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'date_format'")['meta_value'] ?? 'Y-m-d';
    return date($df, strtotime($date));
}

function rpt_badge(string $text, string $color = '#888'): string {
    return '<span style="background:'.$color.';color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">'.$text.'</span>';
}
?>
