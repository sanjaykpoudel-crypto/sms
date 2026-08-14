<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';
$db = db();
$id = $_GET['id'] ?? null;
$data = [];
$payment_lines = [];

if ($id) {
    $data = $db->fetchOne("SELECT th.*, u.full_name as creator_name FROM transaction_headers th LEFT JOIN users u ON th.created_by = u.id WHERE th.id = ?", [$id]);
    $payment_lines = $db->fetchAll("SELECT * FROM payments WHERE header_id = ?", [$id]);
    $data['party_type'] = ($data['txn_type'] === 'vendor_payment') ? 'vendor' : 'customer';
    $first_line = $payment_lines[0] ?? [];
    $data['party_id'] = ($first_line['customer_id'] ?? null) ?: ($first_line['vendor_id'] ?? null) ?: '';
    $applied_links = $db->fetchAll("SELECT tl.*, th.txn_number, th.txn_type, th.txn_date FROM transaction_links tl JOIN transaction_headers th ON tl.child_id = th.id WHERE tl.parent_id = ?", [$id]);
    $gl_entries = $db->fetchAll("SELECT je.*, a.account_name FROM journal_entries je JOIN accounts a ON je.account_id = a.id WHERE je.header_id = ? ORDER BY je.entry_type DESC", [$id]);
} else {
    $party_type = $_GET['party_type'] ?? 'customer';
    $txn_prefix = $party_type === 'vendor' ? 'vendor_payment' : 'customer_payment';
    $data = [
        'txn_number'       => getNextTransactionNumber($txn_prefix),
        'txn_date'         => date('Y-m-d'),
        'party_id'         => $_GET['party_id'] ?? '',
        'party_type'       => $party_type,
        'memo'             => '',
        'reference_number' => '',
        'location_id'      => get_accounting_preference('default_location_id') ?: '',
        'status'           => 'draft',
    ];
    $applied_links = [];
    $gl_entries = [];
}

