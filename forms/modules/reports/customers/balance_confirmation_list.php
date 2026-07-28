<?php
/**
 * AntiGravity ERP - Balance Confirmation Report
 * Supports Customers (AR) & Suppliers (AP)
 */
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
require_once 'forms/modules/reports/rpt_helpers.php';

$db = db();

// Filter Inputs
$confirmation_type       = $_GET['confirmation_type'] ?? ($_GET['type'] ?? 'customer'); // 'customer' or 'supplier'
if ($confirmation_type === 'vendor') $confirmation_type = 'supplier';

$party_id                = $_GET['party_id'] ?? ($_GET['customer_id'] ?? ($_GET['vendor_id'] ?? ''));
$as_on_date              = $_GET['as_on_date'] ?? ($_GET['to_date'] ?? date('Y-m-d'));
$fiscal_year_id          = $_GET['fiscal_year_id'] ?? '';
$branch_id               = $_GET['branch_id'] ?? 'all';
$currency                = $_GET['currency'] ?? 'NPR';
$include_invoice_details = $_GET['include_invoice_details'] ?? 'yes';
$include_aging           = $_GET['include_aging'] ?? 'yes';
$is_bulk                 = ($_GET['bulk'] ?? '0') === '1';

// Currency formatting symbol helper
$currency_symbols = [
    'NPR' => 'Rs ',
    'USD' => '$ ',
    'EUR' => '€ ',
    'INR' => '₹ '
];
$curr_sym = $currency_symbols[$currency] ?? 'Rs ';

// Fetch Fiscal Years
$fiscal_years = $db->fetchAll("SELECT id, name, start_date, end_date, status FROM fiscal_years ORDER BY start_date DESC");

// Resolve active fiscal year and date range
$selected_fy = null;
if ($fiscal_year_id) {
    foreach ($fiscal_years as $fy) {
        if ($fy['id'] === $fiscal_year_id) {
            $selected_fy = $fy;
            break;
        }
    }
}
if (!$selected_fy) {
    // Find fiscal year matching $as_on_date or active
    foreach ($fiscal_years as $fy) {
        if ($as_on_date >= $fy['start_date'] && $as_on_date <= $fy['end_date']) {
            $selected_fy = $fy;
            $fiscal_year_id = $fy['id'];
            break;
        }
    }
}
if (!$selected_fy && !empty($fiscal_years)) {
    $selected_fy = $fiscal_years[0];
    $fiscal_year_id = $selected_fy['id'];
}

$from_date = $selected_fy['start_date'] ?? date('Y-07-16');

// Fetch System Info
$sys_info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$sys = [];
foreach ($sys_info as $row) {
    $sys[$row['meta_field']] = $row['meta_value'];
}

$company_name    = $sys['name'] ?? ($sys['company_name'] ?? 'MNS ERP (P) LTD.');
$company_address = $sys['address'] ?? ($sys['company_address'] ?? 'Kathmandu, Nepal');
$company_phone   = $sys['contact'] ?? ($sys['company_phone'] ?? '');
$company_email   = $sys['email'] ?? ($sys['company_email'] ?? '');
$company_pan     = $sys['pan_no'] ?? ($sys['company_pan'] ?? ($sys['pan_vat_number'] ?? ''));
$company_logo    = $sys['logo'] ?? '';

// Fetch Parties Dropdown
$parties = [];
if ($confirmation_type === 'customer') {
    $parties = $db->fetchAll("SELECT id, customer_code as code, full_name as name, '' as address, phone, email, pan_number as pan FROM customers WHERE is_deleted = 0 ORDER BY full_name ASC");
} else {
    $parties = $db->fetchAll("SELECT id, vendor_code as code, company_name as name, address, phone, email, pan_number as pan FROM vendors WHERE is_deleted = 0 ORDER BY company_name ASC");
}

if (!$party_id && !empty($parties) && !$is_bulk) {
    $party_id = $parties[0]['id'];
}

// Branches list (Head office / main branch default)
$branches = [
    'all' => 'All Branches',
    'BRCH-001' => 'Head Office / Main Branch'
];

