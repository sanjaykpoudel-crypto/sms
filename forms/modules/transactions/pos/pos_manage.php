<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
$db = db();

$user_id = $_SESSION['user_id'] ?? ($_SESSION['userdata']['id'] ?? '');
$session_role = strtolower($_SESSION['role'] ?? ($_SESSION['userdata']['role'] ?? ''));

$user_info = null;
if ($user_id) {
    $user_info = $db->fetchOne("SELECT * FROM users WHERE id = CAST(? AS CHAR) OR username = ? LIMIT 1", [$user_id, $user_id]);
}
if (!$user_info) {
    $user_info = $db->fetchOne("SELECT * FROM users WHERE is_deleted = 0 ORDER BY id ASC LIMIT 1");
}

$is_admin = false;
if ($session_role === 'admin' || strtolower($user_info['role'] ?? '') === 'admin') {
    $is_admin = true;
}

// User default location
$pos_location_id = $user_info['location_id'] ?? (function_exists('get_user_default_location_id') ? get_user_default_location_id() : '');
if (empty($pos_location_id)) {
    $firstLoc = $db->fetchOne("SELECT id FROM locations WHERE is_deleted = 0 ORDER BY name ASC LIMIT 1");
    $pos_location_id = $firstLoc['id'] ?? '';
}

$all_locations = $db->fetchAll("SELECT id, name FROM locations WHERE is_deleted = 0 ORDER BY name ASC");

require_once 'api/PromotionEngine.php';

