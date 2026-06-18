<?php
require_once 'database/DBConnection.php';
$db = db();

// Fetch active items with category names
$items = $db->fetchAll("SELECT i.id, i.sku, i.item_name, r.name as category_name, i.selling_price, i.tax_rate 
    FROM items i 
    LEFT JOIN reference_codes r ON i.item_category = r.id AND r.type = 'category'
    WHERE i.is_active = 1 AND i.is_deleted = 0 
    ORDER BY i.item_name ASC");

// Fetch bank accounts for payment (including those converted from cash)
$payment_accounts = $db->fetchAll("SELECT id, account_name, account_subtype FROM accounts WHERE account_subtype = 'bank' AND is_active = 1 ORDER BY account_name ASC");

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

    .pos-grid { padding: 15px; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; overflow-y: auto; flex: 1; align-content: start; }
    .pos-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; text-align: center; cursor: pointer; transition: 0.2s; display: flex; flex-direction: column; justify-content: space-between; min-height: 120px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .pos-card:hover { border-color: var(--ns-primary); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,85,170,0.08); }
    .pos-card-name { font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 8px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .pos-card-price { font-size: 15px; font-weight: 700; color: var(--ns-accent); }
    .pos-card-sku { font-size: 11px; color: #94a3b8; margin-top: 4px; font-weight: 500; }

    /* Right: Cart & Payment */
    .pos-sidebar { flex: 4; display: flex; flex-direction: column; background: #fff; border-left: 1px solid #e0e6ed; min-width: 400px; max-width: 500px; box-shadow: -4px 0 15px rgba(0,0,0,0.03); }
    .pos-cart-hdr { padding: 18px 20px; background: var(--ns-primary); color: #fff; display: flex; justify-content: space-between; align-items: center; }
    .pos-cart-hdr h2 { font-size: 18px; margin: 0; font-weight: 700; }
    .pos-cart-items { flex: 1; overflow-y: auto; padding: 10px; background: #fafbfc; }
    
    .cart-item { display: flex; align-items: center; padding: 12px; background: #fff; border: 1px solid #e2e8f0; margin-bottom: 8px; border-radius: 10px; position: relative; }
    .cart-item-info { flex: 1; min-width: 0; }
    .cart-item-name { font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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
    .pay-select { flex: 2; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; }
    .pay-amount { flex: 1; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right; font-weight: 700; font-size: 14px; }
    
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
            <h2><i class="fas fa-shopping-basket"></i> POS Cart</h2>
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
            <div class="pos-summary">
                <div class="pos-summary-line">
                    <span>Subtotal</span>
                    <span id="txt-subtotal">Rs 0.00</span>
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; border-top: 1px solid #e2e8f0; padding-top: 10px;">
                    <span class="change-label">Change Due</span>
                    <span id="change-due" class="change-value">0.00</span>
                </div>
            </div>

            <button id="btn-checkout" class="pos-action-btn" onclick="completeSale()" disabled>
                <i class="fas fa-check-double"></i> Complete Sale (F10)
            </button>
        </div>
    </div>
</div>

<script>
const items = <?php echo json_encode($items); ?>;
const accounts = <?php echo json_encode($payment_accounts); ?>;
let cart = [];
let payments = [];
let activeCat = 'all';

function init() {
    renderGrid();
    addPaymentLine(); // Initial payment line
    
    // Search listener
    document.getElementById('pos-search').addEventListener('input', (e) => {
        renderGrid(e.target.value);
        // Simulate barcode scanner (if search matches exactly one SKU, add it)
        const match = items.filter(i => i.sku && i.sku.toLowerCase() === e.target.value.toLowerCase());
        if(match.length === 1 && e.target.value.length > 3) {
            addToCart(match[0]);
            e.target.value = '';
            renderGrid();
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

function renderGrid(search = '') {
    const grid = document.getElementById('pos-grid');
    grid.innerHTML = '';
    
    const filtered = items.filter(i => {
        const itemCat = i.category_name || 'Other';
        const matchCat = (activeCat === 'all' || itemCat === activeCat);
        const matchSearch = i.item_name.toLowerCase().includes(search.toLowerCase()) || (i.sku && i.sku.toLowerCase().includes(search.toLowerCase()));
        return matchCat && matchSearch;
    });

    filtered.forEach(item => {
        const div = document.createElement('div');
        div.className = 'pos-card';
        div.onclick = () => addToCart(item);
        div.innerHTML = `
            <div class="pos-card-name">${item.item_name}</div>
            <div>
                <div class="pos-card-price">Rs ${parseFloat(item.selling_price).toFixed(2)}</div>
                <div class="pos-card-sku">${item.sku || '-'}</div>
            </div>
        `;
        grid.appendChild(div);
    });
}

function filterCategory(cat) {
    activeCat = cat;
    document.querySelectorAll('.pos-cat-btn').forEach(b => b.classList.toggle('active', b.innerText.toLowerCase() === cat.toLowerCase() || (cat === 'all' && b.innerText.includes('All'))));
    renderGrid();
}

function addToCart(item) {
    const idx = cart.findIndex(c => c.id === item.id);
    if(idx > -1) {
        cart[idx].qty += 1;
    } else {
        cart.push({ ...item, qty: 1, discount: 0 });
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
        div.innerHTML = `
            <div class="cart-item-info">
                <div class="cart-item-name">${c.item_name}</div>
                <div class="cart-item-meta">
                    <span>Rs ${parseFloat(c.selling_price).toFixed(2)} / unit</span>
                </div>
            </div>
            <div class="cart-qty-ctrl">
                <button class="cart-qty-btn" onclick="updateQty(${i}, -1)"><i class="fas fa-minus"></i></button>
                <input class="cart-qty-val" type="number" value="${c.qty}" onchange="setQty(${i}, this.value)">
                <button class="cart-qty-btn" onclick="updateQty(${i}, 1)"><i class="fas fa-plus"></i></button>
            </div>
            <div class="cart-item-total">Rs ${(c.qty * c.selling_price).toFixed(2)}</div>
            <button class="cart-item-del" onclick="removeLine(${i})"><i class="fas fa-trash"></i></button>
        `;
        itemsEl.appendChild(div);
    });
    calculateTotals();
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
    cart.forEach(c => subtotal += (c.qty * c.selling_price));
    
    const discType = document.getElementById('discount-type').value;
    const discVal = parseFloat(document.getElementById('discount-val').value) || 0;
    let discAmount = discType === 'percentage' ? (subtotal * discVal / 100) : discVal;
    
    const includeTax = document.getElementById('include-tax').checked;
    const taxable = subtotal - discAmount;
    let taxAmount = 0;
    
    if (includeTax) {
        cart.forEach(c => {
            // Proportionate tax calculation
            const lineTaxable = (c.qty * c.selling_price) - (discAmount * ((c.qty * c.selling_price) / (subtotal||1)));
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
    // Default mode to bank since selection is removed
    payments[idx].mode = 'bank';
}

function updatePayAmt(idx, val) {
    payments[idx].amount = parseFloat(val) || 0;
    calculateChange();
}

function removePayLine(idx) {
    payments.splice(idx, 1);
    renderPayments();
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
}

function completeSale() {
    if(document.getElementById('btn-checkout').disabled) return;
    
    const btn = document.getElementById('btn-checkout');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Transaction...';

    const payload = {
        txn_number: '<?php echo $txn_number; ?>',
        txn_date: '<?php echo $txn_date; ?>',
        gross_amount: parseFloat(document.getElementById('txt-subtotal').innerText.replace('Rs ', '')),
        discount_type: document.getElementById('discount-type').value,
        discount_value: parseFloat(document.getElementById('discount-val').value) || 0,
        discount_amount: parseFloat(document.getElementById('txt-subtotal').innerText.replace('Rs ', '')) - parseFloat(document.getElementById('txt-total').innerText.replace('Rs ', '')) + parseFloat(document.getElementById('tax-amount-val').value),
        tax_amount: parseFloat(document.getElementById('tax-amount-val').value),
        net_amount: parseFloat(document.getElementById('txt-total').innerText.replace('Rs ', '')),
        include_tax: document.getElementById('include-tax').checked,
        items: cart.map(c => {
            const lineSub = c.qty * c.selling_price;
            const lineDisc = (lineSub / (parseFloat(document.getElementById('txt-subtotal').innerText.replace('Rs ', '')) || 1)) * (parseFloat(document.getElementById('discount-val').value) || 0); // Simplified disc
            const isTaxable = document.getElementById('include-tax').checked;
            const tax = isTaxable ? (lineSub - lineDisc) * (parseFloat(c.tax_rate||0)/100) : 0;
            return {
                id: c.id,
                qty: c.qty,
                price: parseFloat(c.selling_price),
                tax: tax,
                net: lineSub - lineDisc + tax
            };
        }),
        payments: payments.filter(p => p.amount > 0),
        customer_id: null // Can add customer selector later
    };

    fetch('api/save_pos.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') {
            nsNotify('Sale Completed! Transaction: ' + res.txn_number);
            setTimeout(() => location.reload(), 1500);
        } else {
            nsNotify('Error: ' + res.message, 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-double"></i> Complete Sale (F10)';
        }
    })
    .catch(err => {
        nsNotify('Network Error', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-double"></i> Complete Sale (F10)';
    });
}

document.addEventListener('DOMContentLoaded', init);
</script>
