<?php
/**
 * Stock Movement & Inventory Inflow/Outflow Analysis Report
 */
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
require_once 'api/reference_helper.php';

$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;

$items = $db->fetchAll("
    SELECT i.id, i.item_name, i.sku as item_code, i.cost_price, COALESCE(SUM(ib.quantity_on_hand), 0) as current_stock
    FROM items i
    LEFT JOIN inventory_balances ib ON ib.item_id = i.id
    WHERE i.is_active = 1 AND i.is_deleted = 0
    GROUP BY i.id, i.item_name, i.sku, i.cost_price
    ORDER BY i.item_name ASC
");

$movement_data = [];
$tot_purchased_qty = 0;
$tot_sold_qty      = 0;

foreach ($items as $item) {
    $iid = $item['id'];
    
    // Purchases / Inflows in date range
    $purchased_qty = (float)($db->fetchOne("
        SELECT SUM(tl.quantity) as total
        FROM transaction_lines tl
        JOIN transaction_headers th ON tl.header_id = th.id
        WHERE tl.item_id = ? AND th.txn_type IN ('bill', 'purchase')
          AND th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ", [$iid, $date_from, $date_to])['total'] ?? 0);

    // Sales / Outflows in date range (Invoices + POS)
    $inv_sold_qty = (float)($db->fetchOne("
        SELECT SUM(tl.quantity) as total
        FROM transaction_lines tl
        JOIN transaction_headers th ON tl.header_id = th.id
        WHERE tl.item_id = ? AND th.txn_type = 'invoice'
          AND th.txn_date BETWEEN ? AND ? AND th.is_deleted = 0 AND th.status NOT IN ('void', 'voided', 'draft')
    ", [$iid, $date_from, $date_to])['total'] ?? 0);

    $pos_sold_qty = (float)($db->fetchOne("
        SELECT SUM(pi.quantity) as total
        FROM pos_items pi
        JOIN pos_entry pe ON pi.pos_id = pe.id
        WHERE pi.item_id = ? AND pe.is_deleted = 0 AND pe.status != 'voided' AND DATE(pe.date_time) BETWEEN ? AND ?
    ", [$iid, $date_from, $date_to])['total'] ?? 0);

    $sold_qty = $inv_sold_qty + $pos_sold_qty;
    $net_movement = $purchased_qty - $sold_qty;

    if ($purchased_qty > 0 || $sold_qty > 0 || $item['current_stock'] > 0) {
        $movement_data[] = [
            'code'          => $item['item_code'],
            'name'          => $item['item_name'],
            'purchased_qty' => $purchased_qty,
            'sold_qty'      => $sold_qty,
            'net_movement'  => $net_movement,
            'current_stock' => (float)$item['current_stock']
        ];
        $tot_purchased_qty += $purchased_qty;
        $tot_sold_qty      += $sold_qty;
    }
}
?>

<?php rpt_filter_bar('Stock Movement & Movement Summary', [
    ['name' => 'date_from', 'label' => 'From', 'type' => 'date', 'default' => $date_from],
    ['name' => 'date_to', 'label' => 'To', 'type' => 'date', 'default' => $date_to],
    rpt_location_filter(),
], 'tbl-stock-movement'); ?>

<div class="ns-portlet" style="max-width: 1050px; margin: 0 auto;">
    <div class="ns-portlet-content" style="padding: 20px;">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding:14px 18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
            <div style="font-size:13px; font-weight:700; color:#334155;">
                <i class="fas fa-boxes"></i> Inventory Stock Flow Activity
            </div>
            <div style="font-size:12px; font-weight:600;">
                Purchases Inflow Qty: <strong style="color:#059669;"><?= number_format($tot_purchased_qty, 2) ?></strong> | 
                Sales Outflow Qty: <strong style="color:#2563eb;"><?= number_format($tot_sold_qty, 2) ?></strong> | 
                Net Qty Flow: <strong style="color:#003087;"><?= number_format($tot_purchased_qty - $tot_sold_qty, 2) ?></strong>
            </div>
        </div>

        <table class="ns-report-table-static" id="tbl-stock-movement">
            <thead>
                <tr>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th style="text-align:right">Inflow (Purchased Qty)</th>
                    <th style="text-align:right">Outflow (Sold Qty)</th>
                    <th style="text-align:right">Net Movement Qty</th>
                    <th style="text-align:right">Current On-Hand Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movement_data)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">No stock movements recorded in selected date range.</td></tr>
                <?php else: ?>
                    <?php foreach ($movement_data as $m): ?>
                        <tr>
                            <td style="font-weight:700; color:#64748b;"><?= htmlspecialchars($m['code'] ?: '—') ?></td>
                            <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($m['name']) ?></td>
                            <td style="text-align:right; color:#059669; font-weight:600;">+<?= number_format($m['purchased_qty'], 2) ?></td>
                            <td style="text-align:right; color:#dc2626; font-weight:600;">-<?= number_format($m['sold_qty'], 2) ?></td>
                            <td style="text-align:right; font-weight:700; color:<?= $m['net_movement'] >= 0 ? '#059669' : '#dc2626' ?>;">
                                <?= $m['net_movement'] >= 0 ? '+' : '' ?><?= number_format($m['net_movement'], 2) ?>
                            </td>
                            <td style="text-align:right; font-weight:800; color:#003087;"><?= number_format($m['current_stock'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#003087; color:#fff; font-weight:900; font-size:13px">
                    <td colspan="2" style="padding:12px 16px">TOTAL QUANTITY MOVEMENTS</td>
                    <td style="text-align:right; padding:12px 16px">+<?= number_format($tot_purchased_qty, 2) ?></td>
                    <td style="text-align:right; padding:12px 16px">-<?= number_format($tot_sold_qty, 2) ?></td>
                    <td style="text-align:right; padding:12px 16px"><?= number_format($tot_purchased_qty - $tot_sold_qty, 2) ?></td>
                    <td style="text-align:right; padding:12px 16px">—</td>
                </tr>
            </tfoot>
        </table>

    </div>
</div>