// Calculation Function for a Party
function calculate_party_balance_confirmation($db, $confirmation_type, $party_id, $from_date, $as_on_date, $branch_id = 'all') {
    $opening_balance = 0.0;
    $sales_purchases = 0.0;
    $debit_notes     = 0.0;
    $credit_notes    = 0.0;
    $payments        = 0.0;
    $journal_adj     = 0.0;
    $history         = [];
    $aging_bands     = [
        'current' => 0.0,
        '1_30'    => 0.0,
        '31_60'   => 0.0,
        '61_90'   => 0.0,
        '91_180'  => 0.0,
        'over_180'=> 0.0
    ];

    if ($confirmation_type === 'customer') {
        // Customer (AR) Accounting Logic
        // 1. Prior Invoices before $from_date
        $prior_inv = (float)($db->fetchOne("
            SELECT COALESCE(SUM(ci.total_amount), 0) as total
            FROM customer_invoices ci
            JOIN transaction_headers h ON ci.header_id = h.id
            WHERE ci.customer_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date < ?
        ", [$party_id, $from_date])['total'] ?? 0);

        // 2. Prior Payments before $from_date
        $prior_pay = (float)($db->fetchOne("
            SELECT COALESCE(SUM(p.amount), 0) as total
            FROM payments p
            JOIN transaction_headers h ON p.header_id = h.id
            WHERE p.customer_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND p.payment_date < ?
        ", [$party_id, $from_date])['total'] ?? 0);

        // 3. Prior Tagged Journals before $from_date
        $prior_j_dr = (float)($db->fetchOne("
            SELECT COALESCE(SUM(j.amount), 0) as total
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            WHERE (j.party_id = ? OR h.party_id = ?) AND (j.party_type = 'customer' OR j.party_type IS NULL)
              AND j.entry_type = 'debit' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date < ? AND h.txn_type IN ('Journal', 'journal_entry', 'debit_note')
        ", [$party_id, $party_id, $from_date])['total'] ?? 0);

        $prior_j_cr = (float)($db->fetchOne("
            SELECT COALESCE(SUM(j.amount), 0) as total
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            WHERE (j.party_id = ? OR h.party_id = ?) AND (j.party_type = 'customer' OR j.party_type IS NULL)
              AND j.entry_type = 'credit' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date < ? AND h.txn_type IN ('Journal', 'journal_entry', 'credit_note')
        ", [$party_id, $party_id, $from_date])['total'] ?? 0);

        $opening_balance = ($prior_inv + $prior_j_dr) - ($prior_pay + $prior_j_cr);

        // Period Transactions between $from_date and $as_on_date
        // Invoices
        $inv_rows = $db->fetchAll("
            SELECT h.txn_date as date, ci.due_date, h.txn_number as number, 'Invoice' as txn_type,
                   COALESCE(ci.invoice_number, h.memo) as memo, ci.total_amount as debit, 0 as credit
            FROM customer_invoices ci
            JOIN transaction_headers h ON ci.header_id = h.id
            WHERE ci.customer_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date BETWEEN ? AND ?
        ", [$party_id, $from_date, $as_on_date]);

        foreach ($inv_rows as $r) { $sales_purchases += (float)$r['debit']; }

        // Payments
        $pay_rows = $db->fetchAll("
            SELECT p.payment_date as date, p.payment_date as due_date, h.txn_number as number, 'Payment' as txn_type,
                   CONCAT(p.payment_method, ' ', COALESCE(p.transaction_reference, '')) as memo, 0 as debit, p.amount as credit
            FROM payments p
            JOIN transaction_headers h ON p.header_id = h.id
            WHERE p.customer_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND p.payment_date BETWEEN ? AND ?
        ", [$party_id, $from_date, $as_on_date]);

        foreach ($pay_rows as $r) { $payments += (float)$r['credit']; }

        // Tagged Journals
        $jour_rows = $db->fetchAll("
            SELECT h.txn_date as date, h.txn_date as due_date, h.txn_number as number,
                   CASE 
                     WHEN UPPER(h.memo) LIKE '%DEBIT NOTE%' OR h.txn_type = 'debit_note' THEN 'Debit Note'
                     WHEN UPPER(h.memo) LIKE '%CREDIT NOTE%' OR h.txn_type = 'credit_note' THEN 'Credit Note'
                     ELSE 'Journal Entry' 
                   END as txn_type,
                   j.memo as memo,
                   CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE 0 END as debit,
                   CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE 0 END as credit
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            WHERE (j.party_id = ? OR h.party_id = ?) AND (j.party_type = 'customer' OR j.party_type IS NULL)
              AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') AND h.txn_type IN ('Journal', 'journal_entry', 'debit_note', 'credit_note')
              AND h.txn_date BETWEEN ? AND ?
        ", [$party_id, $party_id, $from_date, $as_on_date]);

        foreach ($jour_rows as $r) {
            $dr = (float)$r['debit'];
            $cr = (float)$r['credit'];
            if ($r['txn_type'] === 'Debit Note') {
                $debit_notes += $dr;
            } elseif ($r['txn_type'] === 'Credit Note') {
                $credit_notes += $cr;
            } else {
                $journal_adj += ($dr - $cr);
            }
        }

        $history = array_merge($inv_rows, $pay_rows, $jour_rows);

        // Calculate Aging
        $open_docs = $db->fetchAll("
            SELECT ci.balance_due, ci.invoice_date as doc_date
            FROM customer_invoices ci
            JOIN transaction_headers h ON ci.header_id = h.id
            WHERE ci.customer_id = ? AND ci.balance_due > 0.01
              AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date <= ?

            UNION ALL

            SELECT (SUM(CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0.00)) as balance_due,
                   h.txn_date as doc_date
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
            WHERE (j.party_id = ? OR h.party_id = ?) AND (j.party_type = 'customer' OR j.party_type IS NULL)
              AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') AND h.txn_type IN ('Journal', 'journal_entry')
              AND h.txn_date <= ?
            GROUP BY h.id, h.txn_date
            HAVING balance_due > 0.01
        ", [$party_id, $as_on_date, $party_id, $party_id, $as_on_date]);

        foreach ($open_docs as $doc) {
            $days = floor((strtotime($as_on_date) - strtotime($doc['doc_date'])) / 86400);
            $bal = (float)$doc['balance_due'];
            if ($days <= 0) $aging_bands['current'] += $bal;
            elseif ($days <= 30) $aging_bands['1_30'] += $bal;
            elseif ($days <= 60) $aging_bands['31_60'] += $bal;
            elseif ($days <= 90) $aging_bands['61_90'] += $bal;
            elseif ($days <= 180) $aging_bands['91_180'] += $bal;
            else $aging_bands['over_180'] += $bal;
        }

    } else {
        // Supplier (AP) Accounting Logic
        // 1. Prior Bills before $from_date
        $prior_bills = (float)($db->fetchOne("
            SELECT COALESCE(SUM(vb.total_amount), 0) as total
            FROM vendor_bills vb
            JOIN transaction_headers h ON vb.header_id = h.id
            WHERE vb.vendor_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date < ?
        ", [$party_id, $from_date])['total'] ?? 0);

        // 2. Prior Payments before $from_date
        $prior_pay = (float)($db->fetchOne("
            SELECT COALESCE(SUM(p.amount), 0) as total
            FROM payments p
            JOIN transaction_headers h ON p.header_id = h.id
            WHERE p.vendor_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND p.payment_date < ?
        ", [$party_id, $from_date])['total'] ?? 0);

        // 3. Prior Tagged Journals before $from_date
        $prior_j_cr = (float)($db->fetchOne("
            SELECT COALESCE(SUM(j.amount), 0) as total
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            WHERE (j.party_id = ? OR h.party_id = ?) AND (j.party_type = 'vendor' OR j.party_type IS NULL)
              AND j.entry_type = 'credit' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date < ?
        ", [$party_id, $party_id, $from_date])['total'] ?? 0);

        $prior_j_dr = (float)($db->fetchOne("
            SELECT COALESCE(SUM(j.amount), 0) as total
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            WHERE (j.party_id = ? OR h.party_id = ?) AND (j.party_type = 'vendor' OR j.party_type IS NULL)
              AND j.entry_type = 'debit' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date < ?
        ", [$party_id, $party_id, $from_date])['total'] ?? 0);

        $opening_balance = ($prior_bills + $prior_j_cr) - ($prior_pay + $prior_j_dr);

        // Period Transactions
        // Vendor Bills
        $bill_rows = $db->fetchAll("
            SELECT h.txn_date as date, vb.due_date, h.txn_number as number, 'Vendor Bill' as txn_type,
                   COALESCE(vb.vendor_invoice_number, h.memo) as memo, 0 as debit, vb.total_amount as credit
            FROM vendor_bills vb
            JOIN transaction_headers h ON vb.header_id = h.id
            WHERE vb.vendor_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date BETWEEN ? AND ?
        ", [$party_id, $from_date, $as_on_date]);

        foreach ($bill_rows as $r) { $sales_purchases += (float)$r['credit']; }

        // Vendor Payments
        $pay_rows = $db->fetchAll("
            SELECT p.payment_date as date, p.payment_date as due_date, h.txn_number as number, 'Vendor Payment' as txn_type,
                   CONCAT(p.payment_method, ' ', COALESCE(p.transaction_reference, '')) as memo, p.amount as debit, 0 as credit
            FROM payments p
            JOIN transaction_headers h ON p.header_id = h.id
            WHERE p.vendor_id = ? AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND p.payment_date BETWEEN ? AND ?
        ", [$party_id, $from_date, $as_on_date]);

        foreach ($pay_rows as $r) { $payments += (float)$r['debit']; }

        // Vendor Tagged Journals
        $jour_rows = $db->fetchAll("
            SELECT h.txn_date as date, h.txn_date as due_date, h.txn_number as number,
                   CASE 
                     WHEN UPPER(h.memo) LIKE '%CREDIT NOTE%' OR h.txn_type = 'credit_note' THEN 'Credit Note'
                     WHEN UPPER(h.memo) LIKE '%DEBIT NOTE%' OR h.txn_type = 'debit_note' THEN 'Debit Note'
                     ELSE 'Journal Entry' 
                   END as txn_type,
                   j.memo as memo,
                   CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE 0 END as debit,
                   CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE 0 END as credit
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            WHERE (j.party_id = ? OR h.party_id = ?) AND (j.party_type = 'vendor' OR j.party_type IS NULL)
              AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') AND h.txn_type IN ('Journal', 'journal_entry', 'debit_note', 'credit_note')
              AND h.txn_date BETWEEN ? AND ?
        ", [$party_id, $party_id, $from_date, $as_on_date]);

        foreach ($jour_rows as $r) {
            $dr = (float)$r['debit'];
            $cr = (float)$r['credit'];
            if ($r['txn_type'] === 'Credit Note') {
                $credit_notes += $cr;
            } elseif ($r['txn_type'] === 'Debit Note') {
                $debit_notes += $dr;
            } else {
                $journal_adj += ($cr - $dr);
            }
        }

        $history = array_merge($bill_rows, $pay_rows, $jour_rows);

        // Supplier Aging
        $open_docs = $db->fetchAll("
            SELECT vb.balance_due, vb.bill_date as doc_date
            FROM vendor_bills vb
            JOIN transaction_headers h ON vb.header_id = h.id
            WHERE vb.vendor_id = ? AND vb.balance_due > 0.01
              AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
              AND h.txn_date <= ?

            UNION ALL

            SELECT (SUM(CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END) - COALESCE(SUM(CAST(SUBSTRING_INDEX(tl.link_type, ':', -1) AS DECIMAL(10,2))), 0.00)) as balance_due,
                   h.txn_date as doc_date
            FROM journal_entries j
            JOIN transaction_headers h ON j.header_id = h.id
            LEFT JOIN transaction_links tl ON tl.child_id = h.id AND tl.link_type LIKE 'payment:%'
            WHERE (j.party_id = ? OR h.party_id = ?) AND (j.party_type = 'vendor' OR j.party_type IS NULL)
              AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') AND h.txn_type IN ('Journal', 'journal_entry')
              AND h.txn_date <= ?
            GROUP BY h.id, h.txn_date
            HAVING balance_due > 0.01
        ", [$party_id, $as_on_date, $party_id, $party_id, $as_on_date]);

        foreach ($open_docs as $doc) {
            $days = floor((strtotime($as_on_date) - strtotime($doc['doc_date'])) / 86400);
            $bal = (float)$doc['balance_due'];
            if ($days <= 0) $aging_bands['current'] += $bal;
            elseif ($days <= 30) $aging_bands['1_30'] += $bal;
            elseif ($days <= 60) $aging_bands['31_60'] += $bal;
            elseif ($days <= 90) $aging_bands['61_90'] += $bal;
            elseif ($days <= 180) $aging_bands['91_180'] += $bal;
            else $aging_bands['over_180'] += $bal;
        }
    }

    // Sort history by date
    usort($history, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    // Calculate closing balance
    if ($confirmation_type === 'customer') {
        $closing_balance = $opening_balance + $sales_purchases + $debit_notes - $credit_notes - $payments + $journal_adj;
    } else {
        $closing_balance = $opening_balance + $sales_purchases + $credit_notes - $debit_notes - $payments + $journal_adj;
    }

    return [
        'opening_balance' => $opening_balance,
        'sales_purchases' => $sales_purchases,
        'debit_notes'     => $debit_notes,
        'credit_notes'    => $credit_notes,
        'payments'        => $payments,
        'journal_adj'     => $journal_adj,
        'closing_balance' => $closing_balance,
        'history'         => $history,
        'aging'           => $aging_bands
    ];
}

// Party Info fetcher
$party_info = null;
$calc_res   = null;

if ($party_id && !$is_bulk) {
    if ($confirmation_type === 'customer') {
        $party_info = $db->fetchOne("SELECT id, customer_code as code, full_name as name, '' as address, phone, email, pan_number as pan FROM customers WHERE id = ?", [$party_id]);
    } else {
        $party_info = $db->fetchOne("SELECT id, vendor_code as code, company_name as name, address, phone, email, pan_number as pan FROM vendors WHERE id = ?", [$party_id]);
    }
    if ($party_info) {
        $calc_res = calculate_party_balance_confirmation($db, $confirmation_type, $party_id, $from_date, $as_on_date, $branch_id);
    }
}
?>

<!-- STYLING -->
<style>
    @media print {
        .no-print { display: none !important; }
        body { background: #fff !important; margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif !important; }
        .confirmation-card { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100% !important; page-break-after: always; }
        .confirmation-card:last-child { page-break-after: avoid; }
    }
    .confirmation-card {
        background: #fff;
        padding: 35px 40px;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        box-shadow: 0 4px 18px rgba(0,0,0,0.06);
        max-width: 900px;
        margin: 0 auto 30px auto;
        color: #0f172a;
        font-family: 'Segoe UI', Arial, sans-serif;
    }
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
        gap: 10px;
        margin-bottom: 20px;
    }
    .summary-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 12px;
        text-align: center;
    }
    .summary-box .val {
        font-size: 15px;
        font-weight: 800;
        color: #003087;
        margin-top: 3px;
    }
    .summary-box .lbl {
        font-size: 10px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
    }
    .net-box {
        background: #f0f7ff;
        border: 2px solid #003087;
        border-radius: 8px;
        padding: 16px 20px;
        margin-bottom: 25px;
    }
</style>

<!-- HEADER & ACTIONS BAR -->
<div class="ns-page-header no-print" style="max-width: 900px; margin: 0 auto 20px auto; display: flex; justify-content: space-between; align-items: center;">
    <h1 class="ns-page-title" style="font-size: 20px; margin: 0;">
        <i class="fas fa-file-contract"></i> <?php echo $confirmation_type === 'customer' ? 'Customer' : 'Supplier'; ?> Balance Confirmation Letter (मौज्दात पुष्टि पत्र)
    </h1>
    <div class="ns-page-actions" style="display: flex; gap: 8px;">
        <button class="ns-btn ns-btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Confirmation Letter</button>
        <button class="ns-btn" onclick="exportTableToCSV('tbl-transactions-<?php echo htmlspecialchars($party_id); ?>')"><i class="fas fa-file-csv"></i> Export CSV</button>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['bulk' => $is_bulk ? '0' : '1'])); ?>" class="ns-btn">
            <i class="fas fa-layer-group"></i> <?php echo $is_bulk ? 'Single Mode' : 'Bulk Generation'; ?>
        </a>
        <a href="?page=reports/reports_list" class="ns-btn">Back to Reports</a>
    </div>
</div>

<!-- COMPREHENSIVE FILTER CARD -->
<div class="ns-filter-card no-print" style="background: #fff; padding: 20px 24px; border-radius: 12px; border: 1px solid #cbd5e1; box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-bottom: 25px; max-width: 900px; margin-left: auto; margin-right: auto;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
        <input type="hidden" name="page" value="<?php echo htmlspecialchars($_GET['page'] ?? 'reports/customers/balance_confirmation'); ?>">
        
        <div>
            <label class="ns-label" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Confirmation Type *</label>
            <select name="confirmation_type" class="ns-select" style="width: 100%;" onchange="this.form.submit()">
                <option value="customer" <?php echo $confirmation_type === 'customer' ? 'selected' : ''; ?>>Customer (Accounts Receivable)</option>
                <option value="supplier" <?php echo $confirmation_type === 'supplier' ? 'selected' : ''; ?>>Supplier (Accounts Payable)</option>
            </select>
        </div>

        <?php if (!$is_bulk): ?>
        <div>
            <label class="ns-label" style="font-weight: 700; font-size: 11px; text-transform: uppercase;"><?php echo $confirmation_type === 'customer' ? 'Select Customer *' : 'Select Supplier *'; ?></label>
            <select name="party_id" class="ns-select" required style="width: 100%;">
                <option value="">-- Select Party --</option>
                <?php foreach ($parties as $p): ?>
                    <option value="<?php echo htmlspecialchars($p['id']); ?>" <?php echo $party_id === $p['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['name'] . ($p['code'] ? ' (' . $p['code'] . ')' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="bulk" value="1">
        <?php endif; ?>

        <div>
            <label class="ns-label" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">As On Date *</label>
            <input type="date" name="as_on_date" class="ns-input" value="<?php echo htmlspecialchars($as_on_date); ?>" required style="width: 100%;">
        </div>

        <div>
            <label class="ns-label" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Fiscal Year</label>
            <select name="fiscal_year_id" class="ns-select" style="width: 100%;">
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?php echo htmlspecialchars($fy['id']); ?>" <?php echo $fiscal_year_id === $fy['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($fy['name'] . ' (' . $fy['start_date'] . ' to ' . $fy['end_date'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="ns-label" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Branch</label>
            <select name="branch_id" class="ns-select" style="width: 100%;">
                <?php foreach ($branches as $bv => $bl): ?>
                    <option value="<?php echo htmlspecialchars($bv); ?>" <?php echo $branch_id === $bv ? 'selected' : ''; ?>><?php echo htmlspecialchars($bl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="ns-label" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Currency</label>
            <select name="currency" class="ns-select" style="width: 100%;">
                <option value="NPR" <?php echo $currency === 'NPR' ? 'selected' : ''; ?>>NPR - Nepalese Rupee (Rs)</option>
                <option value="USD" <?php echo $currency === 'USD' ? 'selected' : ''; ?>>USD - US Dollar ($)</option>
                <option value="EUR" <?php echo $currency === 'EUR' ? 'selected' : ''; ?>>EUR - Euro (€)</option>
                <option value="INR" <?php echo $currency === 'INR' ? 'selected' : ''; ?>>INR - Indian Rupee (₹)</option>
            </select>
        </div>

        <div>
            <label class="ns-label" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Include Invoice Details?</label>
            <select name="include_invoice_details" class="ns-select" style="width: 100%;">
                <option value="yes" <?php echo $include_invoice_details === 'yes' ? 'selected' : ''; ?>>Yes (Show Outstanding Transactions)</option>
                <option value="no" <?php echo $include_invoice_details === 'no' ? 'selected' : ''; ?>>No (Summary Only)</option>
            </select>
        </div>

        <div>
            <label class="ns-label" style="font-weight: 700; font-size: 11px; text-transform: uppercase;">Include Aging?</label>
            <select name="include_aging" class="ns-select" style="width: 100%;">
                <option value="yes" <?php echo $include_aging === 'yes' ? 'selected' : ''; ?>>Yes (6-Band Aging Summary)</option>
                <option value="no" <?php echo $include_aging === 'no' ? 'selected' : ''; ?>>No (Exclude Aging)</option>
            </select>
        </div>

        <div style="grid-column: 1 / -1; text-align: right; margin-top: 5px;">
            <button type="submit" class="ns-btn ns-btn-primary"><i class="fas fa-search"></i> Generate Report</button>
        </div>
    </form>
</div>

<!-- REPORT CONTENT RENDERING -->
<?php
// Function to render single statement card
function render_single_balance_confirmation($sys, $confirmation_type, $party_info, $calc_res, $from_date, $as_on_date, $selected_fy, $curr_sym, $include_invoice_details, $include_aging) {
    $opening_balance = $calc_res['opening_balance'];
    $sales_purchases = $calc_res['sales_purchases'];
    $debit_notes     = $calc_res['debit_notes'];
    $credit_notes    = $calc_res['credit_notes'];
    $payments        = $calc_res['payments'];
    $journal_adj     = $calc_res['journal_adj'];
    $closing_balance = $calc_res['closing_balance'];
    $history         = $calc_res['history'];
    $aging           = $calc_res['aging'];

    $company_name    = $sys['name'] ?? ($sys['company_name'] ?? 'MNS ERP (P) LTD.');
    $company_address = $sys['address'] ?? ($sys['company_address'] ?? 'Kathmandu, Nepal');
    $company_phone   = $sys['contact'] ?? ($sys['company_phone'] ?? '');
    $company_email   = $sys['email'] ?? ($sys['company_email'] ?? '');
    $company_pan     = $sys['pan_no'] ?? ($sys['company_pan'] ?? ($sys['pan_vat_number'] ?? ''));
    $company_logo    = $sys['logo'] ?? '';

    // Confirmation Reference Number e.g., BC/FY82-83/C-00002-20260724
    $conf_no = "BC/" . str_replace(' ', '', $selected_fy['name'] ?? '2026-27') . "/" . ($party_info['code'] ?? 'REF') . "-" . date('Ymd', strtotime($as_on_date));
    ?>
    <div class="confirmation-card">
        
        <!-- LETTERHEAD HEADER -->
        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #0f172a; padding-bottom: 15px; margin-bottom: 20px;">
            <div style="flex: 0 0 140px;">
                <?php if (!empty($company_logo) && file_exists($company_logo)): ?>
                    <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Logo" style="max-height: 75px; max-width: 130px; object-fit: contain;">
                <?php else: ?>
                    <div style="font-size: 26px; font-weight: 900; color: #003087; line-height: 1;"><?php echo htmlspecialchars($sys['short_name'] ?? 'MNS ERP'); ?></div>
                <?php endif; ?>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 22px; font-weight: 800; color: #003087; text-transform: uppercase; margin-bottom: 2px;"><?php echo htmlspecialchars($company_name); ?></div>
                <div style="font-size: 12px; color: #475569; line-height: 1.3;">
                    <?php echo nl2br(htmlspecialchars($company_address)); ?><br>
                    Phone: <?php echo htmlspecialchars($company_phone); ?>
                    <?php if (!empty($company_email)): ?> | Email: <?php echo htmlspecialchars($company_email); ?><?php endif; ?><br>
                    <strong style="color: #0f172a;">PAN / VAT No: <?php echo htmlspecialchars($company_pan); ?></strong>
                </div>
            </div>
        </div>

        <!-- DOCUMENT TITLE -->
        <div style="text-align: center; font-size: 16px; font-weight: 800; background: #f1f5f9; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1; margin-bottom: 22px; text-transform: uppercase; letter-spacing: 0.5px;">
            <?php echo $confirmation_type === 'customer' ? 'CUSTOMER BALANCE CONFIRMATION LETTER' : 'SUPPLIER BALANCE CONFIRMATION LETTER'; ?><br>
            <span style="font-size: 13px; font-weight: 600; color: #475569;">
                (<?php echo $confirmation_type === 'customer' ? 'ग्राहक मौज्दात पुष्टि पत्र' : 'विक्रेता मौज्दात पुष्टि पत्र'; ?>)
            </span>
        </div>

        <!-- RECIPIENT & STATEMENT REF DETAILS -->
        <div style="display: flex; justify-content: space-between; margin-bottom: 22px; font-size: 13px; gap: 20px;">
            <div style="flex: 1; border: 1px solid #cbd5e1; padding: 12px 16px; border-radius: 8px; background: #f8fafc;">
                <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; margin-bottom: 4px;">
                    To (<?php echo $confirmation_type === 'customer' ? 'Customer Details' : 'Supplier Details'; ?>):
                </div>
                <div style="font-size: 15px; font-weight: 800; color: #003087;"><?php echo htmlspecialchars($party_info['name']); ?></div>
                <div>Address: <?php echo htmlspecialchars($party_info['address'] ?: 'N/A'); ?></div>
                <div>Phone: <?php echo htmlspecialchars($party_info['phone'] ?: 'N/A'); ?></div>
                <div>Email: <?php echo htmlspecialchars($party_info['email'] ?: 'N/A'); ?></div>
                <div>PAN / VAT No: <strong><?php echo htmlspecialchars($party_info['pan'] ?: 'N/A'); ?></strong></div>
            </div>
            <div style="width: 290px; border: 1px solid #cbd5e1; padding: 12px 16px; border-radius: 8px; background: #f8fafc;">
                <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; margin-bottom: 4px;">Confirmation Metadata:</div>
                <div>Confirmation No: <strong style="color: #003087;"><?php echo htmlspecialchars($conf_no); ?></strong></div>
                <div>Confirmation Date: <strong><?php echo date('Y-m-d'); ?></strong></div>
                <div>As On Date: <strong><?php echo htmlspecialchars($as_on_date); ?></strong></div>
                <div>Fiscal Year: <strong><?php echo htmlspecialchars($selected_fy['name'] ?? 'Current'); ?></strong></div>
                <div>Party Code: <strong><?php echo htmlspecialchars($party_info['code'] ?: 'N/A'); ?></strong></div>
            </div>
        </div>

        <!-- CONFIRMATION STATEMENT BODY -->
        <div style="font-size: 13px; line-height: 1.6; margin-bottom: 20px; text-align: justify;">
            <p style="margin-bottom: 10px;"><strong>Dear Sir / Madam,</strong></p>
            <p style="margin-bottom: 10px;">
                In connection with the audit and verification of our accounts, please confirm directly to us that the net outstanding balance due <?php echo $confirmation_type === 'customer' ? 'from your company to us' : 'to your company from us'; ?> for the period from <strong><?php echo htmlspecialchars($from_date); ?></strong> to <strong><?php echo htmlspecialchars($as_on_date); ?></strong> as recorded in our books of account is as stated below:
            </p>
        </div>

        <!-- NET OUTSTANDING SUMMARY BOX -->
        <div class="net-box">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 700; font-size: 15px; color: #003087;">
                    Net Outstanding <?php echo $confirmation_type === 'customer' ? 'Receivable' : 'Payable'; ?> Balance (as of <?php echo htmlspecialchars($as_on_date); ?>):
                </span>
                <span style="font-weight: 800; font-size: 22px; color: #003087;">
                    <?php echo $curr_sym . number_format($closing_balance, 2); ?>
                </span>
            </div>
            <div style="margin-top: 8px; font-weight: 600; font-size: 12px; color: #334155; border-top: 1px dashed #93c5fd; padding-top: 6px;">
                Amount in Words: <strong><?php echo amount_in_words($closing_balance); ?></strong>
            </div>
        </div>

        <!-- PERIOD BALANCE SUMMARY BREAKDOWN -->
        <div style="margin-bottom: 25px;">
            <div style="font-weight: 700; font-size: 13px; margin-bottom: 10px; color: #334155; text-transform: uppercase;">
                Balance Summary Breakdown (<?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($as_on_date); ?>):
            </div>
            <div class="summary-grid">
                <div class="summary-box">
                    <div class="lbl">Opening Bal</div>
                    <div class="val"><?php echo number_format($opening_balance, 2); ?></div>
                </div>
                <div class="summary-box">
                    <div class="lbl"><?php echo $confirmation_type === 'customer' ? 'Sales' : 'Purchases'; ?></div>
                    <div class="val" style="color: #2563eb;"><?php echo number_format($sales_purchases, 2); ?></div>
                </div>
                <div class="summary-box">
                    <div class="lbl">Debit Notes</div>
                    <div class="val"><?php echo number_format($debit_notes, 2); ?></div>
                </div>
                <div class="summary-box">
                    <div class="lbl">Credit Notes</div>
                    <div class="val"><?php echo number_format($credit_notes, 2); ?></div>
                </div>
                <div class="summary-box">
                    <div class="lbl">Payments</div>
                    <div class="val" style="color: #16a34a;"><?php echo number_format($payments, 2); ?></div>
                </div>
                <div class="summary-box">
                    <div class="lbl">Journal Adj</div>
                    <div class="val"><?php echo number_format($journal_adj, 2); ?></div>
                </div>
                <div class="summary-box" style="background: #003087; color: #fff;">
                    <div class="lbl" style="color: #cbd5e1;">Closing Bal</div>
                    <div class="val" style="color: #fff;"><?php echo number_format($closing_balance, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- OUTSTANDING TRANSACTIONS BREAKDOWN -->
        <?php if ($include_invoice_details === 'yes'): ?>
        <div style="margin-bottom: 25px;">
            <div style="font-weight: 700; font-size: 13px; margin-bottom: 8px; color: #334155; text-transform: uppercase;">
                Outstanding Transactions & Period History:
            </div>
            <table class="ns-report-table-static" id="tbl-transactions-<?php echo htmlspecialchars($party_info['id']); ?>" style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead>
                    <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1;">
                        <th style="padding: 8px 10px; text-align: left;">Date</th>
                        <th style="padding: 8px 10px; text-align: left;">Txn Type</th>
                        <th style="padding: 8px 10px; text-align: left;">Doc / Ref No.</th>
                        <th style="padding: 8px 10px; text-align: left;">Due Date</th>
                        <th style="padding: 8px 10px; text-align: right;">Debit (<?php echo trim($curr_sym); ?>)</th>
                        <th style="padding: 8px 10px; text-align: right;">Credit (<?php echo trim($curr_sym); ?>)</th>
                        <th style="padding: 8px 10px; text-align: right;">Outstanding (<?php echo trim($curr_sym); ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background: #f8fafc; font-weight: bold; border-bottom: 1px solid #cbd5e1;">
                        <td style="padding: 7px 10px;" colspan="4">Opening Balance (as of <?php echo htmlspecialchars($from_date); ?>)</td>
                        <td style="padding: 7px 10px; text-align: right;">-</td>
                        <td style="padding: 7px 10px; text-align: right;">-</td>
                        <td style="padding: 7px 10px; text-align: right; color: #003087;"><?php echo number_format($opening_balance, 2); ?></td>
                    </tr>
                    <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 15px; color: #64748b;">No transactions recorded during this date range.</td>
                    </tr>
                    <?php else: ?>
                    <?php
                    $run_bal = $opening_balance;
                    foreach ($history as $h):
                        $dr = (float)$h['debit'];
                        $cr = (float)$h['credit'];
                        if ($confirmation_type === 'customer') {
                            $run_bal += ($dr - $cr);
                        } else {
                            $run_bal += ($cr - $dr);
                        }
                    ?>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 6px 10px;"><?php echo htmlspecialchars($h['date']); ?></td>
                        <td style="padding: 6px 10px; font-weight: 600;"><?php echo htmlspecialchars($h['txn_type']); ?></td>
                        <td style="padding: 6px 10px; font-family: monospace; font-weight: 600;"><?php echo htmlspecialchars($h['number']); ?></td>
                        <td style="padding: 6px 10px; color: #64748b;"><?php echo htmlspecialchars($h['due_date'] ?: $h['date']); ?></td>
                        <td style="padding: 6px 10px; text-align: right;"><?php echo $dr > 0 ? number_format($dr, 2) : '-'; ?></td>
                        <td style="padding: 6px 10px; text-align: right;"><?php echo $cr > 0 ? number_format($cr, 2) : '-'; ?></td>
                        <td style="padding: 6px 10px; text-align: right; font-weight: 600; color: #003087;"><?php echo number_format($run_bal, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f1f5f9; font-weight: bold; border-top: 2px solid #cbd5e1;">
                        <td style="padding: 8px 10px;" colspan="4">Ending Balance (as of <?php echo htmlspecialchars($as_on_date); ?>):</td>
                        <td style="padding: 8px 10px; text-align: right; color: #2563eb;"><?php echo number_format(array_sum(array_column($history, 'debit')), 2); ?></td>
                        <td style="padding: 8px 10px; text-align: right; color: #dc2626;"><?php echo number_format(array_sum(array_column($history, 'credit')), 2); ?></td>
                        <td style="padding: 8px 10px; text-align: right; color: #003087; font-size: 14px;"><?php echo number_format($closing_balance, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <!-- 6-BAND AGING BREAKDOWN -->
        <?php if ($include_aging === 'yes'): ?>
        <div style="margin-bottom: 25px;">
            <div style="font-weight: 700; font-size: 13px; margin-bottom: 8px; color: #334155; text-transform: uppercase;">
                Outstanding Aging Summary (as of <?php echo htmlspecialchars($as_on_date); ?>):
            </div>
            <table class="ns-report-table-static" style="width: 100%; border-collapse: collapse; font-size: 11px; text-align: center;">
                <thead>
                    <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1;">
                        <th style="padding: 6px 4px; text-align: center;">Current (0 Days)</th>
                        <th style="padding: 6px 4px; text-align: center;">1 - 30 Days</th>
                        <th style="padding: 6px 4px; text-align: center;">31 - 60 Days</th>
                        <th style="padding: 6px 4px; text-align: center;">61 - 90 Days</th>
                        <th style="padding: 6px 4px; text-align: center;">91 - 180 Days</th>
                        <th style="padding: 6px 4px; text-align: center; color: #dc2626;">180+ Days</th>
                        <th style="padding: 6px 4px; text-align: center; background: #003087; color: #fff;">Total Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding: 8px 4px; color: #16a34a; font-weight: 600;"><?php echo number_format($aging['current'], 2); ?></td>
                        <td style="padding: 8px 4px;"><?php echo number_format($aging['1_30'], 2); ?></td>
                        <td style="padding: 8px 4px;"><?php echo number_format($aging['31_60'], 2); ?></td>
                        <td style="padding: 8px 4px; color: #d97706;"><?php echo number_format($aging['61_90'], 2); ?></td>
                        <td style="padding: 8px 4px; color: #ea580c;"><?php echo number_format($aging['91_180'], 2); ?></td>
                        <td style="padding: 8px 4px; color: #dc2626; font-weight: 700;"><?php echo number_format($aging['over_180'], 2); ?></td>
                        <td style="padding: 8px 4px; font-weight: 800; background: #f8fafc; color: #003087; font-size: 12px;"><?php echo number_format(array_sum($aging), 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- AUDIT CONFIRMATION STATEMENT & SIGN-OFF FORM -->
        <div style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 18px 20px; background: #fafafa; margin-top: 25px;">
            <div style="font-weight: 800; font-size: 13px; text-transform: uppercase; margin-bottom: 8px; color: #0f172a; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px;">
                Confirmation Statement & Sign-Off (पुष्टि तथा ढड्डा विवरण)
            </div>
            <p style="font-size: 12px; margin-bottom: 15px; line-height: 1.5; color: #334155;">
                "According to our books, the above balance is outstanding as of the selected date. Please verify and confirm."
            </p>

            <div style="font-size: 13px; margin-bottom: 25px; line-height: 2;">
                <div>☐ <strong>CONFIRMED CORRECT</strong>: The ending balance of <strong><?php echo $curr_sym . number_format($closing_balance, 2); ?></strong> as of <?php echo htmlspecialchars($as_on_date); ?> is correct and matches our books.</div>
                <div>☐ <strong>DIFFERENCE FOUND</strong>: The balance does not match. Our records show an amount of <?php echo trim($curr_sym); ?> ____________________.</div>
                <div style="margin-top: 5px;">Remarks: __________________________________________________________________________________________</div>
            </div>

            <!-- FOUR-POINT SIGNATURES -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 50px; text-align: center; font-size: 11px; font-weight: 600;">
                <div style="border-top: 1px dashed #0f172a; padding-top: 6px;">
                    Prepared By
                </div>
                <div style="border-top: 1px dashed #0f172a; padding-top: 6px;">
                    Authorized Signatory
                </div>
                <div style="border-top: 1px dashed #0f172a; padding-top: 6px;">
                    <?php echo $confirmation_type === 'customer' ? 'Customer' : 'Supplier'; ?> Signatory
                </div>
                <div style="border-top: 1px dashed #0f172a; padding-top: 6px;">
                    Date & Official Seal
                </div>
            </div>
        </div>

    </div>
    <?php
}

// Single or Bulk Mode Execution
if (!$is_bulk) {
    if ($party_info && $calc_res) {
        render_single_balance_confirmation($sys, $confirmation_type, $party_info, $calc_res, $from_date, $as_on_date, $selected_fy, $curr_sym, $include_invoice_details, $include_aging);
    } else {
        echo '<div style="padding: 80px 40px; text-align: center; color: #64748b; background: #fff; border-radius: 12px; border: 1px solid #cbd5e1; max-width: 900px; margin: 20px auto;">
            <i class="fas fa-file-contract" style="font-size: 54px; margin-bottom: 15px; opacity: 0.3;"></i>
            <h3 style="margin: 0; font-size: 18px; color: #0f172a;">Balance Confirmation Report</h3>
            <p style="margin-top: 8px;">Please select a ' . ($confirmation_type === 'customer' ? 'Customer' : 'Supplier') . ' and date from the filter bar above to generate the statement.</p>
        </div>';
    }
} else {
    // Bulk Generation Mode
    echo '<div class="no-print" style="max-width: 900px; margin: 0 auto 20px auto; background: #eff6ff; border: 1px solid #93c5fd; padding: 12px 18px; border-radius: 8px; font-weight: 700; color: #1e40af; font-size: 13px;">
        <i class="fas fa-layer-group"></i> BULK GENERATION MODE: Displaying confirmation letters for all active ' . ($confirmation_type === 'customer' ? 'Customers' : 'Suppliers') . ' (' . count($parties) . ' records).
    </div>';

    foreach ($parties as $p_item) {
        $p_info = [
            'id' => $p_item['id'],
            'code' => $p_item['code'],
            'name' => $p_item['name'],
            'address' => $p_item['address'],
            'phone' => $p_item['phone'],
            'email' => $p_item['email'],
            'pan' => $p_item['pan']
        ];
        $c_res = calculate_party_balance_confirmation($db, $confirmation_type, $p_item['id'], $from_date, $as_on_date, $branch_id);
        render_single_balance_confirmation($sys, $confirmation_type, $p_info, $c_res, $from_date, $as_on_date, $selected_fy, $curr_sym, $include_invoice_details, $include_aging);
    }
}
?>

<script>
function exportTableToCSV(id) {
    const t = document.getElementById(id);
    if (!t) {
        alert("Transaction breakdown table is disabled or not found.");
        return;
    }
    let csv = [];
    t.querySelectorAll('tr').forEach(r => {
        let row = [];
        r.querySelectorAll('th,td').forEach(c => row.push('"' + c.innerText.replace(/"/g, '""') + '"'));
        csv.push(row.join(','));
    });
    const b = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(b);
    a.download = 'balance_confirmation_<?php echo $confirmation_type; ?>_<?php echo date('Ymd'); ?>.csv';
    a.click();
}
</script>
