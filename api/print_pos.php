<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

$db = db();
$id = $_GET['id'] ?? '';
$invoice_no = $_GET['invoice_no'] ?? '';

if (!$id && !$invoice_no) {
    die("Invalid POS Transaction Identifier");
}

if ($id) {
    $pos = $db->fetchOne("
        SELECT p.*, c.full_name as customer_name, c.pan_number as customer_pan, u.full_name as user_name 
        FROM pos_entry p
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.id = ?
    ", [$id]);
} else {
    $pos = $db->fetchOne("
        SELECT p.*, c.full_name as customer_name, c.pan_number as customer_pan, u.full_name as user_name 
        FROM pos_entry p
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.invoice_no = ?
    ", [$invoice_no]);
}

if (!$pos) {
    // Fallback: Check if ID or invoice_no matches transaction_headers (POS / Invoices)
    $th = $db->fetchOne("
        SELECT th.id, th.txn_number as invoice_no, th.txn_date as date_time, th.net_amount, th.gross_amount, th.tax_amount, th.discount_amount, th.sale_type,
               c.full_name as customer_name, c.pan_number as customer_pan, u.full_name as user_name
        FROM transaction_headers th
        LEFT JOIN customer_invoices ci ON th.id = ci.header_id
        LEFT JOIN customers c ON (ci.customer_id = c.id OR th.party_id = c.id)
        LEFT JOIN users u ON th.created_by = u.id
        WHERE th.id = ? OR th.txn_number = ?
    ", [$id, $invoice_no ?: $id]);

    if ($th) {
        $pos = $th;
        $id  = $th['id'];
        $items = $db->fetchAll("
            SELECT l.quantity, l.unit_price as rate, l.line_total as amount, l.tax_amount as tax, l.line_total as net_amount, i.item_name, i.sku
            FROM transaction_lines l
            LEFT JOIN items i ON l.item_id = i.id
            WHERE l.header_id = ?
            ORDER BY l.line_number ASC
        ", [$id]);
    } else {
        die("POS Transaction not found.");
    }
} else {
    // Fetch items from pos_items
    $id = $pos['id'];
    $items = $db->fetchAll("
        SELECT pi.*, i.item_name, i.sku
        FROM pos_items pi
        LEFT JOIN items i ON pi.item_id = i.id
        WHERE pi.pos_id = ?
        ORDER BY pi.id ASC
    ", [$id]);
}

// Fetch payment lines (for split payment breakdown)
$payments_rows = [];
if (!empty($id) && is_numeric($id)) {
    $payments_rows = $db->fetchAll("
        SELECT pp.payment_mode, pp.amount, pp.reference_no, a.account_name
        FROM pos_payments pp
        LEFT JOIN accounts a ON pp.account_id = a.id
        WHERE pp.pos_id = ? AND pp.amount > 0
        ORDER BY pp.id ASC
    ", [$id]);
}

// Fetch System Info (Company Details)
$sys_info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$sys = [];
foreach($sys_info as $row) {
    $sys[$row['meta_field']] = $row['meta_value'];
}

$company_name    = $sys['name'] ?? ($sys['company_name'] ?? 'MNS Liquors');
$company_address = $sys['address'] ?? ($sys['company_address'] ?? 'Gokarneshwor 9, Kathmandu Nepal');
$company_phone   = $sys['contact'] ?? ($sys['company_phone'] ?? '');
$company_pan     = $sys['pan_no'] ?? ($sys['company_pan'] ?? ($sys['pan_vat_number'] ?? '106984242'));
$fy_label        = $sys['current_fiscal_year'] ?? '2081/82';

$net_total     = (float)($pos['net_amount'] ?? ($pos['total_amount'] ?? 0));
$discount      = (float)($pos['discount_amount'] ?? ($pos['discount'] ?? 0));
$gross_total   = (float)($pos['gross_amount'] ?? ($net_total + $discount));
$subtotal      = $gross_total;
$taxable       = round($net_total / 1.13, 2);
$vat_13        = round($net_total - $taxable, 2);
$amount_paid   = (float)($pos['amount_paid'] ?? $net_total);
$change_due    = (float)($pos['change_due'] ?? 0);
$payment_method = strtoupper($pos['payment_method'] ?? ($pos['sale_type'] ?? 'CASH'));

// Compute total promotional savings across all items
$total_promo_savings = 0.0;
foreach ($items as $it) {
    $pdisc = (float)($it['promo_discount_amount'] ?? 0);
    $pqty  = (float)($it['quantity'] ?? 1);
    if (!empty($it['promo_code']) && $pdisc > 0) {
        $total_promo_savings += round($pdisc * $pqty, 2);
    }
}
?>
<!DOCTYPE html>
<html lang="ne">
<head>
    <meta charset="UTF-8">
    <title>Abbreviated Tax Invoice - <?php echo htmlspecialchars($pos['invoice_no']); ?></title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            font-family: 'Courier New', Courier, monospace, Arial, sans-serif;
            width: 76mm;
            margin: 0 auto;
            padding: 5mm 2mm;
            font-size: 11px;
            color: #000;
            line-height: 1.3;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .header-title {
            font-size: 15px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .tax-invoice-badge {
            border: 1px solid #000;
            padding: 3px 6px;
            display: inline-block;
            font-weight: bold;
            font-size: 12px;
            margin: 6px 0;
        }
        .divider {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
        .info-table, .items-table, .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 1px 0;
            vertical-align: top;
        }
        .items-table th, .items-table td {
            padding: 3px 0;
            vertical-align: top;
        }
        .items-table th {
            border-bottom: 1px solid #000;
            border-top: 1px solid #000;
            text-align: left;
        }
        .summary-table td {
            padding: 2px 0;
        }
        .footer {
            margin-top: 10px;
            font-size: 10px;
        }
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom: 15px; text-align: center;">
    <button onclick="window.print()" style="padding: 8px 16px; background: #003087; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
        🖨️ Print Abbreviated Tax Invoice
    </button>
</div>

<!-- HEADER SECTION -->
<div class="text-center">
    <div class="header-title"><?php echo htmlspecialchars($company_name); ?></div>
    <div><?php echo htmlspecialchars($company_address); ?></div>
    <?php if ($company_phone): ?><div>Tel: <?php echo htmlspecialchars($company_phone); ?></div><?php endif; ?>
    <div class="bold" style="margin-top: 2px;">Vat/Pan No: <?php echo htmlspecialchars($company_pan); ?></div>
    
    <div class="tax-invoice-badge">
        ABBREVIATED TAX INVOICE<br>
        <span style="font-size:11px;">(लघु कर बिजक)</span>
    </div>
</div>

<!-- TRANSACTION INFO -->
<table class="info-table">
    <tr>
        <td class="bold">Invoice No:</td>
        <td class="text-right bold"><?php echo htmlspecialchars($pos['invoice_no']); ?></td>
    </tr>
    <tr>
        <td>Date/Time:</td>
        <td class="text-right"><?php echo date('Y-m-d H:i', strtotime($pos['date_time'])); ?></td>
    </tr>
    <tr>
        <td>Fiscal Year:</td>
        <td class="text-right"><?php echo htmlspecialchars($fy_label); ?></td>
    </tr>
    <tr>
        <td>Buyer Name:</td>
        <td class="text-right"><?php echo htmlspecialchars($pos['customer_name'] ?? 'Cash Customer'); ?></td>
    </tr>
    <?php if (!empty($pos['customer_pan'])): ?>
    <tr>
        <td>Buyer PAN:</td>
        <td class="text-right"><?php echo htmlspecialchars($pos['customer_pan']); ?></td>
    </tr>
    <?php endif; ?>
    <tr>
        <td>Payment Mode:</td>
        <td class="text-right bold"><?php echo htmlspecialchars($payment_method); ?></td>
    </tr>
    <tr>
        <td>Cashier:</td>
        <td class="text-right"><?php echo htmlspecialchars($pos['user_name'] ?? 'Admin'); ?></td>
    </tr>
</table>

<div class="divider"></div>

<!-- ITEMS TABLE -->
<table class="items-table">
    <thead>
        <tr>
            <th>Item</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Rate</th>
            <th class="text-right">Amount</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item): 
            $item_qty      = (float)($item['quantity'] ?? 1);
            $item_rate     = (float)($item['rate'] ?? ($item['unit_price'] ?? 0));
            $item_amt      = (float)($item['amount'] ?? ($item['net_amount'] ?? ($item_qty * $item_rate)));
            $item_mrp      = (float)($item['mrp_at_sale'] ?? 0);
            $item_norm     = (float)($item['normal_selling_price_at_sale'] ?? 0);
            $item_pdisc    = (float)($item['promo_discount_amount'] ?? 0);
            $item_savings  = round($item_pdisc * $item_qty, 2);
            $has_promo     = !empty($item['promo_code']);
        ?>
        <tr>
            <td colspan="4" class="bold" style="padding-top:3px;"><?php echo htmlspecialchars($item['item_name']); ?></td>
        </tr>
        <?php if ($has_promo): ?>
        <tr>
            <td colspan="4" style="font-size:10px; color:#555;">
                * Promo: <strong><?php echo htmlspecialchars($item['promo_code']); ?></strong>
                <?php if ($item_mrp > 0): ?> | MRP: Rs <?php echo number_format($item_mrp, 2); ?><?php endif; ?>
            </td>
        </tr>
        <?php if ($item_norm > 0 && $item_norm != $item_rate): ?>
        <tr>
            <td colspan="2" style="font-size:10px; color:#888;">Normal Rate:</td>
            <td class="text-right" style="font-size:10px; color:#888; text-decoration:line-through;">Rs <?php echo number_format($item_norm, 2); ?></td>
            <td class="text-right" style="font-size:10px; color:#888;"></td>
        </tr>
        <?php endif; ?>
        <?php if ($item_savings > 0): ?>
        <tr>
            <td colspan="3" style="font-size:10px; color:#000; font-weight:bold;">  You Save:</td>
            <td class="text-right" style="font-size:10px; font-weight:bold; color:#000;">-Rs <?php echo number_format($item_savings, 2); ?></td>
        </tr>
        <?php endif; ?>
        <?php endif; ?>
        <tr>
            <td style="color:#444;">#<?php echo htmlspecialchars($item['sku'] ?? ''); ?></td>
            <td class="text-right"><?php echo number_format($item_qty, 0); ?></td>
            <td class="text-right"><?php echo number_format($item_rate, 2); ?></td>
            <td class="text-right bold"><?php echo number_format($item_amt, 2); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="divider"></div>

<!-- SUMMARY TABLE -->
<table class="summary-table">
    <tr>
        <td>Sub Total:</td>
        <td class="text-right">Rs <?php echo number_format($subtotal, 2); ?></td>
    </tr>
    <?php if ($total_promo_savings > 0): ?>
    <tr>
        <td style="font-weight:bold;">* Promo Savings:</td>
        <td class="text-right" style="font-weight:bold;">- Rs <?php echo number_format($total_promo_savings, 2); ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($discount > 0): ?>
    <tr>
        <td>Discount:</td>
        <td class="text-right">- Rs <?php echo number_format($discount, 2); ?></td>
    </tr>
    <?php endif; ?>
    <tr>
        <td>Taxable Amount:</td>
        <td class="text-right">Rs <?php echo number_format($taxable, 2); ?></td>
    </tr>
    <tr>
        <td>13% VAT Amount:</td>
        <td class="text-right">Rs <?php echo number_format($vat_13, 2); ?></td>
    </tr>
    <tr class="bold" style="font-size: 13px;">
        <td style="padding-top: 4px; border-top: 1px dashed #000;">GRAND TOTAL:</td>
        <td class="text-right" style="padding-top: 4px; border-top: 1px dashed #000;">Rs <?php echo number_format($net_total, 2); ?></td>
    </tr>
</table>

<div class="divider"></div>

<!-- PAYMENT BREAKDOWN -->
<?php
$has_split = count($payments_rows) > 1;
$total_tendered = 0.0;
foreach ($payments_rows as $pr) { $total_tendered += (float)$pr['amount']; }
$computed_change = round($total_tendered - $net_total, 2);
?>
<table class="summary-table">
    <tr>
        <td colspan="2" style="font-size:10px; font-weight:bold; padding-bottom:2px;">PAYMENT DETAIL<?php echo $has_split ? 'S (Split)' : ''; ?>:</td>
    </tr>
    <?php if (!empty($payments_rows)): ?>
        <?php foreach ($payments_rows as $pr):
            $pmode = strtoupper($pr['account_name'] ?? $pr['payment_mode'] ?? 'CASH');
            $pamt  = (float)$pr['amount'];
        ?>
        <tr>
            <td style="font-size:10px;"><?php echo htmlspecialchars($pmode); ?><?php if (!empty($pr['reference_no']) && $pr['reference_no'] !== 'Change Return'): ?> <small>(Ref: <?php echo htmlspecialchars($pr['reference_no']); ?>)</small><?php endif; ?></td>
            <td class="text-right" style="font-size:10px; font-weight:bold;">Rs <?php echo number_format($pamt, 2); ?></td>
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
    <tr>
        <td style="font-size:10px;"><?php echo htmlspecialchars($payment_method); ?></td>
        <td class="text-right" style="font-size:10px; font-weight:bold;">Rs <?php echo number_format($net_total, 2); ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($computed_change > 0.01): ?>
    <tr>
        <td style="font-size:10px;">Change Return:</td>
        <td class="text-right" style="font-size:10px;">Rs <?php echo number_format($computed_change, 2); ?></td>
    </tr>
    <?php elseif ($change_due > 0.01): ?>
    <tr>
        <td style="font-size:10px;">Change Return:</td>
        <td class="text-right" style="font-size:10px;">Rs <?php echo number_format($change_due, 2); ?></td>
    </tr>
    <?php endif; ?>
</table>

<?php 
$show_qr = ($sys['payment_qr_show'] ?? '1') !== '0';
if ($show_qr): 
    $is_unpaid_pos = isset($pos['balance_due']) && (float)$pos['balance_due'] > 0.01 && strtolower($pos['status'] ?? '') !== 'paid';
    $unpaid_amt_pos = $is_unpaid_pos ? (float)$pos['balance_due'] : (float)$net_total;
    $qr_title = $is_unpaid_pos ? 'SCAN TO PAY UNPAID BALANCE' : ($sys['payment_qr_title'] ?? 'SCAN TO PAY');
    $qr_image_db = $sys['payment_qr_image'] ?? '';
    $qr_custom_text = $sys['payment_qr_text'] ?? '';
    $qr_raw = !empty($qr_image_db) ? $qr_image_db : $qr_custom_text;
    $qr_src = generate_static_qr_src($qr_raw, $company_name);
    if (strpos($qr_src, 'uploads/') === 0) {
        $qr_src = '../' . $qr_src;
    }
?>
<div style="text-align:center; margin-top: 8px; padding-top: 6px; border-top: 1px dashed #000;">
    <div style="font-size: 10px; font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars($qr_title); ?></div>
    <img src="<?php echo htmlspecialchars($qr_src); ?>" alt="QR" style="width: 75px; height: 75px; margin: 4px 0; border: 1px solid #ddd; padding: 2px;">
    <div style="font-size: 9px; color: #333; font-weight: bold;">Pay Rs <?php echo number_format($unpaid_amt_pos, 2); ?></div>
</div>
<?php endif; ?>

<div class="divider"></div>

<!-- FOOTER SECTION -->
<div class="footer text-center">
    <div>* Non-Refundable / Non-Transferable *</div>
    <div>Electronic Billing System (IRD Nepal)</div>
    <div style="margin-top: 4px; font-weight: bold;">Thank You for Shopping with Us!</div>
</div>

<script>
    // Auto-trigger window print on page load
    window.addEventListener('load', function() {
        if (!window.location.search.includes('noprint')) {
            setTimeout(function() { window.print(); }, 400);
        }
    });
</script>

</body>
</html>
