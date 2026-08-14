<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$fy         = rpt_get_current_fiscal_year_dates();
$today      = date('Y-m-d');
$date_from  = $_GET['date_from']   ?? $fy['start_date'];
$date_to    = $_GET['date_to']     ?? $today;
$category_id = $_GET['category_id'] ?? '';
$item_id     = $_GET['item_id']     ?? '';

$categories = $db->fetchAll("SELECT id, name FROM reference_codes WHERE type = 'category' ORDER BY name ASC");
$cat_options = ['' => 'All Categories'];
foreach ($categories as $c) { $cat_options[$c['id']] = $c['name']; }

$items = $db->fetchAll("SELECT id, sku, item_name FROM items WHERE is_deleted=0 AND is_active=1 ORDER BY item_name ASC");
$item_options = ['' => 'All Items'];
foreach ($items as $it) { $item_options[$it['id']] = $it['item_name']; }

$params = [$date_from, $date_from, $date_to, $date_from, $date_to];

$item_clause = '';
if ($item_id) {
    $item_clause = " AND i.id = ? ";
    $params[] = $item_id;
}

$cat_clause = '';
if ($category_id) {
    $cat_clause = " AND i.item_category = ? ";
    $params[] = $category_id;
}

$loc_sql = rpt_location_sql('h');

$rows = $db->fetchAll("
    SELECT 
        i.id, i.sku, i.item_name, i.cost_price,
        COALESCE(SUM(CASE 
            WHEN h.txn_date < ? THEN
                CASE 
                    WHEN h.txn_type IN ('vendor_bill', 'bill', 'purchase', 'Opening Stock', 'inventory_adjustment') THEN l.quantity 
                    WHEN h.txn_type IN ('customer_invoice', 'invoice', 'pos', 'Sale') THEN -l.quantity 
                    ELSE 0 
                END
            ELSE 0 
        END), 0) AS opening_qty,
        
        COALESCE(SUM(CASE 
            WHEN h.txn_date BETWEEN ? AND ? THEN
                CASE 
                    WHEN h.txn_type IN ('vendor_bill', 'bill', 'purchase', 'Opening Stock') THEN l.quantity 
                    WHEN h.txn_type = 'inventory_adjustment' AND l.quantity > 0 THEN l.quantity
                    ELSE 0 
                END
            ELSE 0 
        END), 0) AS qty_in,
        
        COALESCE(SUM(CASE 
            WHEN h.txn_date BETWEEN ? AND ? THEN
                CASE 
                    WHEN h.txn_type IN ('customer_invoice', 'invoice', 'pos', 'Sale') THEN l.quantity 
                    WHEN h.txn_type = 'inventory_adjustment' AND l.quantity < 0 THEN ABS(l.quantity)
                    ELSE 0 
                END
            ELSE 0 
        END), 0) AS qty_out
        
    FROM items i
    LEFT JOIN transaction_lines l ON l.item_id = i.id
    LEFT JOIN transaction_headers h ON l.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    WHERE i.is_deleted = 0 AND i.is_active = 1 {$item_clause} {$cat_clause}
    GROUP BY i.id
    HAVING (opening_qty != 0 OR qty_in != 0 OR qty_out != 0)
    ORDER BY i.item_name ASC
", $params);

$tot_open = 0; $tot_in = 0; $tot_out = 0; $tot_close = 0; $tot_val = 0;
?>

<?php rpt_filter_bar('Stock Ledger', [
    ['name' => 'date_from',   'label' => 'From',     'type' => 'date',   'default' => $date_from],
    ['name' => 'date_to',     'label' => 'To',       'type' => 'date',   'default' => $date_to],
    ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => $cat_options, 'default' => $category_id],
    ['name' => 'item_id',     'label' => 'Item',     'type' => 'select', 'options' => $item_options, 'default' => $item_id],
    rpt_location_filter(),
], 'tbl-stock-ledger'); ?>

<div class="ns-portlet" style="max-width:1100px; margin:0 auto;">
    <div class="ns-portlet-content" style="padding:15px;">
        <table class="ns-report-table-static" id="tbl-stock-ledger">
            <thead>
                <tr>
                    <th>Item Code / SKU</th>
                    <th>Item Name</th>
                    <th style="text-align:right">Cost Price</th>
                    <th style="text-align:right">Opening Qty</th>
                    <th style="text-align:right">Qty In (+)</th>
                    <th style="text-align:right">Qty Out (-)</th>
                    <th style="text-align:right">Closing Qty</th>
                    <th style="text-align:right">Closing Value (NPR)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">No stock movements recorded in selected period.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): 
                        $open  = (float)$r['opening_qty'];
                        $in    = (float)$r['qty_in'];
                        $out   = (float)$r['qty_out'];
                        $close = $open + $in - $out;
                        $cost  = (float)$r['cost_price'];
                        $val   = $close * $cost;

                        $tot_open  += $open;
                        $tot_in    += $in;
                        $tot_out   += $out;
                        $tot_close += $close;
                        $tot_val   += $val;
                    ?>
                        <tr>
                            <td style="font-weight:700; color:#64748b;"><?= htmlspecialchars($r['sku'] ?: '—') ?></td>
                            <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($r['item_name']) ?></td>
                            <td style="text-align:right; color:#64748b;"><?= rpt_currency($cost) ?></td>
                            <td style="text-align:right; font-weight:600;"><?= number_format($open, 2) ?></td>
                            <td style="text-align:right; color:#059669; font-weight:600;">+<?= number_format($in, 2) ?></td>
                            <td style="text-align:right; color:#dc2626; font-weight:600;">-<?= number_format($out, 2) ?></td>
                            <td style="text-align:right; font-weight:700; color:#003087;"><?= number_format($close, 2) ?></td>
                            <td style="text-align:right; font-weight:800; color:#003087;"><?= rpt_currency($val) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:13px">
                    <td colspan="3" style="padding:10px 14px">TOTAL INVENTORY SUMMARY</td>
                    <td style="text-align:right; padding:10px 14px"><?= number_format($tot_open, 2) ?></td>
                    <td style="text-align:right; padding:10px 14px">+<?= number_format($tot_in, 2) ?></td>
                    <td style="text-align:right; padding:10px 14px">-<?= number_format($tot_out, 2) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= number_format($tot_close, 2) ?></td>
                    <td style="text-align:right; padding:10px 14px"><?= rpt_currency($tot_val) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
