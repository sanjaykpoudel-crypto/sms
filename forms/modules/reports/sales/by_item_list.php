<?php
require_once 'database/DBConnection.php';
require_once 'forms/modules/reports/rpt_helpers.php';
$db = db();

$fy        = rpt_get_current_fiscal_year_dates();
$today     = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $fy['start_date'];
$date_to   = $_GET['date_to']   ?? $today;

$loc_sql = rpt_location_sql('h');
$rows = $db->fetchAll("
    SELECT 
        i.sku, i.item_name, rc.name as item_category,
        SUM(COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))) AS qty_sold,
        SUM(CASE 
            WHEN h.txn_number LIKE 'INV-POS-%' OR h.txn_number LIKE 'POS-SUM-%' THEN l.line_total
            ELSE l.line_total - l.tax_amount
        END)  AS gross_revenue,
        SUM(l.cost_price * COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))) AS total_cost,
        SUM(COALESCE(l.gross_profit, (CASE WHEN h.txn_number LIKE 'INV-POS-%' OR h.txn_number LIKE 'POS-SUM-%' THEN l.line_total ELSE l.line_total - l.tax_amount END) - (l.cost_price * COALESCE(NULLIF(l.base_qty, 0), l.quantity * COALESCE(l.conversion_factor, 1))))) AS gross_profit
    FROM transaction_lines l
    JOIN transaction_headers h ON l.header_id = h.id
    JOIN items i ON l.item_id = i.id
    LEFT JOIN reference_codes rc ON i.item_category = rc.id AND rc.type = 'category'
    WHERE h.txn_type IN ('customer_invoice','POS')
      AND h.txn_date BETWEEN ? AND ?
      AND h.is_deleted = 0 AND h.status NOT IN ('void', 'voided', 'draft') {$loc_sql}
    GROUP BY i.id
    ORDER BY gross_revenue DESC
", [$date_from, $date_to]);

$total_revenue = array_sum(array_column($rows, 'gross_revenue'));
$total_cost    = array_sum(array_column($rows, 'total_cost'));
$total_profit  = array_sum(array_column($rows, 'gross_profit'));
$total_qty     = array_sum(array_column($rows, 'qty_sold'));
?>

<?php rpt_filter_bar('Sales by Item', [
    ['name'=>'date_from','label'=>'From','type'=>'date','default'=>$fy['start_date']],
    ['name'=>'date_to',  'label'=>'To',  'type'=>'date','default'=>$today],
], 'tbl-sales-item'); ?>

<div class="rpt-summary" id="rpt-summary-cards">
    <div class="rpt-summary-card"><div class="val" id="card-revenue"><?= rpt_currency($total_revenue) ?></div><div class="lbl">Total Revenue</div></div>
    <div class="rpt-summary-card"><div class="val" id="card-cost"><?= rpt_currency($total_cost) ?></div><div class="lbl">Total Cost</div></div>
    <div class="rpt-summary-card"><div class="val" id="card-profit"><?= rpt_currency($total_profit) ?></div><div class="lbl">Gross Profit</div></div>
    <div class="rpt-summary-card"><div class="val" id="card-qty"><?= number_format($total_qty) ?></div><div class="lbl">Total Units Sold</div></div>
    <div class="rpt-summary-card"><div class="val" id="card-margin"><?= $total_revenue > 0 ? number_format($total_profit/$total_revenue*100,1).'%' : '0%' ?></div><div class="lbl">Profit Margin</div></div>
</div>

<div class="ns-portlet">
  <div class="ns-portlet-content">
    <table class="ns-table" id="tbl-sales-item">
      <thead><tr>
        <th>Item Name</th><th>Category</th>
        <th style="text-align:right">Qty Sold</th>
        <th style="text-align:right">Revenue</th>
        <th style="text-align:right">Total Cost</th>
        <th style="text-align:right">Gross Profit</th>
        <th style="text-align:right">Margin %</th>
      </tr></thead>
      <tbody>
      <?php if (!empty($rows)): foreach ($rows as $r):
        $margin = $r['gross_revenue'] > 0 ? $r['gross_profit']/$r['gross_revenue']*100 : 0;
        $color = $margin >= 20 ? '#1a7f37' : ($margin >= 10 ? '#9a6700' : '#c00');
      ?>
        <tr>
          <td><?= htmlspecialchars($r['item_name']) ?></td>
          <td><?= htmlspecialchars($r['item_category'] ?? 'Uncategorized') ?></td>
          <td style="text-align:right" data-raw="<?= (float)$r['qty_sold'] ?>"><?= number_format($r['qty_sold'],2) ?></td>
          <td style="text-align:right" data-raw="<?= (float)$r['gross_revenue'] ?>"><?= rpt_currency($r['gross_revenue']) ?></td>
          <td style="text-align:right" data-raw="<?= (float)$r['total_cost'] ?>"><?= rpt_currency($r['total_cost']) ?></td>
          <td style="text-align:right" data-raw="<?= (float)$r['gross_profit'] ?>"><?= rpt_currency($r['gross_profit']) ?></td>
          <td style="text-align:right;color:<?= $color ?>;font-weight:600"><?= number_format($margin,1) ?>%</td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;background:#f8f9fa">
        <td colspan="2">TOTAL</td>
        <td style="text-align:right" id="foot-qty"><?= number_format($total_qty,2) ?></td>
        <td style="text-align:right" id="foot-revenue"><?= rpt_currency($total_revenue) ?></td>
        <td style="text-align:right" id="foot-cost"><?= rpt_currency($total_cost) ?></td>
        <td style="text-align:right" id="foot-profit"><?= rpt_currency($total_profit) ?></td>
        <td style="text-align:right" id="foot-margin"><?= $total_revenue > 0 ? number_format($total_profit/$total_revenue*100,1).'%' : '0%' ?></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<script>
