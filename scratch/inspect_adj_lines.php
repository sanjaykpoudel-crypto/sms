<?php
require_once 'database/DBConnection.php';
$db = db();

$adj1_lines = $db->fetchOne("
    SELECT SUM(quantity * unit_price) as line_tot
    FROM transaction_lines
    WHERE header_id IN (118, 150)
");

echo "Transaction Lines total for ADJ-0001 & ADJ-0003: Rs " . number_format($adj1_lines['line_tot'], 2) . "\n";

$adj1_gl = $db->fetchOne("
    SELECT SUM(amount) as gl_tot
    FROM journal_entries
    WHERE header_id IN (118, 150) AND entry_type = 'debit' AND account_id = '7'
");

echo "Journal Entries (Account 7) total for ADJ-0001 & ADJ-0003: Rs " . number_format($adj1_gl['gl_tot'], 2) . "\n";

$mov_tot = $db->fetchOne("
    SELECT SUM(net_qty * cost_price_at_movement) as mov_tot
    FROM inventory_movements
    WHERE reference_id IN (118, 150) OR reference_number IN ('ADJ-0001', 'ADJ-0003')
");
echo "Inventory Movements total for ADJ-0001 & ADJ-0003: Rs " . number_format($mov_tot['mov_tot'], 2) . "\n";
