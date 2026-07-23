<?php
require_once 'database/DBConnection.php';
$db = db();
$id = $_GET['id'] ?? null;
$copy_from = $_GET['copy_from'] ?? null;
$data = [];
$details = [];
$is_copy = false;

if ($id) {
    // Editing an existing record
    $data = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
    $details = $db->fetchOne("SELECT * FROM cash_denominations WHERE header_id = ?", [$id]);
} elseif ($copy_from) {
    // Copying from an existing record
    $source_header = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$copy_from]);
    $source_details = $db->fetchOne("SELECT * FROM cash_denominations WHERE header_id = ?", [$copy_from]);
    
    if ($source_header && $source_details) {
        $is_copy = true;
        // Auto-advance shift: Shift_A → Shift_B, Shift_B → Shift_A, Main → Main
        $source_shift = $source_header['party_id'] ?? 'Main';
        if ($source_shift === 'Shift_A') {
            $next_shift = 'Shift_B';
        } elseif ($source_shift === 'Shift_B') {
            $next_shift = 'Shift_A';
        } else {
            $next_shift = 'Main';
        }
        
        $data = [
            'txn_number' => 'CD-' . date('Ymd') . '-' . rand(1000, 9999),
            'txn_date' => date('Y-m-d'),
            'net_amount' => $source_details['total_cash'] ?? 0,
            'party_id' => $next_shift
        ];
        // Copy denomination counts from source
        $details = $source_details;
    } else {
        // Source not found, treat as new
        $data = [
            'txn_number' => 'CD-' . date('Ymd') . '-' . rand(1000, 9999),
            'txn_date' => date('Y-m-d'),
            'net_amount' => 0,
            'party_id' => 'Main'
        ];
    }
} else {
    // Brand new record
    $data = [
        'txn_number' => 'CD-' . date('Ymd') . '-' . rand(1000, 9999),
        'txn_date' => date('Y-m-d'),
        'net_amount' => 0,
        'party_id' => 'Main'
    ];
}
?>
<div class="ns-form-header">
    <div class="ns-form-title"><?php echo $id ? 'Edit' : ($is_copy ? 'Copy' : 'New'); ?> Cash Denomination</div>
    <div class="ns-page-actions">
        <button type="button" onclick="submitCashDenom()" class="ns-btn ns-btn-primary">Save</button>
        <?php if ($id): ?>
        <button type="button" class="ns-btn" style="color: #e74c3c; border-color: #fbcbc5; background: #fdf2f1;" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/cash_denom')"><i class="fas fa-trash-alt"></i> Delete</button>
        <button type="button" onclick="copyToNew()" class="ns-btn" style="background: #f39c12; color: #fff; border-color: #e67e22;" title="Copy all data to a new denomination entry">
            <i class="fas fa-copy"></i> Copy to New
        </button>
        <?php endif; ?>
        <a href="?page=transactions/cash_denom" class="ns-btn">Cancel</a>
    </div>
</div>

