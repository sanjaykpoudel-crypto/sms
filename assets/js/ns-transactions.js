/**
 * NetSuite Transactions UI Logic
 * Handles dynamic grid interactions and front-end calculations
 */

function calculateLine(input, source) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('.qty-input')?.value) || 0;
    const taxPct = parseFloat(row.querySelector('.tax-pct-input')?.value) || 0;
    const amountInput = row.querySelector('.amount-input');
    const rateInput = row.querySelector('.rate-input');
    const taxAmtInput = row.querySelector('.tax-amt-input');

    if (source === 'amount') {
        // Back-calculate rate from amount
        const total = parseFloat(amountInput?.value) || 0;
        if (qty > 0 && rateInput) {
            const rate = total / (qty * (1 + taxPct / 100));
            rateInput.value = rate.toFixed(2);
            if (taxAmtInput) taxAmtInput.value = (qty * rate * (taxPct / 100)).toFixed(2);
        }
    } else {
        // Default: calculate amount from rate
        const rate = parseFloat(rateInput?.value) || 0;
        const subtotal = qty * rate;
        const taxAmt = subtotal * (taxPct / 100);
        if (amountInput) amountInput.value = (subtotal + taxAmt).toFixed(2);
        if (taxAmtInput) taxAmtInput.value = taxAmt.toFixed(2);
    }

    // Trigger total recalculation
    if (typeof calculateBillTotals === 'function') {
        calculateBillTotals();
    } else if (typeof updateTotals === 'function') {
        updateTotals();
    } else if (typeof calculateInvoiceTotals === 'function') {
        calculateInvoiceTotals();
    }
}

function nsAddLine(tableId) {
    const table = document.getElementById(tableId);
    const tbody = table.querySelector('tbody');
    const rowCount = tbody.rows.length;
    const firstRow = tbody.rows[0];
    const newRow = firstRow.cloneNode(true);
    
    // Clear inputs in new row
    newRow.cells[0].innerText = rowCount + 1;
    newRow.querySelectorAll('input').forEach(input => {
        if (!input.readOnly) input.value = input.defaultValue || '';
        if (input.classList.contains('qty-input')) input.value = 1;
        if (input.classList.contains('rate-input') || input.classList.contains('amount-input') || input.classList.contains('profit-input') || input.classList.contains('tax-amt-input') || input.classList.contains('gross-amount-input') || input.classList.contains('cost-input') || input.classList.contains('stock-input') || input.classList.contains('new-stock-input')) {
            input.value = (input.classList.contains('stock-input') || input.classList.contains('cost-input') || input.classList.contains('profit-input') || input.classList.contains('new-stock-input')) ? '' : '0.00';
        }
    });
    
    // Clear selects
    newRow.querySelectorAll('select').forEach(select => {
        select.selectedIndex = 0;
    });
    
    tbody.appendChild(newRow);
    updateRowNumbers(tbody);
}

function nsInsertLine(btn) {
    const currentRow = btn.closest('tr');
    const tbody = currentRow.parentNode;
    const newRow = tbody.rows[0].cloneNode(true);
    
    // Clear inputs
    newRow.querySelectorAll('input').forEach(input => {
        if (!input.readOnly) input.value = '';
        if (input.classList.contains('qty-input')) input.value = 1;
        if (input.classList.contains('rate-input') || input.classList.contains('amount-input') || input.classList.contains('profit-input') || input.classList.contains('tax-amt-input') || input.classList.contains('gross-amount-input') || input.classList.contains('cost-input') || input.classList.contains('stock-input') || input.classList.contains('new-stock-input')) {
            input.value = (input.classList.contains('stock-input') || input.classList.contains('cost-input') || input.classList.contains('profit-input') || input.classList.contains('new-stock-input')) ? '' : '0.00';
        }
    });
    
    tbody.insertBefore(newRow, currentRow);
    updateRowNumbers(tbody);
}

function nsRemoveLine(btn) {
    const row = btn.closest('tr');
    const tbody = row.parentNode;
    
    if (tbody.rows.length > 1) {
        row.remove();
        updateRowNumbers(tbody);
        if (typeof updateTotals === 'function') updateTotals();
        if (typeof calculateInvoiceTotals === 'function') calculateInvoiceTotals();
    } else {
        alert('Transaction must have at least one line.');
    }
}

function nsClearLines(tableId) {
    if (confirm('Are you sure you want to clear all lines?')) {
        const tbody = document.getElementById(tableId).querySelector('tbody');
        while (tbody.rows.length > 1) {
            tbody.deleteRow(1);
        }
        // Clear first row
        const firstRow = tbody.rows[0];
        firstRow.querySelectorAll('input').forEach(input => {
            if (!input.readOnly) input.value = '';
            if (input.classList.contains('qty-input')) input.value = 1;
            if (input.classList.contains('rate-input') || input.classList.contains('amount-input') || input.classList.contains('profit-input') || input.classList.contains('tax-amt-input') || input.classList.contains('gross-amount-input') || input.classList.contains('cost-input') || input.classList.contains('stock-input') || input.classList.contains('new-stock-input')) {
                input.value = (input.classList.contains('stock-input') || input.classList.contains('cost-input') || input.classList.contains('profit-input') || input.classList.contains('new-stock-input')) ? '' : '0.00';
            }
        });
        updateRowNumbers(tbody);
        if (typeof updateTotals === 'function') updateTotals();
        if (typeof calculateInvoiceTotals === 'function') calculateInvoiceTotals();
    }
}

function updateRowNumbers(tbody) {
    Array.from(tbody.rows).forEach((row, index) => {
        row.cells[0].innerText = index + 1;
    });
}

// Default updateTotals if not overridden
function updateTotals() {
    if (document.getElementById('grand-total')) {
        let subtotal = 0;
        document.querySelectorAll('.amount-input').forEach(input => {
            subtotal += parseFloat(input.value) || 0;
        });
        
        const subtotalEl = document.getElementById('subtotal');
        const taxEl = document.getElementById('tax-total');
        const totalEl = document.getElementById('grand-total');
        
        if (subtotalEl) subtotalEl.innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits: 2});
        
        if (taxEl) {
            const tax = subtotal * 0.13;
            taxEl.innerText = tax.toLocaleString(undefined, {minimumFractionDigits: 2});
            if (totalEl) totalEl.innerText = (subtotal + tax).toLocaleString(undefined, {minimumFractionDigits: 2});
        } else {
            if (totalEl) totalEl.innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits: 2});
        }
    }
}

function nsDeleteTransaction(id, redirectUrl) {
    if (!id) return;
    if (confirm("Are you sure you want to delete this transaction? This will mark it as void/deleted.")) {
        fetch('api/transaction_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete',
                table: 'transaction_headers',
                primary_key: 'id',
                primary_value: id
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                nsNotify('Transaction deleted successfully.');
                setTimeout(() => { 
                    window.location.href = redirectUrl || '?page=transactions'; 
                }, 1500);
            } else {
                nsNotify(data.message || 'Delete failed', 'error');
            }
        })
        .catch(err => {
            nsNotify('Network error', 'error');
        });
    }
}
