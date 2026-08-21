<?php
require_once 'database/DBConnection.php';
$db = db();

$date_from = '2026-07-17';
$date_to   = '2026-08-19';
$loc_id    = '1';

// 1. Fetch all customer invoices
$invoices = $db->fetchAll("
    SELECT ci.id as invoice_id, ci.invoice_number, th.id as header_id, th.txn_date, th.net_amount, ci.total_amount, th.source, th.status
    FROM customer_invoices ci
    JOIN transaction_headers th ON ci.header_id = th.id
    WHERE th.txn_date BETWEEN ? AND ? 
      AND th.is_deleted = 0 
      AND th.status NOT IN ('void', 'voided', 'draft')
      AND COALESCE(th.source, '') != 'pos_sync'
      AND ci.invoice_number NOT LIKE 'INV-POS-%'
      AND ci.invoice_number NOT LIKE 'POS-%'
      AND (th.location_id = ? OR th.location_id IS NULL OR th.location_id = 0)
", [$date_from, $date_to, $loc_id]);

echo "=== 1. CUSTOMER INVOICES (Count: " . count($invoices) . ") ===\n";
foreach ($invoices as $inv) {
    echo sprintf("Inv #: %-20s | Header ID: %-6d | Date: %s | Amount: %10.2f | Status: %s | Source: %s\n",
        $inv['invoice_number'], $inv['header_id'], $inv['txn_date'], $inv['total_amount'], $inv['status'], $inv['source']
    );
}

// 2. Fetch all transaction lines for these invoices
$header_ids = array_column($invoices, 'header_id');
if (!empty($header_ids)) {
    $placeholders = implode(',', array_fill(0, count($header_ids), '?'));
    $lines = $db->fetchAll("
        SELECT l.id, l.header_id, l.item_id, i.item_name, l.quantity, l.unit_price, l.cost_price as line_cost_price,
               i.cost_price as item_cost_price, l.line_total,
               (l.quantity * COALESCE(NULLIF(l.cost_price, 0), i.cost_price, 0)) as line_cogs
        FROM transaction_lines l
        JOIN items i ON l.item_id = i.id
        WHERE l.header_id IN ({$placeholders})
    ", $header_ids);

    echo "\n=== 2. TRANSACTION LINES FOR INVOICES (Count: " . count($lines) . ") ===\n";
    $tot_cogs_line = 0;
    $tot_cogs_item = 0;
    foreach ($lines as $l) {
        $cogs_l = (float)$l['quantity'] * (float)($l['line_cost_price'] ?: $l['item_cost_price']);
        $cogs_i = (float)$l['quantity'] * (float)$l['item_cost_price'];
        $tot_cogs_line += $cogs_l;
        $tot_cogs_item += $cogs_i;

        echo sprintf("Header ID: %-6d | Item: %-25s | Qty: %6.2f | Sell Price: %8.2f | Line Cost: %8.2f | Item Cost: %8.2f | Line Total: %10.2f | Calculated COGS: %10.2f\n",
            $l['header_id'], $l['item_name'], $l['quantity'], $l['unit_price'], $l['line_cost_price'], $l['item_cost_price'], $l['line_total'], $cogs_l
        );
    }

    echo "\nTotal Invoice Lines COGS (Line Cost): Rs " . number_format($tot_cogs_line, 2) . "\n";
    echo "Total Invoice Lines COGS (Item Master Cost): Rs " . number_format($tot_cogs_item, 2) . "\n";
}

// 3. Check what query produced Rs 132,576.56 in sales_summary_list.php
$cogs_q3 = (float)($db->fetchOne("
    SELECT SUM(l.quantity * COALESCE(NULLIF(l.cost_price, 0), i.cost_price, 0)) as cogs
    FROM transaction_lines l
    JOIN transaction_headers th ON l.header_id = th.id
    JOIN items i ON l.item_id = i.id
    WHERE th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
      AND th.txn_type IN ('customer_invoice', 'sales_issue') AND COALESCE(th.source,'') != 'pos_sync'
      AND (th.location_id = ? OR th.location_id IS NULL OR th.location_id = 0)
", [$date_from, $date_to, $loc_id])['cogs'] ?? 0);

echo "\nQuery 3 Result in sales_summary_list.php for Invoices COGS: Rs " . number_format($cogs_q3, 2) . "\n";
