<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$date_from = '2026-07-17';
$date_to   = '2026-08-19';

$loc_id = '1';
$loc_sql = rpt_location_sql('th');

$cogs_invoices = (float) ($db->fetchOne("
    SELECT SUM(l.quantity * COALESCE(NULLIF(l.cost_price, 0), i.cost_price, 0)) as cogs
    FROM transaction_lines l
    JOIN transaction_headers th ON l.header_id = th.id
    JOIN customer_invoices ci ON ci.header_id = th.id
    JOIN items i ON l.item_id = i.id
    WHERE th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
      AND COALESCE(th.source,'') != 'pos_sync'
      AND ci.invoice_number NOT LIKE 'INV-POS-%'
      AND ci.invoice_number NOT LIKE 'POS-%' {$loc_sql}
", [$date_from, $date_to])['cogs'] ?? 0);

echo "Customer Invoices COGS: Rs " . number_format($cogs_invoices, 2) . "\n";