<div class="ns-form-container">
    <form id="cash-denom-form">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="net_amount" id="net_amount" value="<?php echo $data['net_amount']; ?>">
        
        <div class="ns-section-title">Session Information</div>
        <div class="ns-form-row">
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Date *</label>
                    <input type="date" name="txn_date" class="ns-input" value="<?php echo $data['txn_date']; ?>" required>
                </div>
            </div>
            <div style="flex: 1;">
                <div class="ns-form-group">
                    <label class="ns-label">Counter/Shift</label>
                    <select name="party_id" class="ns-select">
                        <option value="Main" <?php echo ($data['party_id'] == 'Main') ? 'selected' : ''; ?>>Main Counter</option>
                        <option value="Shift_A" <?php echo ($data['party_id'] == 'Shift_A') ? 'selected' : ''; ?>>Morning Shift</option>
                        <option value="Shift_B" <?php echo ($data['party_id'] == 'Shift_B') ? 'selected' : ''; ?>>Evening Shift</option>
                    </select>
                </div>
            </div>
        </div>

        <?php if ($is_copy): ?>
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px 16px; margin: 10px 0 15px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-info-circle" style="color: #856404; font-size: 16px;"></i>
            <span style="font-size: 12px; color: #856404; font-weight: 600;">
                Copied from previous denomination. Review counts and click Save to create a new entry.
            </span>
        </div>
        <?php endif; ?>

        <div class="ns-section-title">Denominations</div>
        <table class="ns-item-table" style="max-width: 600px; margin: 15px auto;">
            <thead>
                <tr>
                    <th>Note / Coin</th>
                    <th width="150">Count</th>
                    <th width="200">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $denoms = [
                    ['val' => 1000, 'key' => 'note_1000'],
                    ['val' => 500,  'key' => 'note_500'],
                    ['val' => 100,  'key' => 'note_100'],
                    ['val' => 50,   'key' => 'note_50'],
                    ['val' => 20,   'key' => 'note_20'],
                    ['val' => 10,   'key' => 'note_10'],
                    ['val' => 5,    'key' => 'coin_5'],
                    ['val' => 2,    'key' => 'coin_2'],
                    ['val' => 1,    'key' => 'coin_1'],
                ];
                foreach ($denoms as $d):
                    $count = $details[$d['key']] ?? 0;
                ?>
                <tr>
                    <td style="font-weight: bold; text-align: right; padding-right: 20px;">NPR <?php echo $d['val']; ?></td>
                    <td>
                        <input type="number" name="<?php echo $d['key']; ?>" 
                               class="ns-input denom-count" 
                               data-value="<?php echo $d['val']; ?>"
                               style="width: 100px; text-align: center;" 
                               value="<?php echo $count; ?>"
                               oninput="calculateDenom(this)"
                               onkeyup="calculateDenom(this)"
                               onchange="calculateDenom(this)">
                    </td>
                    <td>
                        <input type="number" class="ns-input denom-amount" 
                               value="<?php echo number_format($count * $d['val'], 2, '.', ''); ?>" 
                               readonly style="text-align: right; background: #f8f9fa;">
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #e0e6ef; font-weight: bold;">
                    <td colspan="2" style="text-align: right;">Total Cash</td>
                    <td style="text-align: right;" id="total-cash-display"><?php echo number_format($data['net_amount'], 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Recalculate row amounts and grand total on page load
    document.querySelectorAll('.denom-count').forEach(input => {
        calculateDenom(input);
    });
    updateGrandTotal();
});

function calculateDenom(input) {
    const row = input.closest('tr');
    const val = parseFloat(input.dataset.value);
    const count = parseInt(input.value) || 0;
    const amountInput = row.querySelector('.denom-amount');
    
    const amount = val * count;
    amountInput.value = amount.toFixed(2);
    
    updateGrandTotal();
}

function updateGrandTotal() {
    let total = 0;
    document.querySelectorAll('.denom-amount').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    
    document.getElementById('total-cash-display').innerText = total.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('net_amount').value = total;
}

function copyToNew() {
    // Clear the ID so it saves as a new record
    const form = document.getElementById('cash-denom-form');
    form.querySelector('input[name="id"]').value = '';
    
    // Update date to today
    const today = new Date().toISOString().split('T')[0];
    form.querySelector('input[name="txn_date"]').value = today;
    
    // Auto-advance shift
    const shiftSelect = form.querySelector('select[name="party_id"]');
    if (shiftSelect.value === 'Shift_A') {
        shiftSelect.value = 'Shift_B';
    } else if (shiftSelect.value === 'Shift_B') {
        shiftSelect.value = 'Shift_A';
    }
    
    // Update form title
    document.querySelector('.ns-form-title').innerText = 'Copy Cash Denomination';
    
    // Show notification
    nsNotify('Data copied! Modify values and click Save to create a new entry.', 'info');
}

function submitCashDenom() {
    // Force recalculation of grand total right before building payload
    document.querySelectorAll('.denom-count').forEach(input => {
        calculateDenom(input);
    });
    updateGrandTotal();

    const form = document.getElementById('cash-denom-form');
    const formData = new FormData(form);
    
    // Simple conversion for fetch
    const payload = {};
    formData.forEach((value, key) => { payload[key] = value; });

    fetch('api/save_cash_denom.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            nsNotify(res.message);
            setTimeout(() => { location.href = '?page=transactions/cash_denom'; }, 1000);
        } else {
            nsNotify(res.message || 'Error saving transaction', 'error');
        }
    })
    .catch(err => nsNotify('Network error', 'error'));
}
</script>
