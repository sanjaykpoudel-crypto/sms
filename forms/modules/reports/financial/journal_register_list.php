<?php
/**
 * Journal Register & Audit Report
 * Complete record of all General Ledger Journal Entries and Posting Status
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;
$txn_type  = $_GET['txn_type']  ?? '';

$loc_sql = rpt_location_sql('h');

$where_type = "";
$params = [$date_from, $date_to];
if (!empty($txn_type)) {
    $where_type = " AND h.txn_type = ? ";
    $params[] = $txn_type;
}

$journals = $db->fetchAll("
    SELECT h.id as header_id, h.txn_number, h.txn_date, h.txn_type, h.memo, h.created_by, h.status,
           u.full_name as user_name
    FROM transaction_headers h
    LEFT JOIN users u ON h.created_by = u.id
    WHERE h.txn_date BETWEEN ? AND ? AND h.is_deleted = 0 {$where_type} {$loc_sql}
    ORDER BY h.txn_date DESC, h.id DESC
", $params);

// Fetch journal entry lines for these headers
$header_ids = array_column($journals, 'header_id');
$lines_by_header = [];
if (!empty($header_ids)) {
    $in_clause = implode(',', array_fill(0, count($header_ids), '?'));
    $lines = $db->fetchAll("
        SELECT je.transaction_id as header_id, jl.debit, jl.credit, je.je_date as entry_date, a.id as account_id, a.account_name,
               COALESCE(c.full_name, v.company_name, u.full_name) as entity_name
        FROM journal_lines jl
        JOIN journal_entries je ON jl.je_id = je.je_id
        JOIN accounts a ON jl.account_id = a.id
        LEFT JOIN customers c ON jl.entity_id = c.id AND jl.entity_type = 'CUSTOMER'
        LEFT JOIN vendors v ON jl.entity_id = v.id AND jl.entity_type = 'VENDOR'
        LEFT JOIN users u ON jl.entity_id = u.id AND jl.entity_type = 'USER'
        WHERE je.transaction_id IN ($in_clause)
        ORDER BY jl.jl_id ASC
    ", $header_ids);

    foreach ($lines as $l) {
        $lines_by_header[$l['header_id']][] = $l;
    }
}

// Calculate total debits & credits
$tot_debit = 0.0;
$tot_credit = 0.0;
foreach ($journals as $j) {
    $hid = $j['header_id'];
    if (isset($lines_by_header[$hid])) {
        foreach ($lines_by_header[$hid] as $l) {
            $tot_debit += (float)$l['debit'];
            $tot_credit += (float)$l['credit'];
        }
    }
}
?>

<style>
    .jr-header-row { cursor: pointer; transition: background-color 0.15s; }
    .jr-header-row:hover { background-color: #f1f5f9 !important; }
    .jr-detail-row.collapsed { display: none; }
    .jr-toggle-btn {
        background: #fff; border: 1px solid #cbd5e1; color: #1e293b;
        padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;
        cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
    }
    .jr-toggle-btn:hover { background: #f8fafc; border-color: #94a3b8; }
</style>

<?php rpt_filter_bar('Journal Register & GL Audit Trail', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    ['name' => 'txn_type', 'label' => 'Txn Type', 'type' => 'select', 'options' => ['' => 'All Types', 'journal' => 'Journal Entry', 'customer_invoice' => 'Invoice', 'vendor_bill' => 'Vendor Bill', 'customer_payment' => 'Customer Payment', 'vendor_payment' => 'Vendor Payment'], 'default' => $txn_type],
    rpt_location_filter(),
], 'tbl-journal-register'); ?>

<div class="ns-portlet" style="max-width: 1100px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 15px;">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; padding:12px 16px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; flex-wrap:wrap; gap:10px;">
            <div style="font-size:13px; font-weight:700; color:#0369a1;">
                <i class="fas fa-book"></i> Journal Register Audit Summary
            </div>
            <div style="display:flex; align-items:center; gap:15px; font-size:12px; font-weight:600;">
                <div>
                    Total Posted Entries: <strong><?= count($journals) ?></strong> | 
                    Debits: <strong style="color:#dc2626;"><?= rpt_currency($tot_debit) ?></strong> | 
                    Credits: <strong style="color:#059669;"><?= rpt_currency($tot_credit) ?></strong>
                </div>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="jr-toggle-btn" id="btn-expand-all">
                        <i class="fas fa-chevron-down" style="font-size:10px; color:#059669;"></i> Expand All
                    </button>
                    <button type="button" class="jr-toggle-btn" id="btn-collapse-all">
                        <i class="fas fa-chevron-right" style="font-size:10px; color:#dc2626;"></i> Collapse All
                    </button>
                </div>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-journal-register">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Txn #</th>
                    <th>Type</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Account Details</th>
                    <th style="text-align:right">Debit (NPR)</th>
                    <th style="text-align:right">Credit (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($journals)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">No journal entries found for selected criteria.</td></tr>
                <?php else: ?>
                    <?php foreach ($journals as $j): 
                        $hid = $j['header_id'];
                        $jlines = $lines_by_header[$hid] ?? [];
                    ?>
                        <!-- Header Row -->
                        <tr class="jr-header-row" data-header-id="<?= $hid ?>" style="background:#f8fafc; font-weight:700;">
                            <td><?= rpt_date($j['txn_date']) ?></td>
                            <td style="color:#003087;">
                                <i class="fas fa-chevron-down jr-icon" id="icon-<?= $hid ?>" style="margin-right:6px; font-size:11px; color:#003087; transition:transform 0.2s;"></i>
                                <?= htmlspecialchars($j['txn_number']) ?>
                            </td>
                            <td><span class="ns-badge" style="background:#e2e8f0; color:#334155; padding:2px 6px; border-radius:4px; font-size:10px; text-transform:uppercase;"><?= htmlspecialchars($j['txn_type']) ?></span></td>
                            <td><?= htmlspecialchars($j['user_name'] ?: 'System') ?></td>
                            <td><span class="ns-badge" style="background:#dcfce7; color:#166534; padding:2px 6px; border-radius:4px; font-size:10px; text-transform:uppercase;"><?= htmlspecialchars($j['status']) ?></span></td>
                            <td colspan="3" style="color:#64748b; font-weight:500;"><?= htmlspecialchars($j['memo'] ?: '—') ?></td>
                        </tr>
                        <!-- Detail Lines Rows -->
                        <?php foreach ($jlines as $l): ?>
                            <tr class="jr-detail-row detail-<?= $hid ?>">
                                <td colspan="5" style="border-right:none;"></td>
                                <td style="padding-left:20px; font-weight:500; color:#1e293b;">
                                    <?= htmlspecialchars($l['account_name']) ?>
                                    <?php if (!empty($l['entity_name'])): ?>
                                        <span style="font-size:11px; color:#64748b; margin-left:6px;">(<?= htmlspecialchars($l['entity_name']) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; color:<?= (float)$l['debit'] > 0 ? '#dc2626' : '#94a3b8' ?>; font-weight:600;">
                                    <?= (float)$l['debit'] > 0 ? rpt_currency((float)$l['debit']) : '—' ?>
                                </td>
                                <td style="text-align:right; color:<?= (float)$l['credit'] > 0 ? '#059669' : '#94a3b8' ?>; font-weight:600;">
                                    <?= (float)$l['credit'] > 0 ? rpt_currency((float)$l['credit']) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:13px">
                    <td colspan="6" style="padding:10px 14px">TOTAL JOURNAL DEBITS & CREDITS</td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_debit) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_credit) ?></td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Individual Header Click Toggle
    document.querySelectorAll('.jr-header-row').forEach(function(row) {
        row.addEventListener('click', function() {
            var hid = this.getAttribute('data-header-id');
            var details = document.querySelectorAll('.detail-' + hid);
            var icon = document.getElementById('icon-' + hid);
            
            var isHidden = false;
            details.forEach(function(d) {
                if (d.style.display === 'none') {
                    d.style.display = '';
                } else {
                    d.style.display = 'none';
                    isHidden = true;
                }
            });

            if (icon) {
                icon.style.transform = isHidden ? 'rotate(-90deg)' : 'rotate(0deg)';
            }
        });
    });

    // Expand All Button
    var btnExpand = document.getElementById('btn-expand-all');
    if (btnExpand) {
        btnExpand.addEventListener('click', function() {
            document.querySelectorAll('.jr-detail-row').forEach(function(d) {
                d.style.display = '';
            });
            document.querySelectorAll('.jr-icon').forEach(function(icon) {
                icon.style.transform = 'rotate(0deg)';
            });
        });
    }

    // Collapse All Button
    var btnCollapse = document.getElementById('btn-collapse-all');
    if (btnCollapse) {
        btnCollapse.addEventListener('click', function() {
            document.querySelectorAll('.jr-detail-row').forEach(function(d) {
                d.style.display = 'none';
            });
            document.querySelectorAll('.jr-icon').forEach(function(icon) {
                icon.style.transform = 'rotate(-90deg)';
            });
        });
    }
});
</script>