function exportTableToCSV(id){const t=document.getElementById(id);let csv=[];t.querySelectorAll('tr').forEach(r=>{let row=[];r.querySelectorAll('th,td').forEach(c=>row.push('"'+c.innerText.replace(/"/g,'""')+'"'));csv.push(row.join(','))});const b=new Blob([csv.join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='sales_by_item.csv';a.click()}

document.addEventListener('DOMContentLoaded', function() {
    function updateFilteredTotals() {
        const table = document.getElementById('tbl-sales-item');
        if (!table) return;

        let qtySum = 0, revSum = 0, costSum = 0, profitSum = 0;

        if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#tbl-sales-item')) {
            const dt = $('#tbl-sales-item').DataTable();
            const visibleRows = dt.rows({ search: 'applied' }).nodes();
            $(visibleRows).each(function() {
                const cells = $(this).find('td');
                if (cells.length >= 7) {
                    qtySum    += parseFloat(cells.eq(2).attr('data-raw') || cells.eq(2).text().replace(/[^0-9.-]+/g,"")) || 0;
                    revSum    += parseFloat(cells.eq(3).attr('data-raw') || cells.eq(3).text().replace(/[^0-9.-]+/g,"")) || 0;
                    costSum   += parseFloat(cells.eq(4).attr('data-raw') || cells.eq(4).text().replace(/[^0-9.-]+/g,"")) || 0;
                    profitSum += parseFloat(cells.eq(5).attr('data-raw') || cells.eq(5).text().replace(/[^0-9.-]+/g,"")) || 0;
                }
            });
        } else {
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(r => {
                if (r.style.display !== 'none') {
                    const cells = r.querySelectorAll('td');
                    if (cells.length >= 7) {
                        qtySum    += parseFloat(cells[2].getAttribute('data-raw') || cells[2].innerText.replace(/[^0-9.-]+/g,"")) || 0;
                        revSum    += parseFloat(cells[3].getAttribute('data-raw') || cells[3].innerText.replace(/[^0-9.-]+/g,"")) || 0;
                        costSum   += parseFloat(cells[4].getAttribute('data-raw') || cells[4].innerText.replace(/[^0-9.-]+/g,"")) || 0;
                        profitSum += parseFloat(cells[5].getAttribute('data-raw') || cells[5].innerText.replace(/[^0-9.-]+/g,"")) || 0;
                    }
                }
            });
        }

        const margin = revSum > 0 ? (profitSum / revSum * 100) : 0;
        const fmtCurr = v => 'Rs ' + v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const fmtNum  = v => v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const cardRev  = document.getElementById('card-revenue');
        const cardCost = document.getElementById('card-cost');
        const cardProf = document.getElementById('card-profit');
        const cardQty  = document.getElementById('card-qty');
        const cardMarg = document.getElementById('card-margin');

        if (cardRev)  cardRev.innerText  = fmtCurr(revSum);
        if (cardCost) cardCost.innerText = fmtCurr(costSum);
        if (cardProf) cardProf.innerText = fmtCurr(profitSum);
        if (cardQty)  cardQty.innerText  = Math.round(qtySum).toLocaleString();
        if (cardMarg) cardMarg.innerText = margin.toFixed(1) + '%';

        const footQty  = document.getElementById('foot-qty');
        const footRev  = document.getElementById('foot-revenue');
        const footCost = document.getElementById('foot-cost');
        const footProf = document.getElementById('foot-profit');
        const footMarg = document.getElementById('foot-margin');

        if (footQty)  footQty.innerText  = fmtNum(qtySum);
        if (footRev)  footRev.innerText  = fmtCurr(revSum);
        if (footCost) footCost.innerText = fmtCurr(costSum);
        if (footProf) footProf.innerText = fmtCurr(profitSum);
        if (footMarg) footMarg.innerText = margin.toFixed(1) + '%';
    }

    if (typeof $ !== 'undefined') {
        $(document).on('draw.dt search.dt', '#tbl-sales-item', function() {
            updateFilteredTotals();
        });
    }
});
</script>
