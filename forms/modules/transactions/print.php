<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? '';

if (!$id) {
    die("Invalid Transaction ID");
}

// Fetch Header
$header = $db->fetchOne("
    SELECT t.*, u.full_name as created_by_name 
    FROM transaction_headers t
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.id = :id
", ['id' => $id]);

// Fetch System Info
$sys_info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$sys = [];
foreach($sys_info as $row) {
    $sys[$row['meta_field']] = $row['meta_value'];
}


if (!$header) {
    die("Transaction not found");
}

$txn_type = $header['txn_type'];
$details = [];

// Fetch Specific Details
if ($txn_type == 'vendor_bill') {
    $details = $db->fetchOne("
        SELECT vb.*, v.company_name as entity_name, v.address, v.phone as entity_phone, v.pan_number, v.vat_number 
        FROM vendor_bills vb
        LEFT JOIN vendors v ON vb.vendor_id = v.id
        WHERE vb.header_id = :id
    ", ['id' => $id]);
} elseif ($txn_type == 'customer_invoice') {
    $details = $db->fetchOne("
        SELECT ci.*, c.full_name as entity_name, c.phone as entity_phone, c.pan_number 
        FROM customer_invoices ci
        LEFT JOIN customers c ON ci.customer_id = c.id
        WHERE ci.header_id = :id
    ", ['id' => $id]);
} elseif ($txn_type == 'customer_payment') {
    $details = $db->fetchOne("
        SELECT p.*, c.full_name as entity_name, c.phone as entity_phone, c.address 
        FROM payments p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.header_id = :id
    ", ['id' => $id]);
    if ($details) {
        $details['total_amount'] = $details['amount'];
        $details['subtotal'] = $details['amount'];
        $details['tax_amount'] = 0;
        $details['discount_amount'] = 0;
    }
    $displayType = "PAYMENT RECEIPT";
} elseif ($txn_type == 'vendor_payment') {
    $details = $db->fetchOne("
        SELECT p.*, v.company_name as entity_name, v.phone as entity_phone, v.address 
        FROM payments p
        LEFT JOIN vendors v ON p.vendor_id = v.id
        WHERE p.header_id = :id
    ", ['id' => $id]);
    if ($details) {
        $details['total_amount'] = $details['amount'];
        $details['subtotal'] = $details['amount'];
        $details['tax_amount'] = 0;
        $details['discount_amount'] = 0;
    }
    $displayType = "PAYMENT VOUCHER";
}

// Fetch Items
$items = $db->fetchAll("
    SELECT tl.*, i.item_name
    FROM transaction_lines tl
    LEFT JOIN items i ON tl.item_id = i.id
    WHERE tl.header_id = :id
    ORDER BY tl.line_number ASC
", ['id' => $id]);

// For payments, if no lines, create a placeholder for the receipt
if (empty($items) && ($txn_type == 'customer_payment' || $txn_type == 'vendor_payment') && $details) {
    $memo = !empty($details['transaction_reference']) ? "Ref: " . $details['transaction_reference'] : "Payment Received";
    if (!empty($details['cheque_number'])) $memo .= " [Chq: " . $details['cheque_number'] . "]";
    
    $items = [[
        'item_name' => $memo . " (" . strtoupper($details['payment_method']) . ")",
        'quantity' => 1,
        'unit' => 'LS',
        'unit_price' => $details['amount'],
        'line_total' => $details['amount']
    ]];
}

$displayType = $displayType ?? "INVOICE";
if ($txn_type == 'vendor_bill') $displayType = "PURCHASE BILL";
if ($txn_type == 'customer_invoice' && isset($details['sale_type']) && strtolower($details['sale_type']) == 'cash') {
    $displayType = "CASH INVOICE";
} elseif ($txn_type == 'customer_invoice') {
    $displayType = !empty($sys['print_title']) ? $sys['print_title'] : "TAX INVOICE"; // Nepal VAT standard name
}

$entityLabel = ($txn_type == 'vendor_bill' || $txn_type == 'vendor_payment') ? 'Vendor' : 'Customer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print <?php echo htmlspecialchars($header['txn_number']); ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .print-btn { display: none !important; }
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 13px;
            color: #000;
            line-height: 1.4;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .company-name {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .company-info {
            font-size: 12px;
        }
        .invoice-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            margin: 15px 0;
            text-transform: uppercase;
        }
        .info-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .info-box {
            width: 48%;
        }
        .info-box table {
            width: 100%;
        }
        .info-box td {
            padding: 3px 0;
        }
        .info-box td:first-child {
            font-weight: bold;
            width: 100px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th, .items-table td {
            border: 1px solid #000;
            padding: 6px 8px;
        }
        .items-table th {
            background-color: #f0f0f0;
            -webkit-print-color-adjust: exact;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .totals-container {
            display: flex;
            justify-content: flex-end;
        }
        .totals-table {
            width: 300px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px 8px;
            border: 1px solid #000;
        }
        .totals-table td:first-child {
            font-weight: bold;
            text-align: right;
            background-color: #f0f0f0;
            -webkit-print-color-adjust: exact;
        }
        .amount-words {
            margin-top: 20px;
            font-weight: bold;
        }
        .signatures {
            margin-top: 60px;
            display: flex;
            justify-content: space-between;
        }
        .sig-box {
            width: 200px;
            text-align: center;
            border-top: 1px solid #000;
            padding-top: 5px;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
        .print-btn {
            background: #0055aa;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 14px;
            cursor: pointer;
            border-radius: 4px;
            position: fixed;
            top: 20px;
            right: 20px;
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Print Document</button>
    
    <div class="header">
        <?php if (!empty($sys['logo']) && ($sys['print_logo_show'] ?? 1) == 1): ?>
            <div style="margin-bottom: 10px;">
                <img src="<?php echo $sys['logo']; ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <div class="company-name"><?php echo htmlspecialchars($sys['name'] ?? 'MNS LIQUORS (P) LTD.'); ?></div>
        <div class="company-info">
            <?php echo nl2br(htmlspecialchars($sys['address'] ?? 'Kathmandu, Nepal')); ?><br>
            Phone: <?php echo htmlspecialchars($sys['contact'] ?? '01-4444444'); ?> 
            <?php if(!empty($sys['email'])): ?> | Email: <?php echo htmlspecialchars($sys['email']); ?><?php endif; ?>
            <?php if(!empty($sys['website'])): ?> | Web: <?php echo htmlspecialchars($sys['website']); ?><?php endif; ?>
            <br>
            <strong>PAN / VAT No: <?php echo htmlspecialchars($sys['pan_no'] ?? '600000000'); ?></strong>
        </div>
    </div>
    
    <div class="invoice-title"><?php echo $displayType; ?></div>
    
    <div class="info-grid">
        <div class="info-box">
            <table>
                <tr>
                    <td><?php echo $entityLabel; ?>:</td>
                    <td><?php echo htmlspecialchars($details['entity_name'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td>Address:</td>
                    <td><?php echo htmlspecialchars($details['address'] ?? 'Kathmandu'); ?></td>
                </tr>
                <tr>
                    <td>PAN/VAT No:</td>
                    <td><?php echo htmlspecialchars($details['pan_number'] ?? $details['vat_number'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td>Phone:</td>
                    <td><?php echo htmlspecialchars($details['entity_phone'] ?? ''); ?></td>
                </tr>
            </table>
        </div>
        <div class="info-box">
            <table>
                <tr>
                    <td>Invoice No:</td>
                    <td><strong><?php echo htmlspecialchars($header['txn_number']); ?></strong></td>
                </tr>
                <tr>
                    <td>Date:</td>
                    <td><?php echo date($sys['date_format'] ?? 'Y-m-d', strtotime($header['txn_date'])); ?></td>
                </tr>
                <tr>
                    <td>Miti:</td>
                    <td><!-- Could add BS date here --></td>
                </tr>
                <tr>
                    <td>Created By:</td>
                    <td><?php echo htmlspecialchars($header['created_by_name'] ?? 'System'); ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <table class="items-table">
        <thead>
            <tr>
                <th width="5%">S.N.</th>
                <th width="45%">Description of Goods</th>
                <th width="10%">Qty</th>
                <th width="10%">Unit</th>
                <th width="15%">Rate</th>
                <th width="15%">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sn = 1;
            foreach($items as $item): 
            ?>
            <tr>
                <td class="text-center"><?php echo $sn++; ?></td>
                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                <td class="text-center"><?php echo number_format($item['quantity'], $sys['decimal_places'] ?? 2); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($item['unit']); ?></td>
                <td class="text-right"><?php echo number_format($item['unit_price'], $sys['decimal_places'] ?? 2); ?></td>
                <td class="text-right"><?php echo number_format($item['line_total'], $sys['decimal_places'] ?? 2); ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Empty rows to fill space -->
            <?php for($i=$sn; $i<=5; $i++): ?>
            <tr>
                <td>&nbsp;</td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
    
    <div class="totals-container">
        <table class="totals-table">
            <tr>
                <td>Sub Total:</td>
                <td class="text-right"><?php echo number_format($details['subtotal'] ?? 0, $sys['decimal_places'] ?? 2); ?></td>
            </tr>
            <tr>
                <td>Discount:</td>
                <td class="text-right"><?php echo number_format($details['discount_amount'] ?? 0, $sys['decimal_places'] ?? 2); ?></td>
            </tr>
            <tr>
                <td>Taxable Amount:</td>
                <?php $taxable = ($details['subtotal'] ?? 0) - ($details['discount_amount'] ?? 0); ?>
                <td class="text-right"><?php echo number_format($taxable, $sys['decimal_places'] ?? 2); ?></td>
            </tr>
            <tr>
                <td>VAT (13%):</td>
                <td class="text-right"><?php echo number_format($details['tax_amount'] ?? 0, $sys['decimal_places'] ?? 2); ?></td>
            </tr>
            <tr>
                <td style="font-size: 16px;"><strong>Grand Total:</strong></td>
                <td class="text-right" style="font-size: 16px;"><strong><?php echo number_format($details['total_amount'] ?? 0, $sys['decimal_places'] ?? 2); ?></strong></td>
            </tr>
        </table>
    </div>
    
    <!-- Simple number to words approximation for demo -->
    <div class="amount-words">
        In Words: 
        <?php
        echo amount_in_words($details['total_amount'] ?? 0); 
        ?>
    </div>
    
    <div class="signatures">
        <div class="sig-box">Prepared By</div>
        <div class="sig-box">Checked By</div>
        <div class="sig-box"><?php echo htmlspecialchars($sys['signatory_label'] ?? 'Authorized Signatory'); ?></div>
    </div>
    
    <div class="footer">
        * This is a computer generated invoice *<br>
        <strong>Copy: Original / Duplicate / Triplicate</strong>
    </div>
</body>
</html>
