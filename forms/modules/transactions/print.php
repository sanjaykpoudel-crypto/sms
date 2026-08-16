<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

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

if (!$header) {
    die("Transaction not found");
}

// Fetch System Info
$sys_info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$sys = [];
foreach ($sys_info as $row) {
    $sys[$row['meta_field']] = $row['meta_value'];
}

$txn_type = $header['txn_type'];
$details = [];
$displayType = "TRANSACTION DOCUMENT";
$entityLabel = "Party";

// Fetch Specific Transaction Type Details
if ($txn_type == 'vendor_bill') {
    $details = $db->fetchOne("
        SELECT vb.*, v.company_name as entity_name, v.address, v.phone as entity_phone, v.pan_number 
        FROM vendor_bills vb
        LEFT JOIN vendors v ON vb.vendor_id = v.id
        WHERE vb.header_id = :id
    ", ['id' => $id]);
    $displayType = "PURCHASE INVOICE / BILL (खरिद बिजक)";
    $entityLabel = "Vendor / Supplier";
} elseif ($txn_type == 'customer_invoice') {
    $details = $db->fetchOne("
        SELECT ci.*, c.full_name as entity_name, c.email as address, c.phone as entity_phone, c.pan_number 
        FROM customer_invoices ci
        LEFT JOIN customers c ON ci.customer_id = c.id
        WHERE ci.header_id = :id
    ", ['id' => $id]);
    $sale_type = strtolower($details['sale_type'] ?? 'credit');
    $displayType = ($sale_type === 'cash') ? "CASH INVOICE (नगद बिजक)" : "TAX INVOICE (कर बिजक)";
    $entityLabel = "Customer / Buyer";
} elseif ($txn_type == 'customer_payment') {
    $details = $db->fetchOne("
        SELECT p.*, c.full_name as entity_name, c.phone as entity_phone, c.email as address, c.pan_number 
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
    $displayType = "PAYMENT RECEIPT (भुक्तानी रसिद)";
    $entityLabel = "Received From";
} elseif ($txn_type == 'vendor_payment') {
    $details = $db->fetchOne("
        SELECT p.*, v.company_name as entity_name, v.phone as entity_phone, v.address, v.pan_number 
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
    $displayType = "PAYMENT VOUCHER (भुक्तानी भौचर)";
    $entityLabel = "Paid To";
} elseif ($txn_type == 'journal_entry' || $txn_type == 'Journal') {
    $displayType = "JOURNAL VOUCHER (जर्नल भौचर)";
    $details = [
        'entity_name' => $header['memo'] ?: 'General Ledger Entry',
        'total_amount' => $header['net_amount'],
        'subtotal' => $header['net_amount'],
        'tax_amount' => 0,
        'discount_amount' => 0
    ];
} elseif ($txn_type == 'expense') {
    $displayType = "EXPENSE VOUCHER (खर्च भौचर)";
    $details = [
        'entity_name' => $header['memo'] ?: 'Operating Expense',
        'total_amount' => $header['net_amount'],
        'subtotal' => $header['net_amount'],
        'tax_amount' => 0,
        'discount_amount' => 0
    ];
} elseif ($txn_type == 'account_transfer') {
    $displayType = "ACCOUNT TRANSFER VOUCHER (खाता स्थानान्तरण भौचर)";
    $details = [
        'entity_name' => $header['memo'] ?: 'Bank / Cash Transfer',
        'total_amount' => $header['net_amount'],
        'subtotal' => $header['net_amount'],
        'tax_amount' => 0,
        'discount_amount' => 0
    ];
} elseif ($txn_type == 'inventory_adjustment') {
    $displayType = "STOCK ADJUSTMENT VOUCHER (मौज्दात समायोजन भौचर)";
    $details = [
        'entity_name' => $header['memo'] ?: 'Inventory Quantity Adjustment',
        'total_amount' => $header['net_amount'],
        'subtotal' => $header['net_amount'],
        'tax_amount' => 0,
        'discount_amount' => 0
    ];
}

// Fetch Items / Journal Lines
if ($txn_type == 'journal_entry' || $txn_type == 'Journal') {
    $items = $db->fetchAll("
        SELECT je.*, REPLACE(a.id, 'acc-', '') as account_code, a.account_name as item_name, je.entry_type
        FROM journal_entries je
        LEFT JOIN accounts a ON je.account_id = a.id
        WHERE je.header_id = :id
        ORDER BY je.entry_type DESC, je.id ASC
    ", ['id' => $id]);
} else {
    $items = $db->fetchAll("
        SELECT tl.*, i.item_name, i.sku,
               COALESCE(rc.name, NULLIF(TRIM(i.unit_type), ''), NULLIF(TRIM(tl.unit), ''), 'PCS') as unit_display
        FROM transaction_lines tl
        LEFT JOIN items i ON tl.item_id = i.id
        LEFT JOIN reference_codes rc ON (i.unit_type = CAST(rc.id AS CHAR) OR i.unit_type = rc.name OR i.unit_type = rc.code) AND rc.type IN ('unit', 'units')
        WHERE tl.header_id = :id
        ORDER BY tl.line_number ASC
    ", ['id' => $id]);
}

// Fallback for payments
if (empty($items) && ($txn_type == 'customer_payment' || $txn_type == 'vendor_payment') && $details) {
    $memo = !empty($details['transaction_reference']) ? "Ref: " . $details['transaction_reference'] : "Payment Settlement";
    if (!empty($details['cheque_number']))
        $memo .= " [Chq: " . $details['cheque_number'] . "]";

    $items = [
        [
            'item_name' => $memo . " (" . strtoupper($details['payment_method'] ?? 'CASH') . ")",
            'quantity' => 1,
            'unit' => 'LS',
            'unit_price' => $details['amount'],
            'line_total' => $details['amount']
        ]
    ];
}

$grand_total = (float) ($details['total_amount'] ?? $header['net_amount'] ?? 0);
$subtotal = (float) ($details['subtotal'] ?? $grand_total);
$discount = (float) ($details['discount_amount'] ?? 0);
$tax_amount = (float) ($details['tax_amount'] ?? 0);
$taxable = max(0, $subtotal - $discount);

// Determine Status and Color for Print
$statusRaw = $details['payment_status'] ?? $header['status'] ?? 'POSTED';
if (strtolower($statusRaw) === 'paid') {
    $printStatusStr = 'PAID IN FULL';
    $printStatusColor = '#059669';
} else {
    $printStatusStr = strtoupper($statusRaw);
    if (in_array(strtolower($statusRaw), ['unpaid', 'voided'])) {
        $printStatusColor = '#dc2626';
    } elseif (strtolower($statusRaw) === 'partial') {
        $printStatusColor = '#d97706';
    } else {
        $printStatusColor = '#059669';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($displayType); ?> - <?php echo htmlspecialchars($header['txn_number']); ?>
    </title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 0;
            }

            .print-btn {
                display: none !important;
            }

            thead {
                display: table-header-group !important;
            }

            tfoot {
                display: table-footer-group !important;
            }

            tr {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 13px;
            color: #1e293b;
            line-height: 1.4;
            max-width: 820px;
            margin: 0 auto;
            padding: 20px;
            background: #fff;
        }

        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 12px;
        }

        .company-name {
            font-size: 24px;
            font-weight: 800;
            color: #003087;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .company-info {
            font-size: 12px;
            color: #475569;
        }

        .document-title {
            text-align: center;
            font-size: 16px;
            font-weight: 800;
            margin: 15px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f1f5f9;
            padding: 6px 0;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
        }

        .info-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 20px;
        }

        .info-box {
            flex: 1;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 14px;
            background: #fafafa;
        }

        .info-box table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-box td {
            padding: 3px 0;
            vertical-align: top;
        }

        .info-box td:first-child {
            font-weight: 600;
            width: 120px;
            color: #64748b;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
        }

        .items-table th {
            background-color: #f1f5f9;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            font-size: 11px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .totals-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 15px;
        }

        .totals-table {
            width: 320px;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
        }

        .totals-table td:first-child {
            font-weight: 600;
            text-align: right;
            background-color: #f8fafc;
            color: #475569;
        }

        .amount-words-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 25px;
            font-size: 12px;
        }

        .signatures {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }

        .sig-box {
            width: 220px;
            text-align: center;
            border-top: 1px dashed #000;
            padding-top: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
        }

        .print-btn {
            background: #003087;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            border-radius: 6px;
            position: fixed;
            top: 15px;
            right: 15px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .print-btn:hover {
            background: #002266;
        }
    </style>
</head>

<body>
    <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> 🖨️ Print Document</button>

    <!-- HEADER -->
    <?php
    $sys_company_name = $sys['name'] ?? ($sys['company_name'] ?? 'MNS LIQUORS (P) LTD.');
    $sys_company_address = $sys['address'] ?? ($sys['company_address'] ?? 'Kathmandu, Nepal');
    $sys_company_phone = $sys['contact'] ?? ($sys['company_phone'] ?? '');
    $sys_company_pan = $sys['pan_no'] ?? ($sys['company_pan'] ?? ($sys['pan_vat_number'] ?? ''));
    $sys_company_email = $sys['email'] ?? ($sys['company_email'] ?? '');
    $sys_logo = $sys['logo'] ?? '';
    $sys_show_logo = isset($sys['print_logo_show']) ? ((int) $sys['print_logo_show'] === 1) : true;
    ?>
    <div class="header"
        style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 15px;">
        <div class="header-logo" style="flex: 0 0 140px; text-align: left;">
            <?php if (!empty($sys_logo) && file_exists($sys_logo)): ?>
                <img src="<?php echo htmlspecialchars($sys_logo); ?>" alt="Logo"
                    style="max-height: 75px; max-width: 130px; object-fit: contain;">
            <?php else: ?>
                <div style="font-size: 26px; font-weight: 900; color: #003087; line-height: 1; letter-spacing: -0.5px;">
                    <?php echo htmlspecialchars($sys['short_name'] ?? 'MNS'); ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="header-details" style="flex: 1; text-align: right;">
            <div class="company-name"
                style="font-size: 22px; font-weight: 800; color: #003087; margin-bottom: 2px; text-transform: uppercase;">
                <?php echo htmlspecialchars($sys_company_name); ?>
            </div>
            <div class="company-info" style="font-size: 12px; color: #475569; line-height: 1.3;">
                <?php echo nl2br(htmlspecialchars($sys_company_address)); ?><br>
                Phone: <?php echo htmlspecialchars($sys_company_phone); ?>
                <?php if (!empty($sys_company_email)): ?> | Email:
                    <?php echo htmlspecialchars($sys_company_email); ?><?php endif; ?>
                <br>
                <strong style="color: #0f172a;">PAN / VAT No: <?php echo htmlspecialchars($sys_company_pan); ?></strong>
            </div>
        </div>
    </div>

    <!-- DOCUMENT TITLE -->
    <div class="document-title"><?php echo htmlspecialchars($displayType); ?></div>

    <!-- INFO GRID -->
    <div class="info-grid">
        <div class="info-box">
            <table>
                <tr>
                    <td><?php echo htmlspecialchars($entityLabel); ?>:</td>
                    <td><strong><?php echo htmlspecialchars($details['entity_name'] ?? 'General Party'); ?></strong>
                    </td>
                </tr>
                <?php if (!empty($details['address'])): ?>
                    <tr>
                        <td>Address:</td>
                        <td><?php echo htmlspecialchars($details['address']); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (!empty($details['pan_number'])): ?>
                    <tr>
                        <td>PAN / VAT No:</td>
                        <td><strong><?php echo htmlspecialchars($details['pan_number']); ?></strong></td>
                    </tr>
                <?php endif; ?>
                <?php if (!empty($details['entity_phone'])): ?>
                    <tr>
                        <td>Phone:</td>
                        <td><?php echo htmlspecialchars($details['entity_phone']); ?></td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
        <div class="info-box">
            <table>
                <tr>
                    <td>Voucher / Txn No:</td>
                    <td><strong
                            style="color: #003087; font-size: 14px;"><?php echo htmlspecialchars($header['txn_number']); ?></strong>
                    </td>
                </tr>
                <tr>
                    <td>Date:</td>
                    <td><?php echo date('Y-m-d', strtotime($header['txn_date'])); ?></td>
                </tr>
                <tr>
                    <td>Status:</td>
                    <td><span
                            style="font-weight: bold; color: <?php echo $printStatusColor; ?>;"><?php echo htmlspecialchars($printStatusStr); ?></span>
                    </td>
                </tr>
                <tr>
                    <td>Prepared By:</td>
                    <td><?php echo htmlspecialchars($header['created_by_name'] ?? 'System Administrator'); ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ITEMS / GENERAL LEDGER TABLE -->
    <table class="items-table">
        <thead>
            <?php if ($txn_type == 'journal_entry' || $txn_type == 'Journal'): ?>
                <tr>
                    <th width="8%">S.N.</th>
                    <th width="18%">Account Code</th>
                    <th width="44%">Account Title / Description</th>
                    <th width="15%" class="text-right">Debit (Rs)</th>
                    <th width="15%" class="text-right">Credit (Rs)</th>
                </tr>
            <?php else: ?>
                <tr>
                    <th width="6%">S.N.</th>
                    <th width="44%">Description of Goods / Services</th>
                    <th width="12%" class="text-center">Qty</th>
                    <th width="10%" class="text-center">Unit</th>
                    <th width="14%" class="text-right">Rate</th>
                    <th width="14%" class="text-right">Amount (Rs)</th>
                </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php
            $sn = 1;
            $tot_debit = 0;
            $tot_credit = 0;

            foreach ($items as $item):
                if ($txn_type == 'journal_entry' || $txn_type == 'Journal') {
                    $is_debit = ($item['entry_type'] === 'debit');
                    $amt = (float) $item['amount'];
                    if ($is_debit)
                        $tot_debit += $amt;
                    else
                        $tot_credit += $amt;
                    ?>
                    <tr>
                        <td class="text-center"><?php echo $sn++; ?></td>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($item['account_code'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($item['item_name'] ?? ''); ?></td>
                        <td class="text-right"><?php echo $is_debit ? number_format($amt, 2) : '-'; ?></td>
                        <td class="text-right"><?php echo !$is_debit ? number_format($amt, 2) : '-'; ?></td>
                    </tr>
                    <?php
                } else {
                    ?>
                    <tr>
                        <td class="text-center"><?php echo $sn++; ?></td>
                        <td><strong><?php echo htmlspecialchars($item['item_name'] ?? 'Item'); ?></strong></td>
                        <td class="text-center"><?php echo number_format($item['quantity'] ?? 1, 2); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($item['unit'] ?? 'Pcs'); ?></td>
                        <td class="text-right"><?php echo number_format($item['unit_price'] ?? 0, 2); ?></td>
                        <td class="text-right" style="font-weight: 600;">
                            <?php echo number_format($item['line_total'] ?? 0, 2); ?>
                        </td>
                    </tr>
                    <?php
                }
            endforeach;
            ?>
        </tbody>
    </table>

    <!-- TOTALS CONTAINER -->
    <div class="totals-container">
        <table class="totals-table">
            <?php if ($txn_type == 'journal_entry' || $txn_type == 'Journal'): ?>
                <tr>
                    <td>Total Debits:</td>
                    <td class="text-right"><strong>Rs <?php echo number_format($tot_debit, 2); ?></strong></td>
                </tr>
                <tr>
                    <td>Total Credits:</td>
                    <td class="text-right"><strong>Rs <?php echo number_format($tot_credit, 2); ?></strong></td>
                </tr>
                <tr style="font-size: 15px;">
                    <td><strong>Net Voucher Balance:</strong></td>
                    <td class="text-right" style="color: #059669;"><strong>Rs
                            <?php echo number_format($tot_debit, 2); ?></strong></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td>Sub Total:</td>
                    <td class="text-right">Rs <?php echo number_format($subtotal, 2); ?></td>
                </tr>
                <?php if ($discount > 0): ?>
                    <tr>
                        <td>Discount Amount:</td>
                        <td class="text-right" style="color: #ef4444;">- Rs <?php echo number_format($discount, 2); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td>Taxable Amount:</td>
                    <td class="text-right">Rs <?php echo number_format($taxable, 2); ?></td>
                </tr>
                <?php if ($tax_amount > 0): ?>
                    <tr>
                        <td>VAT (13%):</td>
                        <td class="text-right" style="color: #059669;">Rs <?php echo number_format($tax_amount, 2); ?></td>
                    </tr>
                <?php endif; ?>
                <tr style="font-size: 15px;">
                    <td><strong>Grand Total:</strong></td>
                    <td class="text-right" style="color: #003087; font-size: 16px;"><strong>Rs
                            <?php echo number_format($grand_total, 2); ?></strong></td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- AMOUNT IN WORDS -->
    <div class="amount-words-box">
        <strong>Amount in Words:</strong> <?php echo amount_in_words($grand_total); ?>
    </div>

    <?php
    $show_qr = (($sys['payment_qr_show'] ?? '1') !== '0') && ($txn_type === 'customer_invoice');
    if ($show_qr):
        $is_unpaid = isset($details['balance_due']) && (float) $details['balance_due'] > 0.01 && strtolower($details['payment_status'] ?? '') !== 'paid';
        $unpaid_amount = $is_unpaid ? (float) $details['balance_due'] : (float) $grand_total;
        $qr_title = $is_unpaid ? 'UNPAID INVOICE — SCAN TO PAY BALANCE DUE' : ($sys['payment_qr_title'] ?? 'SCAN TO PAY VIA QR');
        $qr_image_db = $sys['payment_qr_image'] ?? '';
        $qr_custom_text = $sys['payment_qr_text'] ?? '';
        $qr_raw = !empty($qr_image_db) ? $qr_image_db : $qr_custom_text;
        $qr_src = generate_static_qr_src($qr_raw, $sys_company_name);
        ?>
        <!-- PAYMENT QR CODE BOX -->
        <div
            style="display: flex; align-items: center; justify-content: space-between; border: 1.5px dashed <?php echo $is_unpaid ? '#e74c3c' : '#003087'; ?>; border-radius: 8px; padding: 10px 16px; margin: 12px 0; background: <?php echo $is_unpaid ? '#fff5f5' : '#f8fafc'; ?>;">
            <div>
                <div
                    style="font-size: 13px; font-weight: 800; color: <?php echo $is_unpaid ? '#c0392b' : '#003087'; ?>; text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fas fa-qrcode"></i> <?php echo htmlspecialchars($qr_title); ?>
                </div>
                <div style="font-size: 11px; color: #475569; margin-top: 4px;">
                    Invoice No: <strong><?php echo htmlspecialchars($header['txn_number']); ?></strong> |
                    <?php echo $is_unpaid ? 'Unpaid Balance' : 'Amount Due'; ?>: <strong
                        style="color: #c0392b; font-size:12px;">Rs <?php echo number_format($unpaid_amount, 2); ?></strong>
                </div>
                <div style="font-size: 10px; color: #64748b; margin-top: 2px;">
                    Scan with eSewa, Fonepay, Mobile Banking or any QR app to pay.
                </div>
            </div>
            <div style="text-align: center;">
                <img src="<?php echo htmlspecialchars($qr_src); ?>" alt="Payment QR"
                    style="width: 85px; height: 85px; border-radius: 4px; border: 1px solid #cbd5e1; background: #fff; padding: 2px;">
            </div>
        </div>
    <?php endif; ?>

    <!-- SIGNATURE BLOCKS -->
    <div class="signatures">
        <div class="sig-box">Prepared By</div>
        <div class="sig-box">Checked / Verified By</div>
        <div class="sig-box">Authorized Signatory</div>
    </div>

    <!-- FOOTER -->
    <div class="footer">
        * Computer Generated Official Document — IRD Nepal Compliant *<br>
        <strong>Copy: Original / Duplicate / Audit Record</strong>
    </div>
</body>

</html>