// Fetch active items with category names, location-specific stock, cost, and selling price
$items = $db->fetchAll("
    SELECT i.id, i.sku, i.item_name, r.name as category_name, 
        CAST(COALESCE(ib.selling_price, i.selling_price) AS DECIMAL(12,2)) as selling_price, 
        CAST(COALESCE(ib.average_cost, i.cost_price) AS DECIMAL(12,2)) as cost_price, 
        CAST(COALESCE(ib.mrp, i.mrp, 0) AS DECIMAL(12,2)) as mrp,
        i.tax_rate, i.barcode, i.unit_type, i.units_per_case, i.case_unit_name, i.case_selling_price, i.case_barcode,
        CAST(COALESCE(ib.quantity_on_hand, 0) AS DECIMAL(12,2)) as current_stock
    FROM items i 
    LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category'
    LEFT JOIN inventory_balances ib ON ib.item_id = i.id AND ib.location_id = ?
    WHERE i.is_active = 1 AND i.is_deleted = 0 AND COALESCE(ib.quantity_on_hand, 0) > 0
    ORDER BY i.item_name ASC
", [$pos_location_id]);

$promoEngine = PromotionEngine::getInstance();
foreach ($items as &$it) {
    $promoEval = $promoEngine->evaluateItemPromotion($it['id'], $pos_location_id, 1, (float)$it['mrp'], (float)$it['selling_price']);
    $it['has_promotion'] = $promoEval['has_promotion'];
    if ($promoEval['has_promotion']) {
        $it['promotion_id'] = $promoEval['promotion']['id'];
        $it['promo_code'] = $promoEval['promotion']['promo_code'];
        $it['promo_name'] = $promoEval['promotion']['name'];
        $it['promotional_price'] = $promoEval['promotional_selling_price'];
        $it['promo_discount_amount'] = $promoEval['discount_amount_per_unit'];
    }
}

// Fetch bank/cash accounts for payment
$payment_accounts = $db->fetchAll("SELECT id, account_name, account_subtype FROM accounts WHERE (account_type_id = 1 OR account_subtype IN ('Bank', 'Cash')) AND is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
$default_cash_account_id = get_accounting_preference('default_cash_account') ?: (AccountingEngine::getInstance()->resolveAccount('default_cash_account') ?: '');

// Get unique categories (names, not IDs)
$categories = [];
foreach ($items as $item) {
    $cat = !empty($item['category_name']) ? $item['category_name'] : 'Other';
    if (!in_array($cat, $categories)) {
        $categories[] = $cat;
    }
}
sort($categories);

$txn_number = 'POS-' . date('Ymd') . '-' . rand(1000, 9999);
$txn_date = date('Y-m-d');

$edit_id = $_GET['id'] ?? null;
$edit_pos = null;
$edit_items = [];
$edit_payments = [];

if ($edit_id) {
    $edit_pos = $db->fetchOne("SELECT * FROM pos_entry WHERE id = ? AND is_deleted = 0", [$edit_id]);
    if ($edit_pos) {
        $txn_number = $edit_pos['invoice_no'];
        $txn_date = date('Y-m-d', strtotime($edit_pos['date_time']));
        if (!empty($edit_pos['location_id'])) {
            $pos_location_id = $edit_pos['location_id'];
        }
        
        $edit_items_rows = $db->fetchAll("
            SELECT pi.*, i.sku, i.item_name, i.barcode, i.tax_rate, i.unit_type, i.units_per_case, i.case_unit_name, i.case_selling_price, i.case_barcode,
                   CAST(COALESCE(ib.selling_price, i.selling_price) AS DECIMAL(12,2)) as selling_price,
                   CAST(COALESCE(ib.quantity_on_hand, 0) AS DECIMAL(12,2)) as current_stock
            FROM pos_items pi
            JOIN items i ON pi.item_id = i.id
            LEFT JOIN inventory_balances ib ON ib.item_id = i.id AND ib.location_id = ?
            WHERE pi.pos_id = ?
        ", [$pos_location_id, $edit_id]);

        foreach ($edit_items_rows as $e_row) {
            $edit_items[] = [
                'id' => $e_row['item_id'],
                'item_id' => $e_row['item_id'],
                'sku' => $e_row['sku'],
                'name' => $e_row['item_name'],
                'item_name' => $e_row['item_name'],
                'price' => (float)$e_row['rate'],
                'qty' => (float)$e_row['quantity'],
                'unit' => $e_row['txn_unit'] ?: 'PCS',
                'conversion_factor' => (float)($e_row['conversion_factor'] ?: 1),
                'discount' => (float)$e_row['discount'],
                'tax' => (float)$e_row['tax'],
                'tax_rate' => (float)$e_row['tax_rate'],
                'net' => (float)$e_row['net_amount'],
                'unit_type' => $e_row['unit_type'],
                'units_per_case' => $e_row['units_per_case'],
                'case_unit_name' => $e_row['case_unit_name'],
                'case_selling_price' => $e_row['case_selling_price'],
                'case_barcode' => $e_row['case_barcode'],
                'selling_price' => $e_row['selling_price'],
                'current_stock' => (float)$e_row['current_stock'],
                // Restore promo fields from saved pos_items
                'has_promotion' => !empty($e_row['promotion_id']),
                'promotion_id' => $e_row['promotion_id'] ?? null,
                'promo_code' => $e_row['promo_code'] ?? null,
                'mrp_at_sale' => (float)($e_row['mrp_at_sale'] ?? 0),
                'normal_selling_price_at_sale' => (float)($e_row['normal_selling_price_at_sale'] ?? 0),
                'promo_discount_amount' => (float)($e_row['promo_discount_amount'] ?? 0)
            ];
            
            $exists_in_items = false;
            foreach ($items as &$it) {
                if ($it['id'] == $e_row['item_id']) {
                    $exists_in_items = true;
                    break;
                }
            }
            if (!$exists_in_items) {
                $items[] = [
                    'id' => $e_row['item_id'],
                    'sku' => $e_row['sku'],
                    'item_name' => $e_row['item_name'],
                    'category_name' => 'Other',
                    'selling_price' => number_format((float)$e_row['rate'], 2, '.', ''),
                    'cost_price' => '0.00',
                    'mrp' => '0.00',
                    'tax_rate' => $e_row['tax_rate'],
                    'barcode' => $e_row['barcode'],
                    'unit_type' => $e_row['unit_type'],
                    'units_per_case' => $e_row['units_per_case'],
                    'case_unit_name' => $e_row['case_unit_name'],
                    'case_selling_price' => $e_row['case_selling_price'],
                    'case_barcode' => $e_row['case_barcode'],
                    'current_stock' => (float)$e_row['current_stock']
                ];
            }
        }
        
        $edit_payments_rows = $db->fetchAll("
            SELECT * FROM pos_payments WHERE pos_id = ?
        ", [$edit_id]);
        foreach ($edit_payments_rows as $p_row) {
            $edit_payments[] = [
                'account_id' => $p_row['account_id'],
                'amount' => (float)$p_row['amount'],
                'mode' => $p_row['payment_mode'],
                'reference' => $p_row['reference_no'] ?? ''
            ];
        }
    }
}
?>
<style>
    /* POS Shell Styles */
    .ns-header, .ns-nav { display: none !important; }
    .ns-content { padding: 0 !important; margin: 0 !important; max-width: 100% !important; height: 100vh; background: #f0f2f5; }

    .pos-shell { display: flex; height: 100vh; gap: 0; overflow: hidden; font-family: 'Inter', sans-serif; }
    
    /* Left: Product Selection */
    .pos-product-area { flex: 7; display: flex; flex-direction: column; background: #f0f2f5; border-right: 1px solid #e0e6ed; }
    .pos-top-bar { padding: 15px; background: #fff; border-bottom: 1px solid #e0e6ed; display: flex; gap: 15px; align-items: center; }
    .pos-search-wrapper { position: relative; flex: 1; }
    .pos-search-input { width: 100%; padding: 12px 15px 12px 45px; border: 1px solid #d1d9e6; border-radius: 8px; font-size: 15px; outline: none; transition: 0.2s; }
    .pos-search-input:focus { border-color: var(--ns-primary); box-shadow: 0 0 0 4px rgba(0,85,170,0.1); }
    .pos-search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 18px; }
    
    .pos-cat-filter { display: flex; gap: 8px; padding: 10px 15px; overflow-x: auto; background: #fff; scrollbar-width: none; }
    .pos-cat-filter::-webkit-scrollbar { display: none; }
    .pos-cat-btn { padding: 8px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 20px; font-size: 13px; font-weight: 600; color: #475569; cursor: pointer; white-space: nowrap; transition: 0.2s; }
    .pos-cat-btn.active, .pos-cat-btn:hover { background: var(--ns-primary); border-color: var(--ns-primary); color: #fff; }

    .pos-grid { padding: 15px; display: grid; grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); gap: 12px; overflow-y: auto; flex: 1; align-content: start; }
    .pos-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 10px 10px; cursor: pointer; transition: 0.2s; display: flex; flex-direction: column; justify-content: flex-start; min-height: 140px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); box-sizing: border-box; overflow: hidden; }
    .pos-card:hover { border-color: var(--ns-primary); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,85,170,0.08); }
    .pos-card.has-promo { border-color: #dc2626; border-width: 2px; }
    .pos-card-name { font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; font-weight: 800; color: #ea580c; margin-bottom: 6px; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; flex-shrink: 0; letter-spacing: 0.2px; text-align: center; word-break: break-word; }
    .pos-card-price { font-size: 13px; font-weight: 700; color: var(--ns-accent); }
    .pos-card-sku { font-size: 10px; color: #94a3b8; margin-top: 3px; font-weight: 500; }
    .pos-card-detail { font-size: 10px; display: flex; justify-content: space-between; margin-top: 2px; color: #475569; }
    .pos-card-detail span:last-child { font-weight: 700; }
    .pos-promo-badge { display: inline-block; background: #dc2626; color: #fff; border-radius: 3px; padding: 1px 4px; font-size: 9px; font-weight: 800; margin-top: 4px; letter-spacing: 0.5px; }

    /* Right: Cart & Payment */
    .pos-sidebar { flex: 4; display: flex; flex-direction: column; background: #fff; border-left: 1px solid #e0e6ed; min-width: 400px; max-width: 500px; box-shadow: -4px 0 15px rgba(0,0,0,0.03); }
    .pos-cart-hdr { padding: 18px 20px; background: var(--ns-primary); color: #fff; display: flex; justify-content: space-between; align-items: center; }
    .pos-cart-hdr h2 { font-size: 18px; margin: 0; font-weight: 700; }
    .pos-cart-items { flex: 1; overflow-y: auto; padding: 10px; background: #fafbfc; }
    
    .cart-item { display: flex; align-items: center; padding: 12px; background: #fff; border: 1px solid #e2e8f0; margin-bottom: 8px; border-radius: 10px; position: relative; }
    .cart-item-info { flex: 1; min-width: 0; }
    .cart-item-name { font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; font-weight: 800; color: #ea580c; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: 0.2px; }
    .cart-item-meta { font-size: 11px; color: #64748b; display: flex; gap: 8px; }
    
    .cart-qty-ctrl { display: flex; align-items: center; background: #f1f5f9; border-radius: 6px; padding: 2px; margin-left: 10px; }
    .cart-qty-btn { border: none; background: transparent; width: 28px; height: 28px; color: #475569; cursor: pointer; border-radius: 4px; display: flex; align-items: center; justify-content: center; }
    .cart-qty-btn:hover { background: #fff; color: var(--ns-primary); }
    .cart-qty-val { width: 35px; border: none; background: transparent; text-align: center; font-size: 14px; font-weight: 700; color: #1e293b; }
    
    .cart-item-total { font-size: 14px; font-weight: 700; color: #1e293b; min-width: 80px; text-align: right; margin-left: 10px; }
    .cart-item-del { color: #ef4444; background: #fef2f2; border: none; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; margin-left: 10px; transition: 0.2s; }
    .cart-item-del:hover { background: #ef4444; color: #fff; }

    /* Footer / Payments */
    .pos-checkout-area { padding: 20px; border-top: 1px solid #e0e6ed; background: #fff; }
    .pos-summary-line { display: flex; justify-content: space-between; font-size: 14px; color: #64748b; margin-bottom: 8px; font-weight: 500; }
    .pos-summary-line.total { font-size: 24px; font-weight: 800; color: var(--ns-primary); border-top: 2px dashed #e2e8f0; padding-top: 12px; margin-top: 12px; }
    
    .payment-grid { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 20px; }
    .pay-row { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; }
    .pay-select { flex: 2; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; min-width: 0; }
    .pay-amount { flex: 1; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 700; font-size: 14px; min-width: 0; }
    
    /* Hide number input spinners globally within POS */
    input::-webkit-outer-spin-button,
    input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    input[type=number] {
        -moz-appearance: textfield;
    }
    
    .pos-action-btn { width: 100%; padding: 16px; background: var(--ns-accent); color: #fff; border: none; border-radius: 10px; font-size: 18px; font-weight: 800; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 6px rgba(243, 156, 18, 0.2); }
    .pos-action-btn:hover { background: #e67e22; transform: translateY(-1px); box-shadow: 0 6px 12px rgba(243, 156, 18, 0.3); }
    .pos-action-btn:disabled { background: #cbd5e1; color: #94a3b8; cursor: not-allowed; box-shadow: none; transform: none; }

    .change-label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; }
    .change-value { font-size: 20px; font-weight: 800; color: #10b981; }
    .change-value.negative { color: #ef4444; }

    /* Barcode simulation pulse */
    @keyframes pulse { 0% { opacity: 0.5; } 50% { opacity: 1; } 100% { opacity: 0.5; } }
    .barcode-active { color: #10b981; font-size: 12px; font-weight: 700; animation: pulse 2s infinite; }
</style>

<div class="pos-shell">
    <div class="pos-product-area">
        <div class="pos-top-bar">
            <button class="ns-btn" onclick="location.href='?page=home'" style="border-radius: 8px; height: 45px;"><i class="fas fa-home"></i></button>
            <div class="pos-search-wrapper">
                <i class="fas fa-search pos-search-icon"></i>
                <input type="text" id="pos-search" class="pos-search-input" placeholder="Scan Barcode or Search Item Name..." autocomplete="off" autofocus>
            </div>

            <!-- LOCATION SELECTOR FOR POS -->
            <?php if ($is_admin): ?>
                <div class="pos-location-wrapper" style="display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 4px 12px; border-radius: 8px;">
                    <i class="fas fa-map-marker-alt" style="color: var(--ns-primary); font-size: 15px;"></i>
                    <label style="font-size: 12px; font-weight: 700; color: #475569; margin: 0; white-space: nowrap;">Location:</label>
                    <select id="pos-location-select" style="border: none; background: transparent; font-weight: 700; font-size: 13px; color: #1e293b; cursor: pointer; outline: none;" onchange="changePosLocation(this.value)">
                        <?php foreach ($all_locations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['id']); ?>" <?php echo ($loc['id'] == $pos_location_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="pos-location-badge" style="display: flex; align-items: center; gap: 6px; background: #f1f5f9; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 700; color: #334155;">
                    <i class="fas fa-map-marker-alt" style="color: var(--ns-primary);"></i>
                    <span>
                        <?php 
                            $user_loc_name = 'Default Location';
                            foreach ($all_locations as $l) {
                                if ($l['id'] == $pos_location_id) { $user_loc_name = $l['name']; break; }
                            }
                            echo htmlspecialchars($user_loc_name);
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <div class="barcode-active"><i class="fas fa-barcode"></i> SCANNER READY</div>
        </div>
        
        <div class="pos-cat-filter" id="pos-cat-filter">
            <button class="pos-cat-btn active" onclick="filterCategory('all')">All Products</button>
            <?php foreach($categories as $cat): ?>
                <button class="pos-cat-btn" onclick="filterCategory('<?php echo htmlspecialchars($cat); ?>')"><?php echo ucfirst(htmlspecialchars($cat)); ?></button>
            <?php endforeach; ?>
        </div>
        
        <div class="pos-grid" id="pos-grid">
            <!-- Rendered by JS -->
        </div>
    </div>

    <div class="pos-sidebar">
        <div class="pos-cart-hdr">
            <h2><i class="fas fa-shopping-basket"></i> <?php echo $edit_pos ? 'Edit POS Sale' : 'POS Cart'; ?></h2>
            <div style="font-size: 12px; font-weight: 600; opacity: 0.9;"><?php echo $txn_number; ?></div>
        </div>
        
        <div class="pos-cart-items" id="pos-cart-items">
            <!-- Rendered by JS -->
            <div id="empty-cart-msg" style="text-align: center; color: #cbd5e1; margin-top: 50px;">
                <i class="fas fa-cart-plus" style="font-size: 64px; margin-bottom: 15px; opacity: 0.5;"></i>
                <p style="font-weight: 600;">Scan item to start billing</p>
            </div>
        </div>

        <div class="pos-checkout-area">
            <input type="hidden" id="pos-location-id" name="location_id" value="<?php echo htmlspecialchars($pos_location_id); ?>">

            <div class="pos-summary">
                <div class="pos-summary-line">
                    <span>Subtotal</span>
                    <span id="txt-subtotal">Rs 0.00</span>
                </div>
                <div class="pos-summary-line" id="promo-disc-line" style="display:none; color:#dc2626;">
                    <span><i class="fas fa-tag" style="font-size:11px; margin-right:3px;"></i>Promo Discount</span>
                    <span id="txt-promo-disc" style="font-weight:700;">- Rs 0.00</span>
                </div>
                <div class="pos-summary-line">
                    <span>Discount (Total)</span>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <select id="discount-type" style="padding: 2px; border: 1px solid #ddd; font-size: 11px;" onchange="calculateTotals()">
                            <option value="fixed">Fixed</option>
                            <option value="percentage">%</option>
                        </select>
                        <input type="number" id="discount-val" value="0" style="width: 60px; padding: 2px; border: 1px solid #ddd; text-align: right; font-size: 11px;" oninput="calculateTotals()">
                    </div>
                </div>
                <div class="pos-summary-line">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="include-tax" checked onchange="calculateTotals()" style="width: 16px; height: 16px; cursor: pointer;">
                        <label for="include-tax" style="cursor: pointer; font-size: 13px; color: #1e293b; font-weight: 600;">Calculate Tax (VAT 13%)</label>
                    </div>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <span style="font-size: 11px; color: #64748b;">Rs</span>
                        <input type="number" id="tax-amount-val" value="0.00" step="0.01" style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: right; font-size: 14px; font-weight: 700; color: #1e293b;" oninput="updateNetTotal()">
                    </div>
                </div>
                <div class="pos-summary-line total">
                    <span>Net Payable</span>
                    <span id="txt-total">Rs 0.00</span>
                </div>
            </div>

            <div class="payment-grid">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span class="change-label">Payment Split</span>
                    <button class="ns-btn" style="padding: 2px 10px; font-size: 11px;" onclick="addPaymentLine()"><i class="fas fa-plus"></i> Split</button>
                </div>
                <div id="payment-lines">
                    <!-- Rendered by JS -->
                </div>
                <div id="qr-btn-container" style="margin-top: 10px; display: none;">
                    <button type="button" class="ns-btn" onclick="showPosQrModal()" style="width: 100%; background: #003087; color: #fff; padding: 10px; font-weight: 700; border-radius: 8px; font-size: 13px; cursor: pointer; transition: 0.2s; border: none; box-shadow: 0 2px 4px rgba(0, 48, 135, 0.2);">
                        <i class="fas fa-qrcode"></i> Generate Payment QR Code
                    </button>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; border-top: 1px solid #e2e8f0; padding-top: 10px;">
                    <span class="change-label">Change Due</span>
                    <span id="change-due" class="change-value">0.00</span>
                </div>
            </div>

            <button id="btn-checkout" class="pos-action-btn" onclick="completeSale()" disabled>
                <i class="fas fa-check-double"></i> <?php echo $edit_pos ? 'Update POS Sale (F10)' : 'Complete Sale (F10)'; ?>
            </button>
        </div>
    </div>
</div>

<!-- POS PAYMENT QR MODAL -->
<div id="pos-qr-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 99999; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 16px; width: 380px; max-width: 90%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); text-align: center; position: relative; animation: modalPop 0.25s ease-out;">
        <button onclick="closePosQrModal()" style="position: absolute; top: 14px; right: 14px; border: none; background: #f1f5f9; color: #64748b; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center;"><i class="fas fa-times"></i></button>
        <div style="color: #003087; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;" id="pos-qr-company-name">MNS LIQUORS</div>
        <div style="font-size: 12px; color: #64748b; margin-bottom: 15px;" id="pos-qr-txn-no">Ref: <?php echo $txn_number; ?></div>
        
        <div style="background: #f8fafc; border: 2px dashed #003087; border-radius: 12px; padding: 15px; display: inline-block; margin-bottom: 15px; width: 100%; box-sizing: border-box;">
            <img id="pos-qr-img" src="" alt="Payment QR" style="width: 210px; height: 210px; border-radius: 8px; background: #fff; padding: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: block; margin: 0 auto;">
            <div style="font-size: 22px; font-weight: 800; color: #16a34a; margin-top: 12px;" id="pos-qr-amount-txt">Rs 0.00</div>
        </div>

        <div style="font-size: 12px; color: #475569; font-weight: 500; margin-bottom: 18px;">
            <i class="fas fa-mobile-alt" style="color: #003087; margin-right: 4px;"></i> Scan with eSewa, Fonepay, Mobile Banking or any QR app to pay.
        </div>
        <button type="button" class="ns-btn" onclick="closePosQrModal()" style="width: 100%; padding: 12px; background: #003087; color: #fff; font-weight: 700; border-radius: 8px; border: none; cursor: pointer;">Done / Close</button>
    </div>
</div>
<style>
@keyframes modalPop { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
</style>

<script>
const items = <?php echo json_encode($items); ?>;
const accounts = <?php echo json_encode($payment_accounts); ?>;
const defaultCashAccountId = <?php echo json_encode((string)$default_cash_account_id); ?>;

const posId = <?php echo json_encode($edit_id); ?>;
const editPosData = <?php echo json_encode($edit_pos); ?>;
const initialCart = <?php echo json_encode($edit_items); ?>;
const initialPayments = <?php echo json_encode($edit_payments); ?>;

let cart = initialCart.length > 0 ? initialCart : [];
let payments = initialPayments.length > 0 ? initialPayments : [];
let activeCat = 'all';

function changePosLocation(locId) {
    const hiddenInput = document.getElementById('pos-location-id');
    if (cart.length > 0) {
        if (confirm("Switching location will clear the current POS cart items. Proceed?")) {
            cart = [];
            renderCart();
            calculateTotals();
        } else {
            const sel = document.getElementById('pos-location-select');
            if (sel && hiddenInput) sel.value = hiddenInput.value;
            return;
        }
    }
    if (hiddenInput) {
        hiddenInput.value = locId;
    }
    refreshPosItems();
}

function refreshPosItems() {
    const locId = document.getElementById('pos-location-id')?.value || '<?php echo htmlspecialchars($pos_location_id); ?>';
    fetch('api/get_pos_items.php?location_id=' + encodeURIComponent(locId))
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success' && Array.isArray(res.items)) {
                items.length = 0;
                items.push(...res.items);
                renderGrid(document.getElementById('pos-search').value || '');
            }
        })
        .catch(err => console.error('Real-time fetch error:', err));
}

function getCartQty(itemId) {
    const found = cart.find(c => c.id === itemId);
    return found ? parseFloat(found.qty || 0) : 0;
}

function init() {
    renderGrid();
    if (initialPayments.length > 0) {
        renderPayments();
    } else {
        addPaymentLine();
    }
    if (initialCart.length > 0) {
        if (editPosData) {
            if (editPosData.discount_type) {
                const discTypeEl = document.getElementById('discount-type');
                if (discTypeEl) discTypeEl.value = editPosData.discount_type;
            }
            if (editPosData.discount_value !== undefined && editPosData.discount_value !== null) {
                const discValEl = document.getElementById('discount-val');
                if (discValEl) discValEl.value = editPosData.discount_value;
            }
        }
        renderCart();
        calculateTotals();
    }
    refreshPosItems();
    
    // Search listener
    document.getElementById('pos-search').addEventListener('input', (e) => {
        renderGrid(e.target.value);
        const query = e.target.value.trim().toLowerCase();
        if (query.length > 2) {
            // Check exact match for SKU, PCS Barcode, or CASE Barcode
            const pcsMatch  = items.find(i => (i.sku && i.sku.toLowerCase() === query) || (i.barcode && i.barcode.toLowerCase() === query));
            const caseMatch = items.find(i => i.case_barcode && i.case_barcode.toLowerCase() === query);
            
            if (caseMatch) {
                addToCart(caseMatch, 'CASE');
                e.target.value = '';
                renderGrid();
            } else if (pcsMatch) {
                addToCart(pcsMatch, 'PCS');
                e.target.value = '';
                renderGrid();
            }
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if(e.key === 'F10') {
            e.preventDefault();
            completeSale();
        }
    });
}

function getCartBaseQty(itemId) {
    let baseQty = 0;
    cart.filter(c => c.id === itemId).forEach(c => {
        const conv = parseFloat(c.conversion_factor || 1);
        baseQty += (parseFloat(c.qty || 0) * conv);
    });
    return baseQty;
}

function getCartQtyLabel(itemId) {
    const found = cart.filter(c => c.id === itemId);
    if (found.length === 0) return '';
    return found.map(c => `${c.qty} ${c.unit}`).join(', ');
}

function renderGrid(search = '') {
    const grid = document.getElementById('pos-grid');
    if (!grid) return;
    grid.innerHTML = '';
    
    const searchLower = (search || '').toLowerCase().trim();

    const filtered = items.filter(i => {
        if (!i) return false;
        const itemCat = i.category_name || 'Other';
        const matchCat = (activeCat === 'all' || itemCat.toLowerCase() === activeCat.toLowerCase());
        const itemName = (i.item_name || i.name || '').toLowerCase();
        const itemSku = (i.sku || '').toLowerCase();
        const matchSearch = searchLower === '' || itemName.includes(searchLower) || itemSku.includes(searchLower);
        return matchCat && matchSearch;
    });

    filtered.forEach(item => {
        const totalStockPCS = parseFloat(item.current_stock || 0);
        const inCartBasePCS = getCartBaseQty(item.id);
        const availStockPCS = totalStockPCS - inCartBasePCS;

        const convFactor   = parseInt(item.units_per_case || 1);
        const caseUnitName = item.case_unit_name || 'CASE';
        const baseUnitName = item.unit_type || 'PCS';

        const div = document.createElement('div');
        div.className = 'pos-card';
        div.onclick = () => addToCart(item);

        const stockColor = availStockPCS <= 0 ? '#ef4444' : (availStockPCS <= (convFactor > 1 ? convFactor : 5) ? '#f59e0b' : '#10b981');
        const costPrice  = parseFloat(item.cost_price || 0);
        const sellPrice  = parseFloat(item.selling_price || 0);
        const mrpPrice   = parseFloat(item.mrp || 0);

        const cartText = getCartQtyLabel(item.id);
        const cartBadge = cartText ? `<small style="color:#64748b;font-weight:normal">(${cartText} in cart)</small>` : '';

        let stockDisp = '';
        if (convFactor > 1) {
            const cases = Math.floor(availStockPCS / convFactor);
            const remPcs = availStockPCS % convFactor;
            if (availStockPCS <= 0) {
                stockDisp = `0 PCS`;
            } else if (cases > 0 && remPcs > 0) {
                stockDisp = `${cases} ${caseUnitName} ${remPcs} ${baseUnitName}`;
            } else if (cases > 0) {
                stockDisp = `${cases} ${caseUnitName} (${availStockPCS.toFixed(0)} ${baseUnitName})`;
            } else {
                stockDisp = `${availStockPCS.toFixed(0)} ${baseUnitName}`;
            }
        } else {
            stockDisp = `${Math.max(0, availStockPCS).toFixed(0)} ${baseUnitName}`;
        }

        const hasPromo = Boolean(item.has_promotion && item.promotional_price !== undefined && item.promotional_price !== null);
        const promoPrice = hasPromo ? parseFloat(item.promotional_price || sellPrice) : sellPrice;
        const displayName = item.item_name || item.name || 'Item';

        if (hasPromo) div.classList.add('has-promo');

        // Sell price display: strikethrough if promo
        const sellRow = hasPromo
            ? `<span style="text-decoration:line-through;color:#94a3b8;">Rs ${sellPrice.toFixed(2)}</span>&nbsp;<span style="color:#16a34a;font-weight:800;">Rs ${promoPrice.toFixed(2)}</span>`
            : `<span style="color:#16a34a;font-weight:700;">Rs ${sellPrice.toFixed(2)}</span>`;

        div.innerHTML = `
            <div class="pos-card-name">${displayName}</div>
            ${hasPromo ? `<div style="text-align:center;"><span class="pos-promo-badge">&#127991; ${item.promo_code || 'PROMO'}</span></div>` : ''}
            <div style="flex:1; display:flex; flex-direction:column; justify-content:flex-end; gap:1px; font-size:10px; color:#475569; margin-top:5px;">
                <div class="pos-card-detail"><span>Stock:</span><span style="color:${stockColor};">${stockDisp}${cartText ? ` <small style='color:#64748b'>(${cartText})</small>` : ''}</span></div>
                <div class="pos-card-detail"><span>Cost:</span><span style="color:#0284c7;">Rs ${costPrice.toFixed(2)}</span></div>
                <div class="pos-card-detail"><span>Sell:</span><span>${sellRow}</span></div>
                <div class="pos-card-detail"><span>MRP:</span><span style="color:#7c3aed;">Rs ${mrpPrice.toFixed(2)}</span></div>
            </div>
        `;
        grid.appendChild(div);
    });
}

function filterCategory(cat) {
    activeCat = cat;
    document.querySelectorAll('.pos-cat-btn').forEach(b => {
        const btnText = b.innerText.trim().toLowerCase();
        const isAll = (cat.toLowerCase() === 'all' && (btnText.includes('all') || btnText === 'all products'));
        b.classList.toggle('active', btnText === cat.toLowerCase() || isAll);
    });
    renderGrid(document.getElementById('pos-search')?.value || '');
}

function addToCart(item, requestedUnit = 'PCS') {
    if (parseFloat(item.selling_price || 0) === 0) {
        alert("Warning: Selling price is not set for this item.");
    }
    
    const convFactor = parseInt(item.units_per_case || 1);
    const baseUnit   = item.unit_type || 'PCS';
    const caseUnit   = item.case_unit_name || 'CASE';
    const isCaseReq  = (requestedUnit === 'CASE' || requestedUnit === 'BOX' || requestedUnit === caseUnit);
    
    const targetUnit = isCaseReq ? caseUnit : baseUnit;
    const targetConv = isCaseReq ? (convFactor > 0 ? convFactor : 1) : 1;
    
    let defaultPrice = parseFloat(item.selling_price || 0);
    if (item.has_promotion && parseFloat(item.promotional_price) > 0) {
        defaultPrice = parseFloat(item.promotional_price);
    }
    if (isCaseReq) {
        defaultPrice = item.case_selling_price && parseFloat(item.case_selling_price) > 0 
                       ? parseFloat(item.case_selling_price) 
                       : Math.round(defaultPrice * targetConv * 100) / 100;
    }

    const idx = cart.findIndex(c => c.id === item.id && c.unit === targetUnit);
    if (idx > -1) {
        cart[idx].qty += 1;
    } else {
        cart.push({
            ...item,
            unit: targetUnit,
            conversion_factor: targetConv,
            qty: 1,
            price: defaultPrice,
            discount: 0,
            promotion_id: item.has_promotion ? item.promotion_id : (item.promotion_id || null),
            promo_code: item.has_promotion ? item.promo_code : (item.promo_code || null),
            mrp_at_sale: parseFloat(item.mrp || 0),
            normal_selling_price_at_sale: parseFloat(item.selling_price || 0),
            promo_discount_amount: item.has_promotion ? parseFloat(item.promo_discount_amount || 0) : 0
        });
    }
    renderCart();
}

function renderCart() {
    const itemsEl = document.getElementById('pos-cart-items');
    const emptyMsg = document.getElementById('empty-cart-msg');
    
    // Clear list items only
    const children = Array.from(itemsEl.children);
    children.forEach(c => { if(c.id !== 'empty-cart-msg') itemsEl.removeChild(c); });

    if(cart.length === 0) {
        emptyMsg.style.display = 'block';
        calculateTotals();
        return;
    }
    emptyMsg.style.display = 'none';

    cart.forEach((c, i) => {
        const div = document.createElement('div');
        div.className = 'cart-item';
        const isMultiUnit = (parseInt(c.units_per_case || 1) > 1);
        let unitLabel = c.unit || (c.unit_type || 'PCS');
        if (!unitLabel || /^\d+$/.test(unitLabel)) {
            unitLabel = (c.unit === c.case_unit_name) ? (c.case_unit_name || 'CASE') : 'PCS';
        }
        const unitBadge = isMultiUnit 
            ? `<button type="button" onclick="toggleCartUnit(${i})" title="Click to switch unit (Case vs PCS)" style="font-size: 10px; font-weight: 800; background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; border-radius: 4px; padding: 2px 6px; cursor: pointer; margin-top: 2px;"><i class="fas fa-sync-alt" style="font-size: 9px; margin-right: 3px;"></i>${unitLabel}${c.conversion_factor > 1 ? ` (${c.conversion_factor} PCS)` : ''}</button>` 
            : `<span style="font-size: 10px; color: #64748b; font-weight: 600;">${unitLabel}</span>`;
        
        const normalPrice = parseFloat(c.normal_selling_price_at_sale || c.selling_price || 0);
        const isPromoItem = Boolean(c.has_promotion || c.promo_code);
        const promoDisc = parseFloat(c.promo_discount_amount || 0);
        const promoTag = isPromoItem
            ? `<div style="font-size:10px;color:#dc2626;font-weight:800;margin-top:2px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                <i class="fas fa-tag" style="font-size:9px;"></i>
                <span>PROMO: ${c.promo_code || ''}</span>
                ${normalPrice > 0 && normalPrice !== parseFloat(c.price) ? `<span style="color:#94a3b8;text-decoration:line-through;font-weight:500;">Rs ${normalPrice.toFixed(2)}</span>` : ''}
                ${promoDisc > 0 ? `<span style="background:#fef2f2;border-radius:3px;padding:0 3px;color:#b91c1c;">-Rs ${promoDisc.toFixed(2)}/unit</span>` : ''}
               </div>`
            : '';

        div.innerHTML = `
            <div class="cart-item-info" style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 6px;">
                <div style="flex: 1; min-width: 0;">
                    <div class="cart-item-name" style="font-size: 13px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin: 0;" title="${c.item_name || c.name || ''}">${c.item_name || c.name || ''}</div>
                    ${unitBadge}
                    ${promoTag}
                </div>
                <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                    <div class="cart-qty-ctrl" style="margin: 0; padding: 1px; display: flex; align-items: center;">
                        <button class="cart-qty-btn" style="width: 24px; height: 24px;" onclick="updateQty(${i}, -1)"><i class="fas fa-minus" style="font-size: 10px;"></i></button>
                        <input class="cart-qty-val" style="width: 25px; font-size: 12px;" type="number" value="${c.qty}" onchange="setQty(${i}, this.value)">
                        <button class="cart-qty-btn" style="width: 24px; height: 24px;" onclick="updateQty(${i}, 1)"><i class="fas fa-plus" style="font-size: 10px;"></i></button>
                    </div>
                    <div class="cart-price-ctrl" style="display: flex; align-items: center; gap: 2px;">
                        <span style="font-size: 11px; color: #64748b; font-weight: 600;">Rs</span>
                        <input style="width: 65px; text-align: right; border: 1px solid #cbd5e1; border-radius: 6px; padding: 2px 4px; font-size: 12px; font-weight: 700; color: #1e293b; background: #fff;" type="number" step="0.01" value="${parseFloat(c.price).toFixed(2)}" onchange="setPrice(${i}, this.value)">
                    </div>
                    <button class="cart-item-del" style="width: 26px; height: 26px; margin: 0; display: flex; align-items: center; justify-content: center;" onclick="removeLine(${i})"><i class="fas fa-trash" style="font-size: 12px;"></i></button>
                </div>
            </div>
        `;
        itemsEl.appendChild(div);
    });
    calculateTotals();
    renderGrid(document.getElementById('pos-search').value || '');
}

function setPrice(idx, val) {
    cart[idx].price = parseFloat(val) || 0;
    renderCart();
}

function toggleCartUnit(idx) {
    const c = cart[idx];
    const baseUnit   = c.unit_type || 'PCS';
    const caseUnit   = c.case_unit_name || 'CASE';
    const convFactor = parseInt(c.units_per_case || 1);
    
    if (c.unit === caseUnit || c.unit === 'CASE' || c.unit === 'BOX') {
        c.unit = baseUnit;
        c.conversion_factor = 1;
        c.price = parseFloat(c.selling_price || 0);
    } else {
        c.unit = caseUnit;
        c.conversion_factor = convFactor > 0 ? convFactor : 1;
        const caseSell = parseFloat(c.case_selling_price || 0);
        c.price = caseSell > 0 ? caseSell : Math.round(parseFloat(c.selling_price || 0) * c.conversion_factor * 100) / 100;
    }
    renderCart();
}

function updateQty(idx, delta) {
    cart[idx].qty += delta;
    if(cart[idx].qty <= 0) cart.splice(idx, 1);
    renderCart();
}

function setQty(idx, val) {
    cart[idx].qty = parseFloat(val) || 0;
    if(cart[idx].qty <= 0) cart.splice(idx, 1);
    renderCart();
}

function removeLine(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function calculateTotals() {
    let subtotal = 0;
    cart.forEach(c => subtotal += (c.qty * c.price));

    // Calculate total promo discount across all promotional items
    let promoDiscTotal = 0;
    cart.forEach(c => {
        if (c.has_promotion || c.promo_code) {
            promoDiscTotal += parseFloat(c.promo_discount_amount || 0) * parseFloat(c.qty || 1);
        }
    });
    const promoDiscLine = document.getElementById('promo-disc-line');
    const promoDiscText = document.getElementById('txt-promo-disc');
    if (promoDiscLine && promoDiscText) {
        if (promoDiscTotal > 0) {
            promoDiscLine.style.display = 'flex';
            promoDiscText.innerText = '- Rs ' + promoDiscTotal.toFixed(2);
        } else {
            promoDiscLine.style.display = 'none';
        }
    }
    
    const discType = document.getElementById('discount-type').value;
    const discVal = parseFloat(document.getElementById('discount-val').value) || 0;
    let discAmount = discType === 'percentage' ? (subtotal * discVal / 100) : discVal;
    
    const includeTax = document.getElementById('include-tax').checked;
    const taxable = subtotal - discAmount;
    let taxAmount = 0;
    
    if (includeTax) {
        cart.forEach(c => {
            // Proportionate tax calculation
            const lineSub = c.qty * c.price;
            const lineTaxable = lineSub - (discAmount * (lineSub / (subtotal||1)));
            taxAmount += lineTaxable * (parseFloat(c.tax_rate||0) / 100);
        });
    }

    document.getElementById('txt-subtotal').innerText = 'Rs ' + subtotal.toFixed(2);
    document.getElementById('tax-amount-val').value = taxAmount.toFixed(2);
    
    updateNetTotal();
}

function updateNetTotal() {
    const subtotal = parseFloat(document.getElementById('txt-subtotal').innerText.replace('Rs ', '')) || 0;
    const discType = document.getElementById('discount-type').value;
    const discVal = parseFloat(document.getElementById('discount-val').value) || 0;
    let discAmount = discType === 'percentage' ? (subtotal * discVal / 100) : discVal;
    
    const taxAmount = parseFloat(document.getElementById('tax-amount-val').value) || 0;
    const net = (subtotal - discAmount) + taxAmount;

    document.getElementById('txt-total').innerText = 'Rs ' + net.toFixed(2);
    
    // Auto-fill payment if only one line (sync with net)
    if(payments.length === 1) {
        payments[0].amount = net;
        renderPayments();
        return; 
    }
    
    calculateChange();
}

function addPaymentLine() {
    if(accounts.length > 0) {
        payments.push({ account_id: accounts[0].id, amount: 0, mode: accounts[0].account_subtype || 'cash' });
        renderPayments();
    }
}

function renderPayments() {
    const container = document.getElementById('payment-lines');
    container.innerHTML = '';
    
    payments.forEach((p, i) => {
        const accOptions = accounts.map(acc => 
            `<option value="${acc.id}" ${acc.id === p.account_id ? 'selected' : ''}>${acc.account_name}</option>`
        ).join('');

        const div = document.createElement('div');
        div.className = 'pay-row';
        div.innerHTML = `
            <select class="pay-select" style="flex: 3;" onchange="updatePayAcc(${i}, this.value)">
                ${accOptions}
            </select>
            <input type="number" class="pay-amount" value="${p.amount.toFixed(2)}" step="0.01" onfocus="this.select()" oninput="updatePayAmt(${i}, this.value)">
            ${payments.length > 1 ? `<button class="cart-item-del" style="height: 35px; width: 35px;" onclick="removePayLine(${i})"><i class="fas fa-times"></i></button>` : ''}
        `;
        container.appendChild(div);
    });
    calculateChange();
}

function updatePayAcc(idx, val) {
    payments[idx].account_id = val;
    // Derive mode from selected account's actual subtype
    const acc = accounts.find(a => a.id === val);
    payments[idx].mode = acc ? (acc.account_subtype || 'cash') : 'cash';
    calculateChange();
}

function updatePayAmt(idx, val) {
    payments[idx].amount = parseFloat(val) || 0;
    calculateChange();
}

function removePayLine(idx) {
    payments.splice(idx, 1);
    renderPayments();
}

function hasNonCashPayment() {
    return payments.some(p => {
        if (!p.account_id) return false;
        return String(p.account_id) !== String(defaultCashAccountId);
    });
}

function calculateChange() {
    const net = parseFloat(document.getElementById('txt-total').innerText.replace('Rs ', '')) || 0;
    let paid = 0;
    payments.forEach(p => paid += p.amount);
    
    const change = paid - net;
    const el = document.getElementById('change-due');
    el.innerText = change.toFixed(2);
    el.classList.toggle('negative', change < -0.01);
    
    document.getElementById('btn-checkout').disabled = (change < -0.01 || cart.length === 0);

    const qrBtnContainer = document.getElementById('qr-btn-container');
    if (qrBtnContainer) {
        qrBtnContainer.style.display = hasNonCashPayment() ? 'block' : 'none';
    }
}

function showPosQrModal() {
    const net = parseFloat(document.getElementById('txt-total').innerText.replace('Rs ', '')) || 0;
    let nonCashAmount = 0;
    let cashAmount = 0;
    payments.forEach(p => {
        if (p.account_id) {
            if (String(p.account_id) !== String(defaultCashAccountId)) {
                nonCashAmount += (parseFloat(p.amount) || 0);
            } else {
                cashAmount += (parseFloat(p.amount) || 0);
            }
        }
    });

    let qrAmount = 0;
    if (nonCashAmount > 0) {
        qrAmount = nonCashAmount;
    } else if (cashAmount > 0 && net > cashAmount) {
        qrAmount = net - cashAmount;
    } else {
        qrAmount = net;
    }

    const modal = document.getElementById('pos-qr-modal');
    document.getElementById('pos-qr-img').src = 'https://api.qrserver.com/v1/create-qr-code/?size=210x210&margin=0&data=Loading...';
    document.getElementById('pos-qr-amount-txt').innerText = 'Rs ' + qrAmount.toFixed(2);
    modal.style.display = 'flex';

    fetch(`api/get_qr_code.php?amount=${qrAmount}&txn_no=<?php echo $txn_number; ?>`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('pos-qr-img').src = res.qr_src;
                document.getElementById('pos-qr-company-name').innerText = res.company_name;
                document.getElementById('pos-qr-amount-txt').innerText = res.formatted_amount;
            }
        })
        .catch(err => console.error('Error fetching QR code:', err));
}

function closePosQrModal() {
    document.getElementById('pos-qr-modal').style.display = 'none';
}

function completeSale(forceSave = false) {
    if (document.getElementById('btn-checkout').disabled && !forceSave) return;
    
    // Check if any cart item has 0 price
    const zeroPriceItems = cart.filter(c => parseFloat(c.price || 0) === 0);
    if (zeroPriceItems.length > 0 && !forceSave) {
        if (!confirm("Warning: Selling price is not set for some items in the cart. Do you want to proceed?")) {
            return;
        }
    }
    
    closePosErrorModal();

    const btn = document.getElementById('btn-checkout');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Transaction...';

    const payload = {
        id: posId || null,
        txn_number: '<?php echo $txn_number; ?>',
        txn_date: '<?php echo $txn_date; ?>',
        gross_amount: parseFloat(document.getElementById('txt-subtotal').innerText.replace('Rs ', '')),
        discount_type: document.getElementById('discount-type').value,
        discount_value: parseFloat(document.getElementById('discount-val').value) || 0,
        discount_amount: parseFloat(document.getElementById('txt-subtotal').innerText.replace('Rs ', '')) - parseFloat(document.getElementById('txt-total').innerText.replace('Rs ', '')) + parseFloat(document.getElementById('tax-amount-val').value),
        tax_amount: parseFloat(document.getElementById('tax-amount-val').value),
        net_amount: parseFloat(document.getElementById('txt-total').innerText.replace('Rs ', '')),
        include_tax: document.getElementById('include-tax').checked,
        force_save: forceSave ? true : false,
        items: cart.map(c => {
            const lineSub = c.qty * c.price;
            const lineDisc = (lineSub / (parseFloat(document.getElementById('txt-subtotal').innerText.replace('Rs ', '')) || 1)) * (parseFloat(document.getElementById('discount-val').value) || 0); // Simplified disc
            const isTaxable = document.getElementById('include-tax').checked;
            const tax = isTaxable ? (lineSub - lineDisc) * (parseFloat(c.tax_rate||0)/100) : 0;
            return {
                id: c.id,
                qty: c.qty,
                unit: c.unit || 'PCS',
                conversion_factor: c.conversion_factor || 1,
                base_qty: c.qty * (c.conversion_factor || 1),
                price: parseFloat(c.price),
                tax: tax,
                net: lineSub - lineDisc + tax,
                promotion_id: c.promotion_id || null,
                promo_code: c.promo_code || null,
                mrp_at_sale: parseFloat(c.mrp_at_sale || c.mrp || 0),
                normal_selling_price_at_sale: parseFloat(c.normal_selling_price_at_sale || c.selling_price || 0),
                promo_discount_amount: parseFloat(c.promo_discount_amount || 0)
            };
        }),
        payments: payments.filter(p => p.amount > 0),
        customer_id: null
    };

    let httpStatusCode = 200;
    fetch('api/save_pos.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => {
        httpStatusCode = r.status;
        return r.json();
    })
    .then(res => {
        if (httpStatusCode === 200 && res.status === 'success') {
            nsNotify('Sale Saved Successfully! Transaction: ' + res.txn_number);

            const resPosId = res.pos_id || posId || '';
            const invoiceNo = res.txn_number || '';
            
            const shouldPrint = confirm('Want invoice print?');
            if (shouldPrint) {
                if (resPosId) {
                    window.open('api/print_pos.php?id=' + resPosId, '_blank');
                } else if (invoiceNo) {
                    window.open('api/print_pos.php?invoice_no=' + invoiceNo, '_blank');
                }
            }

            setTimeout(() => location.href = '?page=transactions/pos', 500);
        } else {
            const errText = res.message || 'Transaction failed';
            const isStockWarning = errText.toLowerCase().includes('stock warning');
            showPosErrorModal(errText, isStockWarning);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-double"></i> Complete Sale (F10)';
        }
    })
    .catch(err => {
        showPosErrorModal(err.message || 'Network / Server Error', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-double"></i> Complete Sale (F10)';
    });
}

function showPosErrorModal(message, isStockWarning = false) {
    const modal = document.getElementById('pos-error-modal');
    const msgEl = document.getElementById('pos-err-msg');
    const forceBtn = document.getElementById('btn-force-save-pos');
    const titleEl = document.getElementById('pos-err-title');
    const subEl = document.getElementById('pos-err-subtext');

    if (isStockWarning) {
        titleEl.textContent = 'Insufficient Stock Warning';
        subEl.textContent = 'The requested quantity exceeds available location stock.';
        if (forceBtn) forceBtn.style.display = 'inline-flex';
    } else {
        titleEl.textContent = 'Transaction Failed';
        subEl.textContent = 'An error occurred while saving the POS transaction.';
        if (forceBtn) forceBtn.style.display = 'none';
    }

    msgEl.textContent = message;
    modal.style.display = 'flex';
}

function closePosErrorModal() {
    const modal = document.getElementById('pos-error-modal');
    if (modal) modal.style.display = 'none';
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
</script>

<!-- POS Error / Stock Warning Modal -->
<div id="pos-error-modal" style="display:none; position:fixed; z-index:10008; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); justify-content:center; align-items:center; backdrop-filter:blur(3px);">
    <div style="background:#fff; border-radius:12px; box-shadow:0 20px 40px rgba(0,0,0,0.3); width:520px; max-width:92%; font-family:inherit; overflow:hidden;">
        <div style="background:linear-gradient(135deg,#dc2626,#b91c1c); padding:18px 22px; color:#fff; display:flex; align-items:center; gap:12px;">
            <i class="fas fa-exclamation-triangle" style="font-size:24px;"></i>
            <div>
                <div id="pos-err-title" style="font-size:16px; font-weight:700;">Stock Warning</div>
                <div id="pos-err-subtext" style="font-size:11px; opacity:0.85; margin-top:2px;">Attention required before finalizing sale</div>
            </div>
            <button onclick="closePosErrorModal()" style="margin-left:auto; background:rgba(255,255,255,0.2); border:none; color:#fff; width:28px; height:28px; border-radius:50%; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center;">&times;</button>
        </div>
        <div style="padding:22px;">
            <div id="pos-err-msg" style="font-size:13px; color:#1e293b; font-weight:600; line-height:1.6; background:#fef2f2; border-left:4px solid #ef4444; padding:14px 16px; border-radius:6px; margin-bottom:12px;"></div>
            <p style="font-size:12px; color:#64748b; margin:0;">You can edit your cart quantities or click <strong>Force Save</strong> to override stock checks and complete this transaction anyway.</p>
        </div>
        <div style="background:#f8fafc; padding:14px 22px; display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0;">
            <button onclick="closePosErrorModal()" class="ns-btn" style="padding:8px 20px; font-weight:600;"><i class="fas fa-pencil-alt" style="margin-right:5px;"></i> Edit Cart</button>
            <button id="btn-force-save-pos" onclick="completeSale(true)" class="ns-btn ns-btn-primary" style="background:#dc2626; border-color:#dc2626; padding:8px 20px; font-weight:700; display:none;"><i class="fas fa-bolt" style="margin-right:5px;"></i> Force Save & Complete</button>
        </div>
    </div>
</div>
