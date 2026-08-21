<?php
require_once 'database/DBConnection.php';
$db = db();

$as_of = '2026-07-16';

// Historical stock valuation using transaction_lines unit_price as historical cost
$hist_val = (float)($db->fetchOne("
    SELECT COALESCE(SUM(l.quantity * l.unit_price), 0)
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_date <= ?
      AND h.txn_type IN ('inventory_adjustment', 'purchase_receipt', 'vendor_bill')
      AND h.is_deleted = 0
      AND h.status NOT IN ('void', 'voided', 'draft')
", [$as_of])[0] ?? 0);

$hist_cogs = (float)($db->fetchOne("
    SELECT COALESCE(SUM(l.quantity * l.cost_price), 0)
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    WHERE h.txn_date <= ?
      AND h.txn_type IN ('customer_invoice', 'pos_sale', 'sales_issue')
      AND h.is_deleted = 0
      AND h.status NOT IN ('void', 'voided', 'draft')
", [$as_of])[0] ?? 0);

$sub_val = round($hist_val - $hist_cogs, 2);

require_once 'api/ReportingEngine.php';
$gl_val = re_get_inventory_gl_balance($db, $as_of);

echo "=== HISTORICAL RECONCILIATION AS OF {$as_of} ===\n";
echo "Subledger Stock Valuation: Rs " . number_format($sub_val, 2) . "\n";
echo "GL Inventory Asset:        Rs " . number_format($gl_val, 2) . "\n";
echo "Difference:                Rs " . number_format(abs($sub_val - $gl_val), 2) . "\n";
