<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

echo "=== TESTING DAILY PROFIT & LOSS REPORT LOCATION FILTERING ===\n\n";

$_GET['date_from'] = '2026-07-31';
$_GET['date_to']   = '2026-07-31';

$locations = ['all', 'loc-main-retail', 'loc-main-wh'];

foreach ($locations as $loc_id) {
    $_GET['location_id'] = $loc_id;
    $loc_sql    = rpt_location_sql('h');
    $loc_sql_th = rpt_location_sql('th');
    $loc_sql_pe = rpt_location_sql('pe');

    echo "--- LOCATION: $loc_id ---\n";

    $pos_sales_rows = $db->fetchAll("
        SELECT
            DATE(pe.date_time) as txn_date,
            SUM(pi.net_amount - pi.tax) as total_sales
        FROM pos_items pi
        JOIN items i ON pi.item_id = i.id AND i.is_deleted = 0
        JOIN pos_entry pe ON pi.pos_id = pe.id
        WHERE pe.is_deleted = 0 {$loc_sql_pe}
          AND DATE(pe.date_time) BETWEEN '2026-07-31' AND '2026-07-31'
        GROUP BY DATE(pe.date_time)
    ");

    $exp_rows = $db->fetchAll("
        SELECT h.txn_date, SUM(e.amount) as total_expenses
        FROM expenses e
        JOIN transaction_headers h ON e.header_id = h.id
        WHERE h.txn_type = 'expense' AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft')
          AND h.txn_date BETWEEN '2026-07-31' AND '2026-07-31' {$loc_sql}
        GROUP BY h.txn_date
    ");

    $inv_breakdown = $db->fetchAll("
        SELECT h.txn_number, h.net_amount
        FROM transaction_headers h
        WHERE h.txn_type = 'customer_invoice'
          AND h.txn_date BETWEEN '2026-07-31' AND '2026-07-31'
          AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    ");

    echo "POS Sales Count: " . count($pos_sales_rows) . " | Sales: Rs. " . number_format($pos_sales_rows[0]['total_sales'] ?? 0, 2) . "\n";
    echo "Expenses Count: " . count($exp_rows) . " | Total Expenses: Rs. " . number_format($exp_rows[0]['total_expenses'] ?? 0, 2) . "\n";
    echo "Invoice Breakdown Count: " . count($inv_breakdown) . "\n\n";
}
