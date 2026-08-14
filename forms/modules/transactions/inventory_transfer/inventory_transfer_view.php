<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$db = db();
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div style='padding:20px;color:#ef4444'>Invalid Transfer Request.</div>";
    exit;
}

$header = $db->fetchOne("
    SELECT h.*, 
           fl.name as from_location_name, COALESCE(fl.description, '') as from_location_address,
           tl.name as to_location_name, COALESCE(tl.description, '') as to_location_address,
           u.full_name as creator_name
    FROM transaction_headers h
    LEFT JOIN locations fl ON h.location_id = fl.id
    LEFT JOIN locations tl ON h.party_id = tl.id
    LEFT JOIN users u ON h.created_by = u.id
    WHERE h.id = ? AND h.txn_type = 'inventory_transfer'
", [$id]);

if (!$header) {
    echo "<div style='padding:20px;color:#ef4444'>Inventory Transfer not found.</div>";
    exit;
}

$lines = $db->fetchAll("
    SELECT l.*, i.item_name, i.sku, r.name as unit_name
    FROM transaction_lines l
    JOIN items i ON l.item_id = i.id
    LEFT JOIN reference_codes r ON i.unit_type = r.id AND r.type IN ('unit', 'units')
    WHERE l.header_id = ?
    ORDER BY l.line_number ASC
", [$id]);

$company_name = function_exists('get_accounting_preference') ? (get_accounting_preference('company_name') ?: 'MNS Liquors') : 'MNS Liquors';
?>

<style>
.view-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; max-width: 900px; margin: 0 auto; box-shadow: 0 2px 8px rgba(0,0,0,0.05); font-family: 'Inter', sans-serif; }
.view-hdr { display: flex; justify-content: space-between; border-bottom: 2px solid #0284c7; padding-bottom: 15px; margin-bottom: 20px; }
.view-title { font-size: 22px; font-weight: 800; color: #0284c7; }
.view-badge { display: inline-block; padding: 4px 12px; background: #e0f2fe; color: #0369a1; border-radius: 12px; font-weight: 700; font-size: 12px; text-transform: uppercase; }
.info-grid { display: flex; gap: 30px; margin-bottom: 24px; background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0; }
.info-col { flex: 1; font-size: 13px; color: #334155; line-height: 1.6; }
.info-col strong { color: #1e293b; }
.ns-item-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
.ns-item-table th { background: #f8fafc; color: #475569; padding: 10px 12px; font-weight: 700; text-align: left; border: 1px solid #e2e8f0; }
.ns-item-table td { padding: 10px 12px; border: 1px solid #e2e8f0; }
.total-box { margin-top: 20px; text-align: right; font-size: 15px; font-weight: 700; color: #1e293b; }
.actions-bar { display: flex; gap: 10px; margin-bottom: 20px; max-width: 900px; margin: 0 auto 20px; justify-content: space-between; }
@media print {
    .actions-bar, .ns-header, .ns-nav { display: none !important; }
    .view-card { border: none; box-shadow: none; padding: 0; }
}
</style>

<div class="actions-bar">
    <a href="?page=transactions/inventory_transfer" class="ns-btn"><i class="fas fa-arrow-left"></i> Back to Register</a>
    <div style="display: flex; gap: 10px;">
        <button onclick="window.print()" class="ns-btn ns-btn-primary"><i class="fas fa-print"></i> Print Voucher</button>
        <a href="?page=transactions/inventory_transfer/manage&id=<?php echo $id; ?>" class="ns-btn"><i class="fas fa-edit"></i> Edit Transfer</a>
    </div>
</div>

<div class="view-card">
    <div class="view-hdr">
        <div>
            <div class="view-title"><i class="fas fa-boxes-packing"></i> INVENTORY TRANSFER VOUCHER</div>
            <div style="font-size: 13px; color: #64748b; margin-top: 4px;"><?php echo htmlspecialchars($company_name); ?></div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 18px; font-weight: 800; color: #1e293b;"><?php echo htmlspecialchars($header['txn_number']); ?></div>
            <div style="margin-top: 6px;"><span class="view-badge"><?php echo htmlspecialchars($header['status']); ?></span></div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-col">
            <div style="font-weight: 700; color: #0284c7; font-size: 14px; margin-bottom: 6px;">SOURCE (FROM) LOCATION</div>
            <div><strong>Location:</strong> <?php echo htmlspecialchars($header['from_location_name'] ?? 'Main Store'); ?></div>
            <?php if (!empty($header['from_location_address'])): ?>
                <div><strong>Address:</strong> <?php echo htmlspecialchars($header['from_location_address']); ?></div>
            <?php endif; ?>
            <div><strong>Transfer Date:</strong> <?php echo date('d M Y', strtotime($header['txn_date'])); ?></div>
        </div>
        <div class="info-col">
            <div style="font-weight: 700; color: #10b981; font-size: 14px; margin-bottom: 6px;">DESTINATION (TO) LOCATION</div>
            <div><strong>Location:</strong> <?php echo htmlspecialchars($header['to_location_name'] ?? 'Unknown Store'); ?></div>
            <?php if (!empty($header['to_location_address'])): ?>
                <div><strong>Address:</strong> <?php echo htmlspecialchars($header['to_location_address']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($header['memo'])): ?>
        <div style="background: #fffbe0; border: 1px solid #ffe58f; padding: 10px 14px; border-radius: 6px; font-size: 13px; color: #855900; margin-bottom: 20px;">
            <strong>Remarks / Memo:</strong> <?php echo htmlspecialchars($header['memo']); ?>
        </div>
    <?php endif; ?>

    <table class="ns-item-table" id="transfer-view-table">
        <thead>
            <tr>
                <th width="40" style="text-align: center;">#</th>
                <th>Item Name & Description</th>
                <th width="110" style="text-align: right;">Transferred Qty</th>
                <th width="120" style="text-align: right;">Unit Cost (Rs)</th>
                <th width="140" style="text-align: right;">Line Total (Rs)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $total_qty = 0;
            $total_val = 0;
            foreach ($lines as $idx => $line):
                $total_qty += $line['quantity'];
                $total_val += $line['line_total'];
            ?>
                <tr>
                    <td style="text-align: center; font-weight: 600;"><?php echo $idx + 1; ?></td>
                    <td style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($line['item_name']); ?></td>
                    <td style="text-align: right; font-weight: 700;"><?php echo number_format($line['quantity'], 2); ?></td>
                    <td style="text-align: right;"><?php echo number_format($line['cost_price'], 2); ?></td>
                    <td style="text-align: right; font-weight: 700; color: #0284c7;"><?php echo number_format($line['line_total'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="total-box">
        <div>Total Transferred Quantity: <span style="color: #1e293b;"><?php echo number_format($total_qty, 2); ?></span></div>
        <div style="margin-top: 4px; font-size: 17px;">Total Value: <span style="color: #0284c7;">Rs <?php echo number_format($total_val, 2); ?></span></div>
    </div>

    <div style="margin-top: 60px; display: flex; justify-content: space-between; text-align: center; font-size: 12px; color: #64748b;">
        <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 6px;">Prepared By</div>
        <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 6px;">Dispatched By (From Store)</div>
        <div style="width: 200px; border-top: 1px solid #cbd5e1; padding-top: 6px;">Received By (To Store)</div>
    </div>
</div>
