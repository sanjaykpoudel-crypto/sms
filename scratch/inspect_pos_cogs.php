<?php
require_once 'database/DBConnection.php';
$db = db();

$date_from = '2026-07-17';
$date_to = '2026-08-19';

// 1. POS Sales Revenue
$pos_sales = (float)($db->fetchOne("
    SELECT SUM(pe.net_amount) as total
    FROM pos_entry pe
    WHERE pe.is_deleted = 0 AND pe.status != 'voided' AND DATE(pe.date_time) BETWEEN ? AND ?
", [$date_from, $date_to])['total'] ?? 0);

// 2. POS-specific COGS (cost of items sold in POS)
$pos_cogs = (float)($db->fetchOne("
    SELECT SUM(pi.quantity * i.cost_price) as cogs
    FROM pos_items pi
    JOIN pos_entry pe ON pi.pos_id = pe.id
    JOIN items i ON pi.item_id = i.id
    WHERE pe.is_deleted = 0 AND pe.status != 'voided' AND DATE(pe.date_time) BETWEEN ? AND ?
", [$date_from, $date_to])['cogs'] ?? 0);

// 3. Customer Invoice Sales & COGS
$inv_sales = (float)($db->fetchOne("
    SELECT SUM(ci.total_amount) as total
    FROM customer_invoices ci JOIN transaction_headers th ON ci.header_id = th.id
    WHERE th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void','voided','draft')
      AND COALESCE(th.source,'') != 'pos_sync' AND ci.invoice_number NOT LIKE 'INV-POS-%'
", [$date_from, $date_to])['total'] ?? 0);

$inv_cogs = (float)($db->fetchOne("
    SELECT SUM(l.quantity * l.cost_price) as cogs
    FROM transaction_lines l JOIN transaction_headers th ON l.header_id = th.id
    WHERE th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void','voided','draft')
      AND th.txn_type IN ('customer_invoice', 'sales_issue') AND COALESCE(th.source,'') != 'pos_sync'
", [$date_from, $date_to])['cogs'] ?? 0);

// 4. Total Company P&L COGS
require_once 'api/ReportingEngine.php';
$pnl = re_get_pnl($db, $date_from, $date_to);
$total_cogs = (float)($pnl['total_cogs'] ?? 0);

echo "=== SALES & COGS CHANNEL BREAKDOWN ===\n";
echo "POS Sales Revenue:          Rs " . number_format($pos_sales, 2) . "\n";
echo "POS Items Cost (POS COGS):  Rs " . number_format($pos_cogs, 2) . "\n";
echo "POS True Gross Profit:      Rs " . number_format($pos_sales - $pos_cogs, 2) . " (" . number_format((($pos_sales - $pos_cogs) / $pos_sales) * 100, 1) . "%)\n\n";

echo "Invoice Sales Revenue:      Rs " . number_format($inv_sales, 2) . "\n";
echo "Invoice Items COGS:         Rs " . number_format($inv_cogs, 2) . "\n";
echo "Invoice True Gross Profit:  Rs " . number_format($inv_sales - $inv_cogs, 2) . "\n\n";

echo "Company Total COGS from P&L: Rs " . number_format($total_cogs, 2) . "\n";
