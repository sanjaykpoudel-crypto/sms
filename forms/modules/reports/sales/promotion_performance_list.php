<?php
/**
 * forms/modules/reports/sales/promotion_performance_list.php
 * Promotional Sales & Discount Performance Report
 */

require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? $today;
$selected_loc = $_GET['location_id'] ?? '';
$selected_promo = $_GET['promo_code'] ?? '';

$where = "WHERE DATE(p.date_time) BETWEEN ? AND ? AND pi.promo_code IS NOT NULL AND p.is_deleted = 0";
$params = [$date_from, $date_to];

if (!empty($selected_loc)) {
    $where .= " AND (p.location_id = ? OR u.location_id = ?)";
    $params[] = $selected_loc;
    $params[] = $selected_loc;
}
if (!empty($selected_promo)) {
    $where .= " AND pi.promo_code = ?";
    $params[] = $selected_promo;
}

$rows = $db->fetchAll("
    SELECT 
        pi.promo_code,
        COALESCE(pr.name, pi.promo_code) as promo_name,
        i.item_name, i.sku,
        COUNT(DISTINCT p.id) as total_txns,
        SUM(pi.quantity) as total_qty,
        AVG(NULLIF(pi.mrp_at_sale, 0)) as avg_mrp,
        AVG(NULLIF(pi.normal_selling_price_at_sale, 0)) as avg_normal_price,
        AVG(pi.rate) as avg_promo_price,
        SUM(COALESCE(NULLIF(pi.base_qty, 0), pi.quantity * COALESCE(pi.conversion_factor, 1)) * pi.promo_discount_amount) as total_promo_discount,
        SUM(pi.net_amount) as total_net_sales,
        SUM(pi.net_amount - (COALESCE(NULLIF(pi.base_qty, 0), pi.quantity * COALESCE(pi.conversion_factor, 1)) * i.cost_price)) as total_profit
    FROM pos_items pi
    JOIN pos_entry p ON pi.pos_id = p.id
    JOIN items i ON pi.item_id = i.id
    LEFT JOIN users u ON p.created_by = u.id
    LEFT JOIN promotions pr ON pr.promo_code = pi.promo_code
    {$where}
    GROUP BY pi.promo_code, pr.name, i.id, i.item_name, i.sku
    ORDER BY total_net_sales DESC
", $params);

$all_promos = $db->fetchAll("SELECT DISTINCT promo_code, name FROM promotions ORDER BY name ASC");
$all_locations = $db->fetchAll("SELECT id, name FROM locations WHERE is_deleted = 0 ORDER BY name ASC");

$grand_qty = 0;
$grand_discount = 0;
$grand_net = 0;
$grand_profit = 0;

foreach ($rows as $r) {
    $grand_qty += (float)$r['total_qty'];
    $grand_discount += (float)$r['total_promo_discount'];
    $grand_net += (float)$r['total_net_sales'];
    $grand_profit += (float)$r['total_profit'];
}
?>

<div class="ns-page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 20px;">
    <div>
        <h1 class="ns-page-title" style="margin: 0; font-size: 22px; font-weight: 800; color: #0f172a;">
            <i class="fas fa-chart-pie" style="color: #0284c7; margin-right: 10px;"></i> Promotion Sales Performance Report
        </h1>
        <p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b;">Analyze promotional sales revenue, item volumes, customer savings, and profit performance.</p>
    </div>
</div>

<!-- Filters Form -->
<div class="ns-portlet" style="background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 18px; margin-bottom: 20px;">
    <form method="GET" action="" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="page" value="reports/sales/promotion_performance">
        <div>
            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">From Date</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control" style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
        </div>
        <div>
            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">To Date</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control" style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
        </div>
        <div>
            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Location</label>
            <select name="location_id" class="form-control" style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                <option value="">All Locations</option>
                <?php foreach ($all_locations as $loc): ?>
                    <option value="<?php echo $loc['id']; ?>" <?php echo $selected_loc == $loc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($loc['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Promotion Campaign</label>
            <select name="promo_code" class="form-control" style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                <option value="">All Active Promotions</option>
                <?php foreach ($all_promos as $p): ?>
                    <option value="<?php echo htmlspecialchars($p['promo_code']); ?>" <?php echo $selected_promo == $p['promo_code'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['promo_code'] . ' - ' . $p['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="ns-btn ns-btn-primary" style="padding: 8px 18px; font-weight: 700; border-radius: 6px; font-size: 13px;"><i class="fas fa-filter"></i> Run Filter</button>
    </form>
</div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px;">
        <div style="font-size: 12px; font-weight: 700; color: #64748b;">PROMOTIONAL UNITS SOLD</div>
        <div style="font-size: 24px; font-weight: 800; color: #0284c7; margin-top: 4px;"><?php echo number_format($grand_qty, 2); ?></div>
    </div>
    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px;">
        <div style="font-size: 12px; font-weight: 700; color: #64748b;">TOTAL PROMO DISCOUNTS GIVEN</div>
        <div style="font-size: 24px; font-weight: 800; color: #ef4444; margin-top: 4px;">Rs <?php echo number_format($grand_discount, 2); ?></div>
    </div>
    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px;">
        <div style="font-size: 12px; font-weight: 700; color: #64748b;">TOTAL PROMOTIONAL REVENUE</div>
        <div style="font-size: 24px; font-weight: 800; color: #16a34a; margin-top: 4px;">Rs <?php echo number_format($grand_net, 2); ?></div>
    </div>
    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px;">
        <div style="font-size: 12px; font-weight: 700; color: #64748b;">GROSS PROFIT FROM PROMOS</div>
        <div style="font-size: 24px; font-weight: 800; color: <?php echo $grand_profit >= 0 ? '#10b981' : '#ef4444'; ?>; margin-top: 4px;">Rs <?php echo number_format($grand_profit, 2); ?></div>
    </div>
</div>

<!-- Report Table -->
<div class="ns-portlet" style="background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 20px;">
    <div class="table-responsive">
        <table class="ns-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 12px; text-transform: uppercase; color: #475569;">
                    <th style="padding: 12px 15px;">Promotion Code & Name</th>
                    <th style="padding: 12px 15px;">Item Name</th>
                    <th style="padding: 12px 15px; text-align: center;">Txn Count</th>
                    <th style="padding: 12px 15px; text-align: right;">Qty Sold</th>
                    <th style="padding: 12px 15px; text-align: right;">Avg Normal Price</th>
                    <th style="padding: 12px 15px; text-align: right;">Avg Promo Price</th>
                    <th style="padding: 12px 15px; text-align: right;">Total Promo Savings</th>
                    <th style="padding: 12px 15px; text-align: right;">Net Revenue</th>
                    <th style="padding: 12px 15px; text-align: right;">Profit</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 35px; color: #94a3b8;">
                            No promotional sales recorded for the selected date and filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                        <td style="padding: 12px 15px;">
                            <span style="font-weight: 800; color: #0284c7;"><?php echo htmlspecialchars($r['promo_code']); ?></span>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;"><?php echo htmlspecialchars($r['promo_name']); ?></div>
                        </td>
                        <td style="padding: 12px 15px; font-weight: 700; color: #1e293b;">
                            <?php echo htmlspecialchars($r['item_name']); ?>
                            <div style="font-size: 11px; color: #94a3b8; font-weight: normal;">SKU: <?php echo htmlspecialchars($r['sku'] ?: 'N/A'); ?></div>
                        </td>
                        <td style="padding: 12px 15px; text-align: center; font-weight: 700; color: #475569;"><?php echo (int)$r['total_txns']; ?></td>
                        <td style="padding: 12px 15px; text-align: right; font-weight: 700;"><?php echo number_format($r['total_qty'], 2); ?></td>
                        <td style="padding: 12px 15px; text-align: right; color: #64748b;">Rs <?php echo number_format((float)$r['avg_normal_price'], 2); ?></td>
                        <td style="padding: 12px 15px; text-align: right; font-weight: 700; color: #16a34a;">Rs <?php echo number_format((float)$r['avg_promo_price'], 2); ?></td>
                        <td style="padding: 12px 15px; text-align: right; font-weight: 700; color: #ef4444;">Rs <?php echo number_format((float)$r['total_promo_discount'], 2); ?></td>
                        <td style="padding: 12px 15px; text-align: right; font-weight: 800; color: #0f172a;">Rs <?php echo number_format((float)$r['total_net_sales'], 2); ?></td>
                        <td style="padding: 12px 15px; text-align: right; font-weight: 800; color: <?php echo $r['total_profit'] >= 0 ? '#10b981' : '#ef4444'; ?>;">Rs <?php echo number_format((float)$r['total_profit'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
