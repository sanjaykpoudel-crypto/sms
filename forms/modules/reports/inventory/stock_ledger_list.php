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

require_once 'api/InventoryEngine.php';
$user_loc = function_exists('get_user_default_location_id') ? get_user_default_location_id() : '';
$location_id = $_GET['location_id'] ?? ($user_loc ?: ($_SESSION['location_id'] ?? null));

$invEngine = InventoryEngine::getInstance();
$rows = $invEngine->getRealtimeStockLedger($date_from, $date_to, $location_id, $category_id, $item_id);

$tot_open = 0; $tot_in = 0; $tot_out = 0; $tot_close = 0; $tot_val = 0;
?>

<?php rpt_filter_bar('Stock Ledger', [
    ['name' => 'date_from',   'label' => 'From',     'type' => 'date',   'default' => $date_from],
    ['name' => 'date_to',     'label' => 'To',       'type' => 'date',   'default' => $date_to],
    ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => $cat_options, 'default' => $category_id],
    ['name' => 'item_id',     'label' => 'Item',     'type' => 'select', 'options' => $item_options, 'default' => $item_id],
    rpt_location_filter(),
], 'tbl-stock-ledger'); ?>

<div class="ns-portlet">
    <div class="ns-portlet-content">
        <table class="ns-table" id="tbl-stock-ledger">
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
                        <tr data-open="<?= $open ?>" data-in="<?= $in ?>" data-out="<?= $out ?>" data-close="<?= $close ?>" data-val="<?= $val ?>">
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
                    <td style="text-align:right; padding:10px 14px" id="foot-open"><?= number_format($tot_open, 2) ?></td>
                    <td style="text-align:right; padding:10px 14px" id="foot-in">+<?= number_format($tot_in, 2) ?></td>
                    <td style="text-align:right; padding:10px 14px" id="foot-out">-<?= number_format($tot_out, 2) ?></td>
                    <td style="text-align:right; padding:10px 14px" id="foot-close"><?= number_format($tot_close, 2) ?></td>
                    <td style="text-align:right; padding:10px 14px" id="foot-val"><?= rpt_currency($tot_val) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function updateStockLedgerTotals() {
        const table = document.getElementById('tbl-stock-ledger');
        if (!table) return;

        let openSum = 0, inSum = 0, outSum = 0, closeSum = 0, valSum = 0;

        if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#tbl-stock-ledger')) {
            const dt = $('#tbl-stock-ledger').DataTable();
            const visibleRows = dt.rows({ search: 'applied' }).nodes();
            $(visibleRows).each(function() {
                openSum  += parseFloat($(this).attr('data-open')) || 0;
                inSum    += parseFloat($(this).attr('data-in')) || 0;
                outSum   += parseFloat($(this).attr('data-out')) || 0;
                closeSum += parseFloat($(this).attr('data-close')) || 0;
                valSum   += parseFloat($(this).attr('data-val')) || 0;
            });
        } else {
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(r => {
                if (r.style.display !== 'none') {
                    openSum  += parseFloat(r.getAttribute('data-open')) || 0;
                    inSum    += parseFloat(r.getAttribute('data-in')) || 0;
                    outSum   += parseFloat(r.getAttribute('data-out')) || 0;
                    closeSum += parseFloat(r.getAttribute('data-close')) || 0;
                    valSum   += parseFloat(r.getAttribute('data-val')) || 0;
                }
            });
        }

        const fmtCurr = v => 'Rs ' + v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const fmtNum  = v => v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const footOpen  = document.getElementById('foot-open');
        const footIn    = document.getElementById('foot-in');
        const footOut   = document.getElementById('foot-out');
        const footClose = document.getElementById('foot-close');
        const footVal   = document.getElementById('foot-val');

        if (footOpen)  footOpen.innerText  = fmtNum(openSum);
        if (footIn)    footIn.innerText    = '+' + fmtNum(Math.abs(inSum));
        if (footOut)   footOut.innerText   = '-' + fmtNum(Math.abs(outSum));
        if (footClose) footClose.innerText = fmtNum(closeSum);
        if (footVal)   footVal.innerText   = fmtCurr(valSum);
    }

    if (typeof $ !== 'undefined') {
        $(document).on('draw.dt search.dt', '#tbl-stock-ledger', function() {
            updateStockLedgerTotals();
        });
    }
});
</script>