$accounts = $db->fetchAll("
    SELECT a.id, a.account_name, a.account_subtype, a.normal_balance,
        COALESCE(SUM(CASE WHEN h.id IS NOT NULL THEN
            CASE WHEN a.normal_balance = 'debit'
                THEN (CASE WHEN j.entry_type = 'debit' THEN j.amount ELSE -j.amount END)
                ELSE (CASE WHEN j.entry_type = 'credit' THEN j.amount ELSE -j.amount END)
            END ELSE 0 END), 0) as balance
    FROM accounts a
    LEFT JOIN journal_entries j ON a.id = j.account_id
    LEFT JOIN transaction_headers h ON j.header_id = h.id AND h.is_deleted = 0 AND h.status NOT IN ('void','voided','draft')
    WHERE (a.account_type_id = 1 OR a.account_subtype IN ('Bank', 'Cash', 'Liquid Assets')) AND a.is_active = 1 AND a.is_deleted = 0
    GROUP BY a.id ORDER BY a.account_name ASC
");

$customers  = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
$vendors    = $db->fetchAll("SELECT id, company_name FROM vendors WHERE is_active = 1 AND is_deleted = 0 ORDER BY company_name ASC");
$default_bank = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'default_bank_account'")['meta_value'] ?? '';
$default_cash_account_id = get_accounting_preference('default_cash_account') ?: (AccountingEngine::getInstance()->resolveAccount('default_cash_account') ?: '');
$is_pos_pay = (!empty($data['txn_number']) && (strpos($data['txn_number'], 'PAY-POS-') === 0 || strpos($data['txn_number'], 'POS-PAY-') === 0));
$status = $data['status'] ?? 'draft';
$is_new = !$id;
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ================================================================
   CLEAN ENTERPRISE PAYMENT MODULE UI
   ================================================================ */
:root {
  --primary:       #1e40af;
  --primary-hover: #1e3a8a;
  --primary-light: #eff6ff;
  --text-dark:     #0f172a;
  --text-slate:    #334155;
  --text-muted:    #64748b;
  --border-color:  #e2e8f0;
  --bg-app:        #f8fafc;
  --bg-white:      #ffffff;
  --success:       #16a34a;
  --success-bg:    #f0fdf4;
  --danger:        #dc2626;
  --danger-bg:     #fef2f2;
  --warning:       #d97706;
  --warning-bg:    #fffbeb;
  --font-family:   'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

.pm-wrap * { box-sizing: border-box; font-family: var(--font-family); }
.pm-wrap { background: var(--bg-app); min-height: 100vh; padding-bottom: 40px; }

/* Sub Header / Action Bar */
.pm-header-bar {
  background: var(--bg-white);
  border-bottom: 1px solid var(--border-color);
  padding: 12px 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

.pm-header-title {
  display: flex;
  align-items: center;
  gap: 12px;
}
.pm-header-title h1 {
  font-size: 18px;
  font-weight: 700;
  color: var(--text-dark);
  margin: 0;
}
.pm-status-pill {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 12px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.st-draft    { background: #f1f5f9; color: #475569; }
.st-posted   { background: var(--success-bg); color: var(--success); }
.st-open     { background: var(--warning-bg); color: var(--warning); }
.st-partial  { background: var(--primary-light); color: var(--primary); }
.st-reversed { background: var(--danger-bg); color: var(--danger); }

.pm-actions { display: flex; items-center: center; gap: 8px; }

/* Buttons */
.pm-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 16px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  transition: all 0.15s ease;
  text-decoration: none;
  white-space: nowrap;
}
.pm-btn-primary {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}
.pm-btn-primary:hover { background: var(--primary-hover); }
.pm-btn-secondary {
  background: var(--bg-white);
  color: var(--text-slate);
  border-color: var(--border-color);
}
.pm-btn-secondary:hover { background: var(--bg-app); border-color: #cbd5e1; color: var(--text-dark); }
.pm-btn-danger {
  background: var(--bg-white);
  color: var(--danger);
  border-color: #fecaca;
}
.pm-btn-danger:hover { background: var(--danger-bg); }

/* Grid Layout */
.pm-grid {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 20px;
  padding: 20px 24px;
  max-width: 1600px;
  margin: 0 auto;
}
.pm-main-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.pm-side-col { display: flex; flex-direction: column; gap: 20px; }

/* Cards */
.pm-card {
  background: var(--bg-white);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.pm-card-title {
  padding: 14px 20px;
  border-bottom: 1px solid var(--border-color);
  font-size: 14px;
  font-weight: 700;
  color: var(--text-dark);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.pm-card-body { padding: 20px; }

/* Form Fields */
.pm-form-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}
.pm-form-row:last-child { margin-bottom: 0; }

.pm-field-group { display: flex; flex-direction: column; gap: 6px; }
.pm-field-group label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-slate);
}

.pm-control {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  font-size: 13.5px;
  color: var(--text-dark);
  background: #fff;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.pm-control:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}
.pm-control[readonly] {
  background: #f8fafc;
  color: var(--text-muted);
  cursor: default;
}
textarea.pm-control { height: 68px; resize: vertical; }

/* Party Switcher Buttons */
.pm-party-switch {
  display: flex;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  overflow: hidden;
  background: #f8fafc;
}
.pm-switch-btn {
  flex: 1;
  padding: 7px 12px;
  font-size: 12.5px;
  font-weight: 600;
  border: none;
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  transition: all 0.15s;
  text-align: center;
}
.pm-switch-btn.active {
  background: var(--primary);
  color: #fff;
}

/* Payment Methods Table */
.pm-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.pm-table th {
  padding: 10px 14px;
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: #f8fafc;
  border-bottom: 1px solid var(--border-color);
}
.pm-table th.r, .pm-table td.r { text-align: right; }
.pm-table th.c, .pm-table td.c { text-align: center; }

.pm-table td {
  padding: 10px 14px;
  border-bottom: 1px solid var(--border-color);
  vertical-align: middle;
}
.pm-table tr:last-child td { border-bottom: none; }

.pm-table-input {
  width: 100%;
  padding: 6px 10px;
  border: 1px solid var(--border-color);
  border-radius: 5px;
  font-size: 13px;
  color: var(--text-dark);
  outline: none;
}
.pm-table-input:focus { border-color: var(--primary); }
.pm-table-input.r { text-align: right; font-weight: 600; }

.pm-del-icon {
  color: #94a3b8;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 4px;
  transition: all 0.15s;
}
.pm-del-icon:hover { color: var(--danger); background: var(--danger-bg); }

/* Filter Bar */
.pm-filter-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 20px;
  background: #fafcff;
  border-bottom: 1px solid var(--border-color);
}
.pm-search-input {
  max-width: 280px;
  padding: 6px 12px;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  font-size: 13px;
}

/* Open Transactions Table */
.pm-txn-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.pm-txn-table th {
  padding: 10px 14px;
  font-size: 11px;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: #f8fafc;
  border-bottom: 1px solid var(--border-color);
  text-align: left;
}
.pm-txn-table td {
  padding: 10px 14px;
  border-bottom: 1px solid #f1f5f9;
  color: var(--text-dark);
  vertical-align: middle;
}
.pm-txn-table tr:last-child td { border-bottom: none; }
.pm-txn-table tr.applied td { background: #f0f7ff; }

/* Group Row Header */
.pm-group-row td {
  background: #f1f5f9 !important;
  font-weight: 700;
  font-size: 12px;
  color: var(--text-slate);
  padding: 8px 14px !important;
  cursor: pointer;
}

/* Badges */
.pm-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
}
.bg-inv  { background: #f0fdf4; color: #16a34a; }
.bg-bill { background: #fff7ed; color: #c2410c; }
.bg-jrn  { background: #e0f2fe; color: #0369a1; }
.bg-cm   { background: #fdf4ff; color: #9333ea; }
.bg-ob   { background: #fef3c7; color: #92400e; }

/* Summary Sidebar Card */
.pm-summary-card {
  background: var(--bg-white);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.pm-summary-header {
  background: linear-gradient(135deg, #1e3a8a, #1e40af);
  color: #fff;
  padding: 18px 20px;
}
.pm-summary-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  opacity: 0.8;
  margin-bottom: 4px;
}
.pm-summary-val {
  font-size: 32px;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -0.5px;
}

.pm-summary-body {
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.pm-summary-line {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
}
.pm-summary-line .lbl { color: var(--text-muted); }
.pm-summary-line .val { font-weight: 700; color: var(--text-dark); }

.pm-status-banner {
  margin: 0 20px 20px;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 12.5px;
  font-weight: 600;
  text-align: center;
}
.banner-ok   { background: var(--success-bg); color: var(--success); border: 1px solid #bbf7d0; }
.banner-warn { background: var(--warning-bg); color: var(--warning); border: 1px solid #fef08a; }
.banner-err  { background: var(--danger-bg); color: var(--danger); border: 1px solid #fecaca; }

/* Tabs */
.pm-tabs-card {
  background: var(--bg-white);
  border: 1px solid var(--border-color);
  border-radius: 8px;
}
.pm-tab-headers {
  display: flex;
  border-bottom: 1px solid var(--border-color);
  background: #fafcff;
  border-top-left-radius: 8px;
  border-top-right-radius: 8px;
}
.pm-tab-btn {
  padding: 12px 18px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted);
  border: none;
  background: transparent;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.pm-tab-btn.active {
  color: var(--primary);
  border-bottom-color: var(--primary);
  background: #fff;
}
.pm-tab-content { padding: 20px; display: none; }
.pm-tab-content.active { display: block; }
</style>

<div class="pm-wrap">

  <!-- Header Bar -->
  <div class="pm-header-bar">
    <div class="pm-header-title">
      <h1><?php echo $id ? 'Edit Payment' : 'New Payment'; ?></h1>
      <span class="pm-status-pill st-<?php echo strtolower($status); ?>"><?php echo ucfirst($status); ?></span>
    </div>
    <div class="pm-actions">
      <?php if (!$is_pos_pay): ?>
        <button type="submit" form="pm-form" class="pm-btn pm-btn-primary" id="pm-save-btn">Save Payment</button>
      <?php else: ?>
        <button class="pm-btn pm-btn-secondary" disabled>Locked (POS)</button>
      <?php endif; ?>
      <?php if ($id && !$is_pos_pay): ?>
        <button type="button" class="pm-btn pm-btn-danger" onclick="nsDeleteTransaction('<?php echo $id; ?>', '?page=transactions/payment')">Delete</button>
      <?php endif; ?>
      <a href="?page=transactions/payment" class="pm-btn pm-btn-secondary">Cancel</a>
    </div>
  </div>

  <form id="pm-form" method="POST" action="api/save_transaction.php">
    <?php if ($id): ?><input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>"><?php endif; ?>
    <input type="hidden" name="net_amount" id="net_amount" value="<?php echo htmlspecialchars($data['net_amount'] ?? '0.00'); ?>">

    <div class="pm-grid">
      <!-- Main Left Column -->
      <div class="pm-main-col">

        <!-- Payment Header Details Card -->
        <div class="pm-card">
          <div class="pm-card-title">Payment Information</div>
          <div class="pm-card-body">

            <div class="pm-form-row">
              <div class="pm-field-group">
                <label>Payment Type</label>
                <div class="pm-party-switch">
                  <button type="button" class="pm-switch-btn <?php echo ($data['party_type']!='vendor')?'active':''; ?>" id="btn-customer" onclick="setPartyType('customer')">Customer (Receive)</button>
                  <button type="button" class="pm-switch-btn <?php echo ($data['party_type']=='vendor')?'active':''; ?>" id="btn-vendor" onclick="setPartyType('vendor')">Vendor (Pay)</button>
                </div>
                <input type="hidden" name="party_type" id="party_type" value="<?php echo htmlspecialchars($data['party_type']); ?>">
              </div>

              <div class="pm-field-group">
                <label id="party-label"><?php echo ($data['party_type']=='vendor')?'Vendor':'Customer'; ?> *</label>
                <select name="party_id" id="party_id" class="pm-control" required onchange="onPartyChange()">
                  <option value="" disabled <?php echo empty($data['party_id'])?'selected':''; ?> hidden>Select...</option>
                  <?php
                  $parties    = ($data['party_type']=='vendor') ? $vendors : $customers;
                  $name_field = ($data['party_type']=='vendor') ? 'company_name' : 'full_name';
                  foreach ($parties as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo ($data['party_id']==$p['id'])?'selected':''; ?>>
                      <?php echo htmlspecialchars($p[$name_field]); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="pm-form-row">
              <div class="pm-field-group">
                <label>Payment #</label>
                <input type="text" name="txn_number" id="txn_number" class="pm-control" value="<?php echo htmlspecialchars($data['txn_number']??''); ?>" readonly>
              </div>

              <div class="pm-field-group">
                <label>Date *</label>
                <input type="date" name="txn_date" class="pm-control" value="<?php echo $data['txn_date']; ?>" required>
              </div>

              <div class="pm-field-group">
                <label>Reference / Cheque #</label>
                <input type="text" name="reference_number" class="pm-control" value="<?php echo htmlspecialchars($data['reference_number']??''); ?>" placeholder="Optional">
              </div>

              <div class="pm-field-group">
                <label>Location</label>
                <select name="location_id" class="pm-control">
                  <option value="" disabled <?php echo empty($data['location_id'])?'selected':''; ?> hidden>Select Location...</option>
                  <?php
                  $curr_loc = !empty($data['location_id']) ? $data['location_id'] : get_user_default_location_id();
                  foreach (get_active_locations() as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc['id']); ?>" <?php echo ($curr_loc==$loc['id'])?'selected':''; ?>>
                      <?php echo htmlspecialchars($loc['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="pm-form-row">
              <div class="pm-field-group" style="grid-column: 1 / -1;">
                <label>Notes / Memo</label>
                <textarea name="memo" class="pm-control" placeholder="Add payment notes..."><?php echo htmlspecialchars($data['memo']??''); ?></textarea>
              </div>
            </div>

          </div>
        </div>

        <!-- Payment Accounts Card -->
        <div class="pm-card">
          <div class="pm-card-title">
            <span>Deposit / Payment Account</span>
            <div style="display:flex;gap:8px;align-items:center;">
              <button type="button" id="btn-generate-qr" class="pm-btn" onclick="showPaymentQrModal()" style="display: none; background: #003087; color: #fff; border-color: #003087; padding: 4px 10px; font-size: 12px; font-weight: 700; align-items: center; gap: 4px;">
                <i class="fas fa-qrcode"></i> Generate QR
              </button>
              <button type="button" class="pm-btn pm-btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="addPayRow()">+ Add Account</button>
            </div>
          </div>
          <div style="overflow-x:auto;">
            <table class="pm-table">
              <thead>
                <tr>
                  <th>Account</th>
                  <th class="r">Current Balance</th>
                  <th class="r" style="width:160px;">Payment Amount</th>
                  <th class="r">Balance After</th>
                  <th style="width:40px;"></th>
                </tr>
              </thead>
              <tbody id="pay-rows">
                <?php
                $p_rows = empty($payment_lines) ? [null] : $payment_lines;
                foreach ($p_rows as $idx => $pl):
                  $isNew = ($pl === null);
                ?>
                <tr class="pay-method-row">
                  <td>
                    <select name="bank_account_id[]" class="pm-control" required onchange="onAccChange(this)">
                      <option value="" disabled hidden data-balance="0">Select account...</option>
                      <?php foreach ($accounts as $acc):
                        $sel = (!$isNew && $pl['bank_account_id']==$acc['id']) || ($isNew && $acc['id']==$default_bank);
                      ?>
                        <option value="<?php echo $acc['id']; ?>" data-balance="<?php echo $acc['balance']; ?>" <?php echo $sel?'selected':''; ?>>
                          <?php echo htmlspecialchars($acc['account_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td class="r"><span class="acc-bal-display">—</span></td>
                  <td class="r">
                    <input type="number" step="0.01" name="line_amount[]" class="pm-table-input r pay-line-amt" value="<?php echo $isNew ? '0.00' : $pl['amount']; ?>" oninput="calcTotals()">
                  </td>
                  <td class="r"><span class="acc-after-bal">—</span></td>
                  <td class="c"><i class="fas fa-trash-alt pm-del-icon" onclick="removePayRow(this)"></i></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Open Transactions Card -->
        <div class="pm-card">
          <div class="pm-card-title">
            <span>Open Transactions</span>
            <div style="display:flex;gap:8px;">
              <button type="button" class="pm-btn pm-btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="applyAllOpen()">Apply All</button>
              <button type="button" class="pm-btn pm-btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="clearAllTxns()">Clear All</button>
            </div>
          </div>

          <div class="pm-filter-bar">
            <input type="text" id="txn-search" class="pm-search-input" placeholder="Search invoices, bills..." oninput="filterTxns()">
            <div style="display:flex;gap:10px;">
              <select class="pm-control" id="type-filter" style="width:150px;padding:5px 8px;font-size:12.5px;" onchange="filterTxns()">
                <option value="">All Types</option>
                <option value="Invoice">Invoices</option>
                <option value="Bill">Bills</option>
                <option value="Credit Memo">Credit Memos</option>
                <option value="Vendor Credit">Vendor Credits</option>
                <option value="Journal">Journals</option>
              </select>
            </div>
          </div>

          <div style="max-height: 480px; overflow-y: auto;">
            <table class="pm-txn-table" id="txn-table" style="display:none;">
              <thead>
                <tr>
                  <th style="width:36px;" class="c"><input type="checkbox" id="select-all-cb" onchange="toggleSelectAll(this)"></th>
                  <th>Txn #</th>
                  <th>Type</th>
                  <th>Date</th>
                  <th style="text-align:right;">Original</th>
                  <th style="text-align:right;">Balance Due</th>
                  <th style="text-align:right;width:140px;">Apply Amount</th>
                </tr>
              </thead>
              <tbody id="txn-tbody"></tbody>
            </table>
            <div id="txn-placeholder" style="text-align:center;padding:40px;color:var(--text-muted);">
              Select a customer or vendor above to load open transactions
            </div>
          </div>
        </div>

        <!-- Related Tabs -->
        <div class="pm-tabs-card">
          <div class="pm-tab-headers">
            <button type="button" class="pm-tab-btn active" onclick="showTab('tab-related', this)">Related Records</button>
            <button type="button" class="pm-tab-btn" onclick="showTab('tab-gl', this)">GL Impact</button>
            <button type="button" class="pm-tab-btn" onclick="showTab('tab-audit', this)">Audit Information</button>
          </div>

          <div class="pm-tab-content active" id="tab-related">
            <?php if ($id && !empty($applied_links)): ?>
              <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($applied_links as $link): ?>
                  <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border:1px solid var(--border-color);border-radius:6px;">
                    <div>
                      <a href="?page=transactions/view&id=<?php echo $link['child_id']; ?>" target="_blank" style="font-weight:700;color:var(--primary);text-decoration:none;"><?php echo htmlspecialchars($link['txn_number']); ?></a>
                      <span style="font-size:12px;color:var(--text-muted);margin-left:8px;"><?php echo ucfirst(str_replace('_',' ',$link['txn_type'])); ?> · <?php echo $link['txn_date']; ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div style="color:var(--text-muted);font-size:13px;">No related transaction links available.</div>
            <?php endif; ?>
          </div>

          <div class="pm-tab-content" id="tab-gl">
            <?php if (!empty($gl_entries)): ?>
              <table class="pm-table">
                <thead><tr><th>Account</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
                <tbody>
                  <?php foreach ($gl_entries as $gle): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($gle['account_name']); ?></td>
                      <td class="r"><?php echo $gle['entry_type']=='debit' ? number_format($gle['amount'],2) : '—'; ?></td>
                      <td class="r"><?php echo $gle['entry_type']=='credit' ? number_format($gle['amount'],2) : '—'; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div style="color:var(--text-muted);font-size:13px;">GL impact will be calculated when payment is saved.</div>
            <?php endif; ?>
          </div>

          <div class="pm-tab-content" id="tab-audit">
            <div style="font-size:13px;color:var(--text-slate);display:flex;flex-direction:column;gap:8px;">
              <div><strong>Status:</strong> <?php echo ucfirst($status); ?></div>
            </div>
          </div>
        </div>

      </div><!-- End Main Left Col -->

      <!-- Right Summary Column -->
      <div class="pm-side-col">
        <div class="pm-summary-card">
          <div class="pm-summary-header">
            <div class="pm-summary-label">Total Payment</div>
            <div class="pm-summary-val" id="sum-total">0.00</div>
          </div>

          <div class="pm-summary-body">
            <div class="pm-summary-line">
              <span class="lbl">Transactions Selected</span>
              <span class="val" id="sum-count">0</span>
            </div>
            <div class="pm-summary-line">
              <span class="lbl">Total Applied</span>
              <span class="val" id="sum-applied">0.00</span>
            </div>
            <div class="pm-summary-line">
              <span class="lbl">Unapplied Balance</span>
              <span class="val" id="sum-unapplied">0.00</span>
            </div>
          </div>

          <div class="pm-status-banner banner-err" id="balance-banner">
            Enter payment amount
          </div>
        </div>
      </div>

    </div>
  </form>
</div>

<script>
const _accounts   = <?php echo json_encode($accounts); ?>;
const _customers  = <?php echo json_encode($customers); ?>;
const _vendors    = <?php echo json_encode($vendors); ?>;
const defaultCashAccountId = <?php echo json_encode((string)$default_cash_account_id); ?>;
const _custNum    = "<?php echo getNextTransactionNumber('customer_payment'); ?>";
const _vendNum    = "<?php echo getNextTransactionNumber('vendor_payment'); ?>";
const _isEdit     = <?php echo $id ? 'true' : 'false'; ?>;

const GROUP_CFG = {
  'Invoice':         { label:'Invoices',         badge:'bg-inv'  },
  'Bill':            { label:'Bills',             badge:'bg-bill' },
  'Journal':         { label:'Journal Entries',   badge:'bg-jrn'  },
  'Opening Balance': { label:'Opening Balances',  badge:'bg-ob'   },
  'Credit Memo':     { label:'Credit Memos',      badge:'bg-cm'   },
  'Vendor Credit':   { label:'Vendor Credits',    badge:'bg-cm'   },
};
const GROUP_ORDER = ['Invoice','Bill','Opening Balance','Journal','Credit Memo','Vendor Credit'];

let _allRows     = [];

function setPartyType(type) {
  document.getElementById('party_type').value = type;
  document.getElementById('btn-customer').classList.toggle('active', type==='customer');
  document.getElementById('btn-vendor').classList.toggle('active', type==='vendor');
  document.getElementById('party-label').innerText = type==='customer' ? 'Customer *' : 'Vendor *';

  if (!_isEdit) {
    document.getElementById('txn_number').value = type==='customer' ? _custNum : _vendNum;
  }
  const sel   = document.getElementById('party_id');
  const list  = type==='customer' ? _customers : _vendors;
  const field = type==='customer' ? 'full_name' : 'company_name';
  sel.innerHTML = `<option value="" disabled hidden selected>Select...</option>`;
  list.forEach(p => {
    const o = document.createElement('option'); o.value = p.id; o.text = p[field];
    sel.appendChild(o);
  });
  clearTxnTable();
  recalc();
}

function onPartyChange() { fetchOpenTxns(); }

function fetchOpenTxns() {
  const partyId   = document.getElementById('party_id').value;
  const partyType = document.getElementById('party_type').value;
  const payId     = (document.querySelector('input[name="id"]')||{}).value || '';
  if (!partyId) { clearTxnTable(); return; }

  fetch(`api/get_open_transactions.php?party_id=${partyId}&party_type=${partyType}&payment_id=${payId}`)
    .then(r => r.json())
    .then(rows => {
      _allRows = rows || [];
      renderTxnTable();
      recalc();
    });
}

function clearTxnTable() {
  _allRows = [];
  document.getElementById('txn-table').style.display = 'none';
  document.getElementById('txn-placeholder').style.display = 'block';
  recalc();
}

function renderTxnTable() {
  const search = (document.getElementById('txn-search').value||'').toLowerCase();
  const typeF  = document.getElementById('type-filter').value;

  let rows = _allRows.filter(r => {
    if (typeF && (r.txn_type_group||r.txn_type) !== typeF) return false;
    if (search) {
      const hay = [(r.txn_number||''),(r.txn_type||''),(r.txn_date||'')].join(' ').toLowerCase();
      if (!hay.includes(search)) return false;
    }
    return true;
  });

  const tbody = document.getElementById('txn-tbody');
  tbody.innerHTML = '';

  if (rows.length === 0) {
    document.getElementById('txn-table').style.display = 'none';
    document.getElementById('txn-placeholder').style.display = 'block';
    return;
  }

  document.getElementById('txn-table').style.display = 'table';
  document.getElementById('txn-placeholder').style.display = 'none';

  rows.forEach((row) => {
    const applied = parseFloat(row.applied_amount)||0;
    const due     = parseFloat(row.balance_due)||0;
    const total   = parseFloat(row.total_amount)||0;
    const isChk   = Math.abs(applied) > 0.0001;
    const itemKey = row.line_id ? (row.id+':'+row.line_id) : row.id;
    const cfg     = GROUP_CFG[row.txn_type_group||row.txn_type] || GROUP_CFG['Journal'];

    const tr = document.createElement('tr');
    tr.className = isChk ? 'applied' : '';
    tr.dataset.key = itemKey;

    tr.innerHTML = `
      <td class="c"><input type="checkbox" name="apply_txn_id[]" value="${itemKey}" class="apply-cb" onchange="toggleRow(this)" ${isChk?'checked':''}></td>
      <td><a href="?page=transactions/view&id=${row.id}" target="_blank" style="font-weight:600;color:var(--primary);text-decoration:none;">${esc(row.txn_number)}</a></td>
      <td><span class="pm-badge ${cfg.badge}">${esc(row.txn_type)}</span></td>
      <td>${row.txn_date}</td>
      <td class="r">${fmt(total)}</td>
      <td class="r balance-due-text">${fmt(due)}</td>
      <td class="r">
        <input type="number" name="apply_amount[${itemKey}]"
               class="pm-table-input r apply-inp"
               value="${applied.toFixed(2)}"
               step="0.01"
               oninput="onApplyInput(this)">
      </td>`;
    tbody.appendChild(tr);
  });
  recalc();
}

function onApplyInput(inp) {
  const tr = inp.closest('tr');
  const cb = tr.querySelector('.apply-cb');
  const val = parseFloat(inp.value) || 0;
  if (Math.abs(val) > 0.0001) {
    cb.checked = true;
    tr.classList.add('applied');
  } else {
    cb.checked = false;
    tr.classList.remove('applied');
  }
  recalc();
}

function toggleRow(cb) {
  const tr  = cb.closest('tr');
  const inp = tr.querySelector('.apply-inp');
  const due = parseFloat(tr.querySelector('.balance-due-text').innerText.replace(/,/g,''))||0;

  if (cb.checked) {
    const curVal = parseFloat(inp.value) || 0;
    if (Math.abs(curVal) < 0.0001) {
      const total   = parseFloat(document.getElementById('net_amount').value)||0;
      let already   = 0;
      document.querySelectorAll('.apply-inp').forEach(i => {
        if (i !== inp && i.closest('tr').querySelector('.apply-cb').checked) already += parseFloat(i.value)||0;
      });
      const rem = total > 0 ? Math.max(0, total - already) : Math.abs(due);
      inp.value = Math.min(rem > 0 ? rem : Math.abs(due), Math.abs(due)).toFixed(2);
    }
    tr.classList.add('applied');
  } else {
    inp.value = '0.00';
    tr.classList.remove('applied');
  }
  recalc();
}

function toggleSelectAll(masterCb) {
  document.querySelectorAll('#txn-tbody .apply-cb').forEach(cb => {
    if (cb.checked !== masterCb.checked) { cb.checked = masterCb.checked; toggleRow(cb); }
  });
}

function applyAllOpen() {
  const total = parseFloat(document.getElementById('net_amount').value)||0;
  let applied = getTotalApplied();
  document.querySelectorAll('#txn-tbody .apply-cb').forEach(cb => {
    const tr  = cb.closest('tr');
    const inp = tr.querySelector('.apply-inp');
    const due = Math.abs(parseFloat(tr.querySelector('.balance-due-text').innerText.replace(/,/g,''))||0);
    if (cb.checked) return;
    const rem = total > 0 ? Math.max(0, total - applied) : due;
    if (rem <= 0.001 && total > 0) return;
    const amt = Math.min(rem > 0 ? rem : due, due);
    if (amt <= 0.001) return;
    cb.checked = true;
    inp.value  = amt.toFixed(2);
    tr.classList.add('applied');
    applied += amt;
  });
  recalc();
}

function clearAllTxns() {
  document.querySelectorAll('#txn-tbody .apply-cb').forEach(cb => {
    const tr  = cb.closest('tr');
    const inp = tr.querySelector('.apply-inp');
    cb.checked = false;
    inp.value  = '0.00';
    tr.classList.remove('applied');
  });
  recalc();
}

function filterTxns() { renderTxnTable(); }

function addPayRow() {
  const tbody = document.getElementById('pay-rows');
  let opts = '<option value="" disabled hidden data-balance="0">Select account...</option>';
  _accounts.forEach(a => { opts += `<option value="${esc(a.id)}" data-balance="${a.balance}">${esc(a.account_name)}</option>`; });
  const tr = document.createElement('tr');
  tr.className = 'pay-method-row';
  tr.innerHTML = `
    <td><select name="bank_account_id[]" class="pm-control" required onchange="onAccChange(this)">${opts}</select></td>
    <td class="r"><span class="acc-bal-display">—</span></td>
    <td class="r"><input type="number" step="0.01" name="line_amount[]" class="pm-table-input r pay-line-amt" value="0.00" oninput="calcTotals()"></td>
    <td class="r"><span class="acc-after-bal">—</span></td>
    <td class="c"><i class="fas fa-trash-alt pm-del-icon" onclick="removePayRow(this)"></i></td>`;
  tbody.appendChild(tr);
  onAccChange(tr.querySelector('.pm-control'));
  checkQrButtonVisibility();
}

function removePayRow(icon) {
  const tbody = document.getElementById('pay-rows');
  if (tbody.children.length > 1) { icon.closest('tr').remove(); calcTotals(); }
  checkQrButtonVisibility();
}

function checkQrButtonVisibility() {
  let showQr = false;
  document.querySelectorAll('#pay-rows .pay-method-row').forEach(tr => {
    const sel = tr.querySelector('.pm-control');
    if (sel && sel.value && String(sel.value) !== String(defaultCashAccountId)) {
      showQr = true;
    }
  });
  const qrBtn = document.getElementById('btn-generate-qr');
  if (qrBtn) {
    qrBtn.style.display = showQr ? 'inline-flex' : 'none';
  }
}

function onAccChange(sel) {
  const tr   = sel.closest('tr');
  const opt  = sel.options[sel.selectedIndex];
  const bal  = opt ? (parseFloat(opt.getAttribute('data-balance'))||0) : 0;
  const balDisp = tr.querySelector('.acc-bal-display');
  const afterDisp = tr.querySelector('.acc-after-bal');
  if (sel.value) {
    if (balDisp) balDisp.textContent = fmt(bal);
    const lineAmt = parseFloat(tr.querySelector('.pay-line-amt')?.value)||0;
    if (afterDisp) afterDisp.textContent = fmt(bal - lineAmt);
  } else {
    if (balDisp) balDisp.textContent = '—';
    if (afterDisp) afterDisp.textContent = '—';
  }
  checkQrButtonVisibility();
}

function calcTotals() {
  let total = 0;
  document.querySelectorAll('.pay-line-amt').forEach(i => { total += parseFloat(i.value)||0; });
  document.getElementById('sum-total').innerText = fmt(total);
  document.getElementById('net_amount').value = total.toFixed(2);
  document.querySelectorAll('.pay-method-row').forEach(tr => {
    const sel = tr.querySelector('.pm-control');
    const opt = sel?.options[sel?.selectedIndex];
    const bal = opt ? (parseFloat(opt.getAttribute('data-balance'))||0) : 0;
    const lineAmt = parseFloat(tr.querySelector('.pay-line-amt')?.value)||0;
    const afterDisp = tr.querySelector('.acc-after-bal');
    if (afterDisp && sel?.value) afterDisp.textContent = fmt(bal - lineAmt);
  });
  recalc();
  checkQrButtonVisibility();
}

function getTotalApplied() {
  let tot = 0;
  document.querySelectorAll('#txn-tbody .apply-inp').forEach(i => {
    if (i.closest('tr').querySelector('.apply-cb')?.checked) tot += parseFloat(i.value)||0;
  });
  return tot;
}

function recalc() {
  const tendered = parseFloat(document.getElementById('net_amount').value)||0;
  const applied  = getTotalApplied();
  const unapplied = tendered - (applied < 0 ? Math.abs(applied) : applied);

  document.getElementById('sum-applied').innerText   = fmt(applied);
  document.getElementById('sum-unapplied').innerText = fmt(unapplied);

  let cnt = 0;
  document.querySelectorAll('#txn-tbody .apply-cb:checked').forEach(() => { cnt++; });
  document.getElementById('sum-count').innerText = cnt;

  const banner = document.getElementById('balance-banner');
  if (tendered === 0) {
    banner.className = 'pm-status-banner banner-err';
    banner.innerText = 'Enter payment amount';
  } else if (Math.abs(unapplied) < 0.01) {
    banner.className = 'pm-status-banner banner-ok';
    banner.innerText = 'Balanced — Ready to Save';
  } else if (unapplied > 0) {
    banner.className = 'pm-status-banner banner-warn';
    banner.innerText = `${fmt(unapplied)} Unapplied`;
  } else {
    banner.className = 'pm-status-banner banner-err';
    banner.innerText = `Over-applied by ${fmt(Math.abs(unapplied))}`;
  }
}

function showTab(id, btn) {
  document.querySelectorAll('.pm-tab-content').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.pm-tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  btn.classList.add('active');
}

document.getElementById('pm-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const btn = document.getElementById('pm-save-btn');
  if (!btn) return;
  const orig = btn.innerHTML;
  btn.innerHTML = 'Saving...';
  btn.disabled = true;
  fetch(this.action, {method:'POST', body: new FormData(this)})
    .then(r => r.json())
    .then(d => {
      if (d.status === 'success') {
        nsNotify(d.message || 'Payment saved.');
        setTimeout(() => { window.location.href = '?page=transactions/view&id=' + d.id; }, 1200);
      } else {
        nsNotify(d.message || 'Error saving.', 'error');
        btn.innerHTML = orig; btn.disabled = false;
      }
    })
    .catch(() => { nsNotify('Network error.', 'error'); btn.innerHTML = orig; btn.disabled = false; });
});

function fmt(n) { return parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function init() {
  calcTotals();
  document.querySelectorAll('.pay-method-row .pm-control').forEach(onAccChange);
  checkQrButtonVisibility();
  if (_isEdit || document.getElementById('party_id').value) fetchOpenTxns();
}
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
window.addEventListener('load', init);

function showPaymentQrModal() {
    let nonCashAmt = 0;
    let cashAmt = 0;
    document.querySelectorAll('#pay-rows .pay-method-row').forEach(tr => {
        const sel = tr.querySelector('.pm-control');
        const lineAmt = parseFloat(tr.querySelector('.pay-line-amt')?.value) || 0;
        if (sel && sel.value) {
            if (String(sel.value) !== String(defaultCashAccountId)) {
                nonCashAmt += lineAmt;
            } else {
                cashAmt += lineAmt;
            }
        }
    });
    const tendered = parseFloat(document.getElementById('net_amount').value) || 0;
    let qrAmt = 0;
    if (nonCashAmt > 0) {
        qrAmt = nonCashAmt;
    } else if (cashAmt > 0 && tendered > cashAmt) {
        qrAmt = tendered - cashAmt;
    } else {
        qrAmt = tendered;
    }

    const modal = document.getElementById('payment-qr-modal');
    document.getElementById('pm-qr-img').src = 'https://api.qrserver.com/v1/create-qr-code/?size=210x210&margin=0&data=Loading...';
    document.getElementById('pm-qr-amount-txt').innerText = 'Rs ' + qrAmt.toFixed(2);
    modal.style.display = 'flex';

    const txnNo = document.getElementById('txn_number')?.value || '';
    fetch(`api/get_qr_code.php?amount=${qrAmt}&txn_no=${encodeURIComponent(txnNo)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                document.getElementById('pm-qr-img').src = res.qr_src;
                document.getElementById('pm-qr-company-name').innerText = res.company_name;
                document.getElementById('pm-qr-amount-txt').innerText = res.formatted_amount;
            }
        })
        .catch(err => console.error('Error fetching QR code:', err));
}

function closePaymentQrModal() {
    document.getElementById('payment-qr-modal').style.display = 'none';
}
</script>

<!-- PAYMENT MODULE QR MODAL -->
<div id="payment-qr-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 99999; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 16px; width: 380px; max-width: 90%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); text-align: center; position: relative;">
        <button type="button" onclick="closePaymentQrModal()" style="position: absolute; top: 14px; right: 14px; border: none; background: #f1f5f9; color: #64748b; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center;"><i class="fas fa-times"></i></button>
        <div style="color: #003087; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;" id="pm-qr-company-name">MNS LIQUORS</div>
        <div style="font-size: 12px; color: #64748b; margin-bottom: 15px;" id="pm-qr-txn-no">Ref: <?php echo htmlspecialchars($data['txn_number'] ?? ''); ?></div>
        
        <div style="background: #f8fafc; border: 2px dashed #003087; border-radius: 12px; padding: 15px; display: inline-block; margin-bottom: 15px; width: 100%; box-sizing: border-box;">
            <img id="pm-qr-img" src="" alt="Payment QR" style="width: 210px; height: 210px; border-radius: 8px; background: #fff; padding: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: block; margin: 0 auto;">
            <div style="font-size: 22px; font-weight: 800; color: #16a34a; margin-top: 12px;" id="pm-qr-amount-txt">Rs 0.00</div>
        </div>

        <div style="font-size: 12px; color: #475569; font-weight: 500; margin-bottom: 18px;">
            <i class="fas fa-mobile-alt" style="color: #003087; margin-right: 4px;"></i> Scan with eSewa, Fonepay, Mobile Banking or any QR app to pay.
        </div>
        <button type="button" class="pm-btn pm-btn-primary" onclick="closePaymentQrModal()" style="width: 100%; padding: 10px; justify-content: center;">Done / Close</button>
    </div>
</div>
