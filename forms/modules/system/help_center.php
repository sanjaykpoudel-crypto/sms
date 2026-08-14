<?php
/**
 * MNS ERP - Help Center & Comprehensive System Documentation
 * Full page documentation covering engines, forms, reports, database schemas, and logic.
 */
$db = db();
?>

<style>
.hc-container {
    max-width: 1300px;
    margin: 0 auto;
    padding: 10px 20px 40px 20px;
    font-family: var(--ns-font-family, system-ui, -apple-system, sans-serif);
}
.hc-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: white;
    padding: 30px 32px;
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.hc-hero::after {
    content: '';
    position: absolute;
    right: -40px;
    bottom: -40px;
    width: 220px;
    height: 220px;
    background: rgba(59, 130, 246, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.hc-hero-title {
    font-size: 24px;
    font-weight: 800;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.hc-hero-subtitle {
    font-size: 13px;
    color: #94a3b8;
    max-width: 750px;
    line-height: 1.6;
    margin: 0;
}
.hc-search-bar {
    margin-top: 20px;
    position: relative;
    max-width: 650px;
}
.hc-search-input {
    width: 100%;
    padding: 12px 16px 12px 44px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.1);
    color: white;
    font-size: 14px;
    outline: none;
    backdrop-filter: blur(8px);
    transition: all 0.2s;
}
.hc-search-input::placeholder {
    color: #94a3b8;
}
.hc-search-input:focus {
    background: rgba(255,255,255,0.18);
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
}
.hc-nav-tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 24px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 12px;
}
.hc-tab-btn {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}
.hc-tab-btn:hover, .hc-tab-btn.active {
    background: #2563eb;
    color: white;
    border-color: #2563eb;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
}

.hc-section {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.02);
}
.hc-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 12px;
    margin-bottom: 16px;
}
.hc-section-title {
    font-size: 17px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.hc-grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 16px;
}
.hc-grid-3 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}

.hc-card {
    background: #fafafa;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 18px;
    transition: all 0.2s;
}
.hc-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    transform: translateY(-1px);
}
.hc-card-title {
    font-size: 14px;
    font-weight: 700;
    margin: 0 0 8px 0;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}
.hc-card-desc {
    font-size: 12.5px;
    color: #64748b;
    line-height: 1.55;
    margin: 0 0 12px 0;
}
.hc-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10.5px;
    font-weight: 600;
    margin-right: 6px;
}
.hc-badge-blue { background: #dbeafe; color: #1e40af; }
.hc-badge-green { background: #dcfce7; color: #166534; }
.hc-badge-amber { background: #fef3c7; color: #92400e; }
.hc-badge-purple { background: #f3e8ff; color: #6b21a8; }
.hc-badge-slate { background: #f1f5f9; color: #334155; }

.hc-code-block {
    background: #0f172a;
    color: #e2e8f0;
    padding: 12px 16px;
    border-radius: 8px;
    font-family: consolas, monaco, monospace;
    font-size: 12px;
    line-height: 1.5;
    overflow-x: auto;
    margin: 10px 0;
}

.hc-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 6px;
    background: #f1f5f9;
    color: #2563eb;
    font-weight: 600;
    font-size: 11.5px;
    text-decoration: none;
    transition: all 0.2s;
}
.hc-link-btn:hover {
    background: #2563eb;
    color: white;
}
</style>

<div class="hc-container">

    <!-- Hero Header -->
    <div class="hc-hero">
        <h1 class="hc-hero-title">
            <i class="fas fa-book-reader" style="color: #f59e0b;"></i>
            MNS ERP Knowledge Base & Comprehensive System Documentation
        </h1>
        <p class="hc-hero-subtitle">
            Complete technical specification, architectural rules, operational logic, database schema mappings, and navigation directory for all core business engines, forms, and reports.
        </p>
        <div class="hc-search-bar">
            <i class="fas fa-search" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 15px;"></i>
            <input type="text" class="hc-search-input" id="hc-filter-input" onkeyup="hcFilterDocs(this.value)" placeholder="Search any topic, engine logic, table name, or module (e.g. POS, P&L, InventoryEngine, audit_logs)...">
        </div>
    </div>

    <!-- Quick Navigation Tabs -->
    <div class="hc-nav-tabs">
        <a href="#section-engines" class="hc-tab-btn active"><i class="fas fa-cogs"></i> Core Business Engines</a>
        <a href="#section-forms" class="hc-tab-btn"><i class="fas fa-file-signature"></i> Forms & Transactions</a>
        <a href="#section-reports" class="hc-tab-btn"><i class="fas fa-chart-bar"></i> Reports & Registers</a>
        <a href="#section-database" class="hc-tab-btn"><i class="fas fa-database"></i> Database Architecture</a>
        <a href="#section-audit" class="hc-tab-btn"><i class="fas fa-history"></i> System Notes & Change Logs</a>
        <a href="#section-masters" class="hc-tab-btn"><i class="fas fa-sliders-h"></i> Master Setup & Roles</a>
        <a href="#section-entry-guide" class="hc-tab-btn" style="background:#fef3c7; color:#92400e; border-color:#f59e0b;"><i class="fas fa-book-open"></i> Accounting & Entry Guide</a>
    </div>

    <!-- 1. CORE BUSINESS ENGINES -->
    <div class="hc-section" id="section-engines">
        <div class="hc-section-header">
            <h2 class="hc-section-title">
                <i class="fas fa-cogs" style="color: #2563eb;"></i>
                1. Core Business Engines Architecture & Logics
            </h2>
            <span class="hc-badge hc-badge-blue">System Core</span>
        </div>

        <div class="hc-grid-3">

            <!-- Inventory Engine -->
            <div class="hc-card doc-item" data-keywords="inventory engine moving average cost stock allocation valuation cost_price items">
                <h3 class="hc-card-title"><i class="fas fa-boxes" style="color: #0d9488;"></i> Inventory Engine (`InventoryEngine.php`)</h3>
                <p class="hc-card-desc">
                    Manages real-time stock levels, moving-average cost recalculations, stock reservations, and inter-location inventory movements.
                </p>
                <div style="font-size: 11.5px; color: #334155; margin-bottom: 10px;">
                    <strong>Key Logics Implemented:</strong>
                    <ul style="margin: 4px 0 0 0; padding-left: 18px;">
                        <li><strong>Moving Average Cost Formula:</strong> Recalculated on inbound purchases (`bill`, `inventory_transfer`):<br>
                        <code>New Cost = ((Current Qty * Current Cost) + (Inbound Qty * Inbound Rate)) / Total Qty</code></li>
                        <li><strong>Stock Deduction Scenarios:</strong> Customer Invoices, POS Sales, Inventory Transfers OUT, Stock Adjustment Losses.</li>
                        <li><strong>Stock Reversal Scenarios:</strong> Credit Memos (if "Return Items to Stock" checked), Vendor Credits, Inventory Transfers IN.</li>
                    </ul>
                </div>
                <div class="hc-code-block">
File: api/InventoryEngine.php
Location: Centralized inventory calculation engine
                </div>
            </div>

            <!-- Accounting Engine -->
            <div class="hc-card doc-item" data-keywords="accounting engine gl journal entries double entry debit credit resolveaccount preference">
                <h3 class="hc-card-title"><i class="fas fa-calculator" style="color: #d97706;"></i> Accounting Engine (`AccountingEngine.php`)</h3>
                <p class="hc-card-desc">
                    Generates balanced General Ledger postings for all system transactions, ensuring <code>SUM(Debit) == SUM(Credit)</code> across all periods.
                </p>
                <div style="font-size: 11.5px; color: #334155; margin-bottom: 10px;">
                    <strong>Preference Fallback Hierarchy (resolveAccount):</strong>
                    <ol style="margin: 4px 0 0 0; padding-left: 18px;">
                        <li>Explicit transaction line account override.</li>
                        <li>Item master specific GL override (<code>income_account_id</code>, <code>cogs_account_id</code>).</li>
                        <li>Category item accounting (<code>erp_item_accounting</code>).</li>
                        <li>Location effective-dated preferences (<code>erp_accounting_preferences</code>).</li>
                        <li>Operational system defaults (<code>system_info</code>).</li>
                        <li>Engine integer fallback (<code>Cash=2</code>, <code>AR=6</code>, <code>Inventory=7</code>, <code>AP=12</code>, <code>Sales=25</code>, <code>COGS=26</code>).</li>
                    </ol>
                </div>
                <div class="hc-code-block">
File: api/AccountingEngine.php
Method: resolveAccount($key, $context)
                </div>
            </div>

            <!-- Reporting Engine -->
            <div class="hc-card doc-item" data-keywords="reporting engine pnl balance sheet pos deduplication source pos_sync trial balance">
                <h3 class="hc-card-title"><i class="fas fa-chart-line" style="color: #16a34a;"></i> Reporting Engine (`ReportingEngine.php`)</h3>
                <p class="hc-card-desc">
                    Provides single-source-of-truth financial statements (P&L, Balance Sheet, Trial Balance) with zero double-counting.
                </p>
                <div style="font-size: 11.5px; color: #334155; margin-bottom: 10px;">
                    <strong>POS Deduplication & P&L Logics:</strong>
                    <ul style="margin: 4px 0 0 0; padding-left: 18px;">
                        <li><strong>Deduplication Rule:</strong> Excludes auto-created POS invoices (<code>source = 'pos_sync'</code> or <code>created_by = 'POS Engine'</code>) from sales totals when raw POS counter sales are counted.</li>
                        <li><strong>Complete GL Inclusion:</strong> Includes all Journal Entries (Operating Expenses JV, Salary JV, CA audit adjustments) and direct B2B Invoices.</li>
                        <li><strong>P&L Formula:</strong> <code>Net Profit = (Sales - Credit Memos) - (COGS - Return COGS) - Expenses</code>.</li>
                    </ul>
                </div>
                <div class="hc-code-block">
File: api/ReportingEngine.php
Functions: re_get_pnl(), re_get_balance_sheet()
                </div>
            </div>

        </div>
    </div>

    <!-- 2. FORMS & TRANSACTIONS DIRECTORY -->
    <div class="hc-section" id="section-forms">
        <div class="hc-section-header">
            <h2 class="hc-section-title">
                <i class="fas fa-file-signature" style="color: #16a34a;"></i>
                2. Transaction Forms & Operational Workflows
            </h2>
            <span class="hc-badge hc-badge-green">11 Transaction Modules</span>
        </div>

        <div class="hc-grid-2">

            <!-- POS -->
            <div class="hc-card doc-item" data-keywords="pos point of sale counter sales register pos_entry pos_items receipt">
                <h3 class="hc-card-title"><i class="fas fa-cash-register" style="color: #f59e0b;"></i> POS Counter Sales</h3>
                <p class="hc-card-desc">
                    Fast retail counter terminal for barcode scanning, quick item selection, cash/bank payments, and receipt printing.
                </p>
                <div style="font-size: 11.5px; color: #475569; margin-bottom: 10px;">
                    <strong>Tables Affected:</strong> <code>pos_entry</code>, <code>pos_items</code>, <code>journal_entries</code><br>
                    <strong>GL Postings:</strong> DR Cash/Bank (Acc 2/3), CR Sales Income (Acc 25); DR COGS (Acc 26), CR Inventory Asset (Acc 7).
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=transactions/pos/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New POS Sale</a>
                    <a href="?page=transactions/pos" class="hc-link-btn"><i class="fas fa-list"></i> POS Register</a>
                </div>
            </div>

            <!-- Customer Invoices -->
            <div class="hc-card doc-item" data-keywords="sales invoice b2b customer receivables customer_invoices transaction_headers ar">
                <h3 class="hc-card-title"><i class="fas fa-file-invoice-dollar" style="color: #10b981;"></i> Customer Invoices (AR)</h3>
                <p class="hc-card-desc">
                    B2B credit sales invoices with customer credit limits, payment terms, tax calculations, and line item discounts.
                </p>
                <div style="font-size: 11.5px; color: #475569; margin-bottom: 10px;">
                    <strong>Tables Affected:</strong> <code>transaction_headers</code>, <code>transaction_lines</code>, <code>customer_invoices</code>, <code>journal_entries</code><br>
                    <strong>GL Postings:</strong> DR Accounts Receivable (Acc 6), CR Sales Income (Acc 25), CR VAT Payable (Acc 13).
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=transactions/invoice/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New Invoice</a>
                    <a href="?page=transactions/invoice" class="hc-link-btn"><i class="fas fa-list"></i> Invoice Register</a>
                </div>
            </div>

            <!-- Customer Payments -->
            <div class="hc-card doc-item" data-keywords="customer payments receive payment ar settlement payments receipt">
                <h3 class="hc-card-title"><i class="fas fa-hand-holding-usd" style="color: #0284c7;"></i> Customer Payments</h3>
                <p class="hc-card-desc">
                    Record customer payment receipts against open invoices with multi-invoice allocation and discount application.
                </p>
                <div style="font-size: 11.5px; color: #475569; margin-bottom: 10px;">
                    <strong>Tables Affected:</strong> <code>payments</code>, <code>payment_lines</code>, <code>customer_invoices</code>, <code>journal_entries</code><br>
                    <strong>GL Postings:</strong> DR Cash/Bank Account, CR Accounts Receivable (Acc 6).
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=transactions/payment/manage" class="hc-link-btn"><i class="fas fa-plus"></i> Receive Payment</a>
                    <a href="?page=transactions/payment/customer_payments" class="hc-link-btn"><i class="fas fa-list"></i> Payment Register</a>
                </div>
            </div>

            <!-- Credit Memos -->
            <div class="hc-card doc-item" data-keywords="credit memo customer return refund credit_memos restock stock">
                <h3 class="hc-card-title"><i class="fas fa-undo-alt" style="color: #dc2626;"></i> Credit Memos & Sales Returns</h3>
                <p class="hc-card-desc">
                    Process sales returns, issue customer credit memos, automatically restock returned goods, and reverse revenue/COGS.
                </p>
                <div style="font-size: 11.5px; color: #475569; margin-bottom: 10px;">
                    <strong>Tables Affected:</strong> <code>credit_memos</code>, <code>credit_memo_items</code>, <code>journal_entries</code><br>
                    <strong>GL Postings:</strong> DR Sales Return/Sales (Acc 24/25), CR Accounts Receivable (Acc 6); DR Inventory Asset (Acc 7), CR COGS (Acc 26).
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=transactions/credit_memo/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New Credit Memo</a>
                    <a href="?page=transactions/credit_memo" class="hc-link-btn"><i class="fas fa-list"></i> Credit Memo Register</a>
                </div>
            </div>

            <!-- Vendor Bills -->
            <div class="hc-card doc-item" data-keywords="vendor bill purchase payables vendor_bills supplier purchase order ap">
                <h3 class="hc-card-title"><i class="fas fa-file-bill" style="color: #9333ea;"></i> Vendor Bills (AP)</h3>
                <p class="hc-card-desc">
                    Record supplier purchase bills, increase stock levels, calculate purchase VAT, and track vendor payables.
                </p>
                <div style="font-size: 11.5px; color: #475569; margin-bottom: 10px;">
                    <strong>Tables Affected:</strong> <code>transaction_headers</code>, <code>transaction_lines</code>, <code>vendor_bills</code>, <code>journal_entries</code><br>
                    <strong>GL Postings:</strong> DR Inventory Asset (Acc 7), DR VAT Input, CR Accounts Payable (Acc 12).
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=transactions/bill/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New Vendor Bill</a>
                    <a href="?page=transactions/bill" class="hc-link-btn"><i class="fas fa-list"></i> Bill Register</a>
                </div>
            </div>

            <!-- Journal Entries -->
            <div class="hc-card doc-item" data-keywords="journal entries journal_entries voucher general ledger ca audit opening balance">
                <h3 class="hc-card-title"><i class="fas fa-book" style="color: #d97706;"></i> Journal Vouchers (JV)</h3>
                <p class="hc-card-desc">
                    Post manual double-entry journal vouchers, salary & expense adjustments, CA audit opening balances, and fiscal year closing entries.
                </p>
                <div style="font-size: 11.5px; color: #475569; margin-bottom: 10px;">
                    <strong>Tables Affected:</strong> <code>transaction_headers</code>, <code>journal_entries</code><br>
                    <strong>Validation Rule:</strong> Total Debits must equal Total Credits before posting.
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=transactions/journal/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New Journal Entry</a>
                    <a href="?page=transactions/journal" class="hc-link-btn"><i class="fas fa-list"></i> Journal Register</a>
                </div>
            </div>

        </div>
    </div>

    <!-- 3. REPORTS & REGISTERS DIRECTORY -->
    <div class="hc-section" id="section-reports">
        <div class="hc-section-header">
            <h2 class="hc-section-title">
                <i class="fas fa-chart-bar" style="color: #d97706;"></i>
                3. Financial & Operational Reports Directory
            </h2>
            <span class="hc-badge hc-badge-amber">10 Core Reports</span>
        </div>

        <div class="hc-grid-3">

            <!-- General Ledger -->
            <div class="hc-card doc-item" data-keywords="general ledger report account movement debit credit balance">
                <h3 class="hc-card-title"><i class="fas fa-list-alt" style="color: #2563eb;"></i> General Ledger</h3>
                <p class="hc-card-desc">Detailed transactional debit/credit movement for any account with running balance calculation.</p>
                <a href="?page=reports/financial/general_ledger" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open General Ledger</a>
            </div>

            <!-- Profit & Loss -->
            <div class="hc-card doc-item" data-keywords="profit loss pnl income statement cogs gross net profit">
                <h3 class="hc-card-title"><i class="fas fa-chart-line" style="color: #16a34a;"></i> Profit & Loss (P&L)</h3>
                <p class="hc-card-desc">Income, Cost of Goods Sold, Gross Profit, Operating Expenses, and Net Profit statement.</p>
                <a href="?page=reports/financial/pnl" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open P&L Report</a>
            </div>

            <!-- Balance Sheet -->
            <div class="hc-card doc-item" data-keywords="balance sheet assets liabilities equity retained earnings">
                <h3 class="hc-card-title"><i class="fas fa-balance-scale" style="color: #9333ea;"></i> Balance Sheet</h3>
                <p class="hc-card-desc">As-of-date statement of Assets, Liabilities, Owner's Equity, and Net Income Retained Earnings.</p>
                <a href="?page=reports/financial/balance_sheet" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open Balance Sheet</a>
            </div>

            <!-- Trial Balance -->
            <div class="hc-card doc-item" data-keywords="trial balance debits credits account verification">
                <h3 class="hc-card-title"><i class="fas fa-check-double" style="color: #0284c7;"></i> Trial Balance</h3>
                <p class="hc-card-desc">Period-end summary of opening, movement, and closing debit/credit balances across all accounts.</p>
                <a href="?page=reports/financial/trial_balance" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open Trial Balance</a>
            </div>

            <!-- Stock Summary -->
            <div class="hc-card doc-item" data-keywords="stock summary inventory valuation unit cost quantity">
                <h3 class="hc-card-title"><i class="fas fa-boxes" style="color: #0d9488;"></i> Stock Summary Report</h3>
                <p class="hc-card-desc">On-hand physical quantities, average unit costs, and total stock valuation breakdown by location.</p>
                <a href="?page=reports/inventory/stock_summary" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open Stock Summary</a>
            </div>

            <!-- Sales Register -->
            <div class="hc-card doc-item" data-keywords="sales summary register customer location breakdown">
                <h3 class="hc-card-title"><i class="fas fa-receipt" style="color: #f59e0b;"></i> Sales Register</h3>
                <p class="hc-card-desc">Detailed sales register by customer, location, date range, and invoice status.</p>
                <a href="?page=reports/sales/sales_summary" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open Sales Register</a>
            </div>

            <!-- Purchases Register -->
            <div class="hc-card doc-item" data-keywords="purchases summary vendor supplier bill register">
                <h3 class="hc-card-title"><i class="fas fa-shopping-cart" style="color: #dc2626;"></i> Purchases Register</h3>
                <p class="hc-card-desc">Detailed purchase register by vendor, location, tax, and bill status.</p>
                <a href="?page=reports/purchases/purchases_summary" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open Purchases Register</a>
            </div>

            <!-- VAT Return -->
            <div class="hc-card doc-item" data-keywords="vat return tax register sales vat purchase vat tax payable">
                <h3 class="hc-card-title"><i class="fas fa-percent" style="color: #ec4899;"></i> VAT Return Register</h3>
                <p class="hc-card-desc">Taxable sales, exempt sales, sales VAT collected, purchase VAT paid, and net VAT liability.</p>
                <a href="?page=reports/vat/vat_return" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open VAT Return</a>
            </div>

            <!-- AR Aging -->
            <div class="hc-card doc-item" data-keywords="ar aging customer receivables overdue days 30 60 90">
                <h3 class="hc-card-title"><i class="fas fa-clock" style="color: #eab308;"></i> Customer AR Aging</h3>
                <p class="hc-card-desc">Outstanding customer balances categorized into 0-30, 31-60, 61-90, and 90+ days aging buckets.</p>
                <a href="?page=reports/customers/ar_aging" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open AR Aging</a>
            </div>

            <!-- AP Aging -->
            <div class="hc-card doc-item" data-keywords="ap aging vendor payables overdue days 30 60 90">
                <h3 class="hc-card-title"><i class="fas fa-hourglass-half" style="color: #6366f1;"></i> Vendor AP Aging</h3>
                <p class="hc-card-desc">Outstanding vendor payables categorized into 0-30, 31-60, 61-90, and 90+ days aging buckets.</p>
                <a href="?page=reports/vendors/ap_aging" class="hc-link-btn"><i class="fas fa-external-link-alt"></i> Open AP Aging</a>
            </div>

        </div>
    </div>

    <!-- 4. DATABASE ARCHITECTURE -->
    <div class="hc-section" id="section-database">
        <div class="hc-section-header">
            <h2 class="hc-section-title">
                <i class="fas fa-database" style="color: #9333ea;"></i>
                4. Database Architecture & Schema References
            </h2>
            <span class="hc-badge hc-badge-purple">Database Relational Schema</span>
        </div>

        <div style="overflow-x: auto;">
            <table class="ns-table" style="width: 100%; border-collapse: collapse; font-size: 12.5px;">
                <thead>
                    <tr style="background: #f8fafc; text-align: left; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 10px 14px;">Table Name</th>
                        <th style="padding: 10px 14px;">Primary Key</th>
                        <th style="padding: 10px 14px;">Core Purpose</th>
                        <th style="padding: 10px 14px;">Key Columns & Enums</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; font-weight: 700; color: #2563eb;"><code>transaction_headers</code></td>
                        <td style="padding: 10px 14px;"><code>id</code> (INT)</td>
                        <td style="padding: 10px 14px;">Header record for all invoices, bills, payments, expenses, journals, and transfers.</td>
                        <td style="padding: 10px 14px;"><code>txn_number</code>, <code>txn_type</code>, <code>txn_date</code>, <code>net_amount</code>, <code>status</code> ('posted', 'void', 'draft'), <code>created_by</code>, <code>updated_by</code></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; font-weight: 700; color: #2563eb;"><code>transaction_lines</code></td>
                        <td style="padding: 10px 14px;"><code>id</code> (INT)</td>
                        <td style="padding: 10px 14px;">Line items detailing products, quantities, rates, and unit costs.</td>
                        <td style="padding: 10px 14px;"><code>header_id</code>, <code>item_id</code>, <code>quantity</code>, <code>unit_price</code>, <code>cost_price</code>, <code>line_total</code></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; font-weight: 700; color: #2563eb;"><code>journal_entries</code></td>
                        <td style="padding: 10px 14px;"><code>id</code> (VARCHAR/UUID)</td>
                        <td style="padding: 10px 14px;">General Ledger double-entry postings for financial reporting.</td>
                        <td style="padding: 10px 14px;"><code>header_id</code>, <code>account_id</code> (INT), <code>entry_type</code> ('debit', 'credit'), <code>amount</code>, <code>entry_date</code>, <code>party_id</code></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; font-weight: 700; color: #2563eb;"><code>pos_entry</code> & <code>pos_items</code></td>
                        <td style="padding: 10px 14px;"><code>id</code> (INT)</td>
                        <td style="padding: 10px 14px;">POS counter retail transaction header and items.</td>
                        <td style="padding: 10px 14px;"><code>invoice_no</code>, <code>date_time</code>, <code>total_amount</code>, <code>net_amount</code>, <code>location_id</code></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; font-weight: 700; color: #2563eb;"><code>items</code></td>
                        <td style="padding: 10px 14px;"><code>id</code> (INT)</td>
                        <td style="padding: 10px 14px;">Product and item master definitions.</td>
                        <td style="padding: 10px 14px;"><code>item_name</code>, <code>sku</code>, <code>cost_price</code>, <code>unit_id</code>, <code>income_account_id</code>, <code>cogs_account_id</code></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; font-weight: 700; color: #2563eb;"><code>accounts</code></td>
                        <td style="padding: 10px 14px;"><code>id</code> (INT)</td>
                        <td style="padding: 10px 14px;">Chart of accounts defining GL account structure.</td>
                        <td style="padding: 10px 14px;"><code>account_name</code>, <code>account_type</code> ('asset', 'liability', 'equity', 'income', 'expense'), <code>account_subtype</code></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; font-weight: 700; color: #2563eb;"><code>audit_logs</code></td>
                        <td style="padding: 10px 14px;"><code>id</code> (INT)</td>
                        <td style="padding: 10px 14px;">Entity edit history and system note records.</td>
                        <td style="padding: 10px 14px;"><code>table_name</code>, <code>action</code> ('create', 'update', 'delete'), <code>record_id</code>, <code>old_values</code>, <code>new_values</code>, <code>user_id</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 5. SYSTEM NOTES & AUDIT TRAIL LOGIC -->
    <div class="hc-section" id="section-audit">
        <div class="hc-section-header">
            <h2 class="hc-section-title">
                <i class="fas fa-history" style="color: #0284c7;"></i>
                5. System Notes & Change Log Rules
            </h2>
            <span class="hc-badge hc-badge-slate">Audit System Specification</span>
        </div>

        <div class="hc-grid-2">
            <div class="hc-card doc-item" data-keywords="system notes change log audit trail positional lookup updated_by">
                <h3 class="hc-card-title"><i class="fas fa-shield-alt" style="color: #0284c7;"></i> Change Log Resolution Rule</h3>
                <p class="hc-card-desc">
                    All entity views (Transactions, Customers, Vendors, Items, Users) render a clean **System Notes / Change Log** tab with internal ID badges.
                </p>
                <div style="font-size: 11.5px; color: #334155;">
                    <strong>Technical Execution:</strong>
                    <ul style="margin: 4px 0 0 0; padding-left: 18px;">
                        <li><strong>Multi-Table Positional Lookup:</strong> Queries <code>audit_logs</code> matching <code>record_id = header_id OR customer_invoices / vendor_bills / payments / expenses</code>.</li>
                        <li><strong>Synthetic Creation Fallback:</strong> If no explicit log row exists for a historical transaction, the system dynamically synthesizes the creation note from <code>transaction_headers</code> (<code>txn_number</code>, <code>amount</code>, <code>status</code>, <code>created_by</code>, <code>created_at</code>).</li>
                        <li><strong>Action Badges:</strong> Displays color-coded badges (<span class="hc-badge hc-badge-blue">UPDATE</span>, <span class="hc-badge hc-badge-green">CREATE</span>, <span class="hc-badge hc-badge-amber">DELETE</span>).</li>
                    </ul>
                </div>
            </div>

            <div class="hc-card doc-item" data-keywords="audit_logs updated_by transaction_headers user tracking">
                <h3 class="hc-card-title"><i class="fas fa-user-edit" style="color: #16a34a;"></i> User Tracking & Schema Alignment</h3>
                <p class="hc-card-desc">
                    Every save, edit, and status update captures the modifier's user ID and timestamp.
                </p>
                <div style="font-size: 11.5px; color: #334155;">
                    <strong>Database Schema Columns:</strong>
                    <ul style="margin: 4px 0 0 0; padding-left: 18px;">
                        <li><code>created_by</code> (INT) - User ID who created the record.</li>
                        <li><code>created_at</code> (DATETIME) - Creation timestamp.</li>
                        <li><code>updated_by</code> (INT) - User ID who last modified the record.</li>
                        <li><code>updated_at</code> (TIMESTAMP) - Auto-updated modification timestamp.</li>
                    </ul>
                </div>
            </div>
        </div>
    <!-- 6. MASTER SETUP, SYSTEM PREFERENCES & USER ROLES -->
    <div class="hc-section" id="section-masters">
        <div class="hc-section-header">
            <h2 class="hc-section-title">
                <i class="fas fa-sliders-h" style="color: #6366f1;"></i>
                6. Master Setup, Chart of Accounts, User Roles & System Preferences
            </h2>
            <span class="hc-badge hc-badge-purple">Master System Setup</span>
        </div>

        <div class="hc-grid-3">

            <!-- Item Master -->
            <div class="hc-card doc-item" data-keywords="item master sku barcode unit cost income cogs account items">
                <h3 class="hc-card-title"><i class="fas fa-box" style="color: #0d9488;"></i> Item Master Setup</h3>
                <p class="hc-card-desc">Define products, unit costs, selling prices, unit conversions, and specific GL income/COGS account overrides.</p>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=master/item/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New Item</a>
                    <a href="?page=master/item" class="hc-link-btn"><i class="fas fa-list"></i> Items List</a>
                </div>
            </div>

            <!-- Customer Master -->
            <div class="hc-card doc-item" data-keywords="customer master profile credit limit payment terms customer ar">
                <h3 class="hc-card-title"><i class="fas fa-users" style="color: #10b981;"></i> Customer Master</h3>
                <p class="hc-card-desc">Customer contact profiles, credit limits, payment terms, tax/VAT registration, and opening AR balance.</p>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=master/customer/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New Customer</a>
                    <a href="?page=master/customer" class="hc-link-btn"><i class="fas fa-list"></i> Customers List</a>
                </div>
            </div>

            <!-- Vendor Master -->
            <div class="hc-card doc-item" data-keywords="vendor master supplier profile payment terms pan vat ap">
                <h3 class="hc-card-title"><i class="fas fa-truck" style="color: #9333ea;"></i> Vendor Master</h3>
                <p class="hc-card-desc">Supplier profiles, vendor credit terms, tax PAN numbers, bank details, and opening AP balance.</p>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=master/vendor/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New Vendor</a>
                    <a href="?page=master/vendor" class="hc-link-btn"><i class="fas fa-list"></i> Vendors List</a>
                </div>
            </div>

            <!-- Chart of Accounts -->
            <div class="hc-card doc-item" data-keywords="chart of accounts gl account asset liability equity income expense account_type">
                <h3 class="hc-card-title"><i class="fas fa-sitemap" style="color: #d97706;"></i> Chart of Accounts</h3>
                <p class="hc-card-desc">Structure General Ledger accounts across Asset, Liability, Equity, Income, and Expense categories.</p>
                <div style="display: flex; gap: 8px;">
                    <a href="?page=master/account/manage" class="hc-link-btn"><i class="fas fa-plus"></i> New GL Account</a>
                    <a href="?page=master/account" class="hc-link-btn"><i class="fas fa-list"></i> Accounts List</a>
                </div>
            </div>

            <!-- User Management & Roles -->
            <div class="hc-card doc-item" data-keywords="users user management roles permissions administrator manager accountant cashier clerk auditor permissions">
                <h3 class="hc-card-title"><i class="fas fa-user-shield" style="color: #2563eb;"></i> User Management & System Roles</h3>
                <p class="hc-card-desc">Manage system users and configure granular role permissions (Administrator, Manager, Accountant, Cashier, Inventory Clerk, Auditor).</p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="?page=system/users/user_manage" class="hc-link-btn"><i class="fas fa-plus"></i> New User</a>
                    <a href="?page=system/users" class="hc-link-btn"><i class="fas fa-users"></i> Users List</a>
                    <a href="?page=system/roles" class="hc-link-btn"><i class="fas fa-lock"></i> Roles & Permissions</a>
                </div>
            </div>

            <!-- System Preferences & Fiscal Years -->
            <div class="hc-card doc-item" data-keywords="accounting preferences fiscal years opening balances setup company info">
                <h3 class="hc-card-title"><i class="fas fa-sliders-h" style="color: #ec4899;"></i> Accounting Preferences & Fiscal Years</h3>
                <p class="hc-card-desc">Configure default GL accounts, manage fiscal years (e.g. FY 82/83), soft/hard close periods, and set opening balances.</p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="?page=setup/accounting_preferences" class="hc-link-btn"><i class="fas fa-cog"></i> Preferences</a>
                    <a href="?page=setup/fiscal_years" class="hc-link-btn"><i class="fas fa-calendar"></i> Fiscal Years</a>
                    <a href="?page=setup/opening_balances" class="hc-link-btn"><i class="fas fa-balance-scale"></i> Opening Balances</a>
                </div>
            </div>

        </div>
    </div>

</div>


    <!-- 7. ACCOUNTING & JOURNAL ENTRY HELP GUIDE FOR SHOP ERP -->
    <div class="hc-section doc-item" id="section-entry-guide" data-keywords="accounting journal entry help guide shop erp debit credit rules assets liabilities equity revenue expense cogs loans capital drawings vat tax payroll prepaid accrued depreciation mistakes">
        <div class="hc-section-header" style="background: linear-gradient(135deg, #1e1b4b, #312e81); color: white; margin: -24px -24px 24px -24px; padding: 20px 24px; border-radius: 12px 12px 0 0;">
            <div>
                <h2 class="hc-section-title" style="color: #f59e0b; font-size: 20px;">
                    <i class="fas fa-book-open"></i>
                    7. Accounting & Journal Entry Official User Guide for Shop ERP
                </h2>
                <p style="margin: 4px 0 0 0; font-size: 12px; color: #cbd5e1;">
                    Comprehensive practical guide to double-entry bookkeeping, debit/credit rules, shop transactions, financial statement impact, and accounting validations.
                </p>
            </div>
            <span class="hc-badge" style="background: #f59e0b; color: #78350f; font-size: 12px; padding: 4px 10px;">Official Guide</span>
        </div>

        <!-- Objective & Core Purpose -->
        <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 14px 18px; border-radius: 6px; margin-bottom: 24px; font-size: 13px; color: #1e3a8a;">
            <strong><i class="fas fa-bullseye"></i> Document Objective:</strong>
            This guide teaches shop owners, accountants, cashiers, and managers how to record journal entries correctly so that General Ledger, Trial Balance, Profit & Loss, Balance Sheet, Cash/Bank, Accounts Receivable, Accounts Payable, Inventory Valuation, Owner Capital, and Tax Reports remain 100% accurate and reconciled across all periods and locations.
        </div>

        <!-- Part 1: Accounting Equation -->
        <div style="margin-bottom: 28px;">
            <h3 style="font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                1. The Accounting Equation
            </h3>
            <div style="background: #f8fafc; border: 2px dashed #cbd5e1; padding: 16px; border-radius: 10px; text-align: center; margin-bottom: 16px;">
                <span style="font-size: 22px; font-weight: 800; color: #1e293b; letter-spacing: 0.5px;">
                    Assets (NPR) = Liabilities (NPR) + Equity (NPR)
                </span>
            </div>
            
            <div class="hc-grid-3">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 14px; border-radius: 8px;">
                    <h4 style="margin:0 0 6px 0; color:#166534; font-size:14px;"><i class="fas fa-coins"></i> Assets (What Shop Owns)</h4>
                    <p style="font-size:12px; color:#15803d; margin:0 0 8px 0;">Resource owned that provides future economic value.</p>
                    <ul style="font-size:12px; color:#166534; margin:0; padding-left:16px; line-height:1.6;">
                        <li><strong>Cash (Acc 2):</strong> Physical cash in register drawer. [Increases: DR, Decreases: CR]</li>
                        <li><strong>Bank (Acc 3):</strong> Prabhu / Bank funds. [Increases: DR, Decreases: CR]</li>
                        <li><strong>eSewa / Digital (Acc 4):</strong> Digital wallet balances. [Increases: DR, Decreases: CR]</li>
                        <li><strong>Accounts Receivable (Acc 6):</strong> Money owed by credit customers. [Increases: DR, Decreases: CR]</li>
                        <li><strong>Inventory Asset (Acc 7):</strong> Stock items on shelves at cost price. [Increases: DR, Decreases: CR]</li>
                        <li><strong>Fixed Assets (Acc 8):</strong> Equipment, refrigerators, computers. [Increases: DR, Decreases: CR]</li>
                        <li><strong>Accumulated Depreciation (Acc 10):</strong> Contra-Asset reducing asset value. [Increases: CR, Decreases: DR]</li>
                    </ul>
                </div>

                <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 14px; border-radius: 8px;">
                    <h4 style="margin:0 0 6px 0; color:#991b1b; font-size:14px;"><i class="fas fa-file-invoice"></i> Liabilities (What Shop Owes)</h4>
                    <p style="font-size:12px; color:#b91c1c; margin:0 0 8px 0;">External financial obligations payable to outside parties.</p>
                    <ul style="font-size:12px; color:#991b1b; margin:0; padding-left:16px; line-height:1.6;">
                        <li><strong>Accounts Payable (Acc 12):</strong> Money owed to suppliers for goods. [Increases: CR, Decreases: DR]</li>
                        <li><strong>VAT Payable (Acc 13):</strong> Net VAT collected payable to Inland Revenue. [Increases: CR, Decreases: DR]</li>
                        <li><strong>Bank Loans (Acc 16):</strong> Formal bank financing. [Increases: CR, Decreases: DR]</li>
                        <li><strong>Owner Loans (Acc 19):</strong> Temporary loan from owner. [Increases: CR, Decreases: DR]</li>
                        <li><strong>Accrued Expenses:</strong> Incurred unpaid bills (e.g. unpaid rent/electricity). [Increases: CR, Decreases: DR]</li>
                    </ul>
                </div>

                <div style="background: #fefce8; border: 1px solid #fef08a; padding: 14px; border-radius: 8px;">
                    <h4 style="margin:0 0 6px 0; color:#854d0e; font-size:14px;"><i class="fas fa-user-shield"></i> Equity (Owner Claim)</h4>
                    <p style="font-size:12px; color:#a16207; margin:0 0 8px 0;">Net worth belonging to shop owner after subtracting liabilities.</p>
                    <ul style="font-size:12px; color:#854d0e; margin:0; padding-left:16px; line-height:1.6;">
                        <li><strong>Owner Capital (Acc 21):</strong> Permanent investment into shop. [Increases: CR, Decreases: DR]</li>
                        <li><strong>Retained Earnings (Acc 22):</strong> Accumulated prior-period profits. [Increases: CR, Decreases: DR]</li>
                        <li><strong>Current Year Profit (Acc 23):</strong> Net Income earned this period. [Increases: CR, Decreases: DR]</li>
                        <li><strong>Owner Drawings (Acc 20):</strong> Cash/goods withdrawn for personal use. [Increases: DR, Decreases: CR]</li>
                    </ul>
                </div>
            </div>
            
            <div style="margin-top: 10px; background: #fffbe0; border: 1px solid #ffe58f; padding: 10px 14px; border-radius: 6px; font-size: 12px; color: #855900;">
                <strong><i class="fas fa-lightbulb"></i> Critical Capital vs Loan Distinction:</strong>
                An owner's temporary advance to cover cash flow is recorded as an <strong>Owner Loan (Liability - Acc 19)</strong>. Permanent investment into business capital is recorded as <strong>Owner Capital (Equity - Acc 21)</strong>. Personal money withdrawn is <strong>Owner Drawings (Equity Reduction - Acc 20)</strong> and is <u>NEVER a business expense</u>.
            </div>
        </div>

        <!-- Part 2: Debit and Credit Reference Rules -->
        <div style="margin-bottom: 28px;">
            <h3 style="font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                2. Master Debit & Credit Rules Reference Table
            </h3>
            
            <table class="ns-table" style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 12px;">
                <thead>
                    <tr style="background: #1e293b; color: white;">
                        <th style="padding: 10px 14px;">Account Type</th>
                        <th style="padding: 10px 14px;">To Increase</th>
                        <th style="padding: 10px 14px;">To Decrease</th>
                        <th style="padding: 10px 14px;">Normal Balance</th>
                        <th style="padding: 10px 14px;">ERP Example Accounts</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 10px 14px; font-weight:700; color:#2563eb;">Asset</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#16a34a;">DEBIT</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#dc2626;">CREDIT</td>
                        <td style="padding: 10px 14px; font-weight:700;">Debit</td>
                        <td style="padding: 10px 14px;">Cash (Acc 2), Bank (Acc 3), Accounts Receivable (Acc 6), Inventory (Acc 7)</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                        <td style="padding: 10px 14px; font-weight:700; color:#dc2626;">Liability</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#16a34a;">CREDIT</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#dc2626;">DEBIT</td>
                        <td style="padding: 10px 14px; font-weight:700;">Credit</td>
                        <td style="padding: 10px 14px;">Accounts Payable (Acc 12), VAT Payable (Acc 13), Loans (Acc 16/19)</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 10px 14px; font-weight:700; color:#9333ea;">Equity</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#16a34a;">CREDIT</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#dc2626;">DEBIT</td>
                        <td style="padding: 10px 14px; font-weight:700;">Credit</td>
                        <td style="padding: 10px 14px;">Owner Capital (Acc 21), Retained Earnings (Acc 22), Current Year Income (Acc 23)</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                        <td style="padding: 10px 14px; font-weight:700; color:#16a34a;">Revenue / Income</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#16a34a;">CREDIT</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#dc2626;">DEBIT</td>
                        <td style="padding: 10px 14px; font-weight:700;">Credit</td>
                        <td style="padding: 10px 14px;">Sales Income (Acc 25), Other Operating Income (Acc 24)</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 10px 14px; font-weight:700; color:#d97706;">Expense</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#16a34a;">DEBIT</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#dc2626;">CREDIT</td>
                        <td style="padding: 10px 14px; font-weight:700;">Debit</td>
                        <td style="padding: 10px 14px;">COGS (Acc 26), Rent (Acc 30), Salaries (Acc 31), Electricity (Acc 32)</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                        <td style="padding: 10px 14px; font-weight:700; color:#64748b;">Contra Asset</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#16a34a;">CREDIT</td>
                        <td style="padding: 10px 14px; font-weight:700; color:#dc2626;">DEBIT</td>
                        <td style="padding: 10px 14px; font-weight:700;">Credit</td>
                        <td style="padding: 10px 14px;">Accumulated Depreciation (Acc 10)</td>
                    </tr>
                </tbody>
            </table>
            
            <p style="font-size:12px; color:#64748b; margin:0;">
                <strong>Golden Rule:</strong> "Debit" does not mean good/plus and "Credit" does not mean bad/minus. They simply denote left side (Debit) vs right side (Credit) of the double-entry accounting ledger.
            </p>
        </div>

        <!-- Part 3: Basic Journal Entry Structure -->
        <div style="margin-bottom: 28px;">
            <h3 style="font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                3. Basic Double-Entry Journal Structure
            </h3>
            <p style="font-size:12.5px; color:#475569;">
                Every journal entry must strictly satisfy <code>Total Debits == Total Credits</code>.
            </p>
            <div class="hc-code-block">
Example 1: Owner invests NPR 500,000 Cash into Shop Business
----------------------------------------------------------------------
Dr Cash Account (Acc 2)                            NPR 500,000.00
    Cr Owner Capital Account (Acc 21)                  NPR 500,000.00

Why?
- Cash is an Asset and increased -> DEBIT NPR 500,000
- Owner Capital is Equity and increased -> CREDIT NPR 500,000
- Financial Statement Impact: Assets +500,000, Equity +500,000. P&L Impact: ZERO.
            </div>
        </div>

        <!-- Part 4: Shop-Specific Journal Entry Examples -->
        <div style="margin-bottom: 28px;">
            <h3 style="font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                4. Shop-Specific Real-World Journal Entry Examples
            </h3>

            <!-- A. Owner Capital -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-money-bill-wave" style="color:#16a34a;"></i> A. Owner Capital Transactions</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event:</strong> Owner deposits NPR 200,000 directly into shop Prabhu Bank account.<br>
                    <strong>What Happened?</strong> Bank Asset increased; Owner Capital Equity increased.<br>
                    <strong>Journal Entry:</strong>
                    <code>Dr Prabhu Bank (Acc 3) 200,000 / Cr Owner Capital (Acc 21) 200,000</code><br>
                    <strong>Report Impact:</strong> Balance Sheet Assets ↑ 200,000, Equity ↑ 200,000. P&L Impact = 0.<br>
                    <span style="color:#b91c1c;"><strong>Common Mistake:</strong> Do NOT record capital investment as Sales Income! It is NOT revenue.</span>
                </div>
            </div>

            <!-- B. Owner Drawings -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-hand-holding-water" style="color:#dc2626;"></i> B. Owner Drawings / Withdrawals</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event:</strong> Owner takes NPR 15,000 cash from shop register for personal household use.<br>
                    <strong>What Happened?</strong> Cash Asset decreased; Owner Drawings (Equity reduction) increased.<br>
                    <strong>Journal Entry:</strong>
                    <code>Dr Owner Drawings (Acc 20) 15,000 / Cr Cash (Acc 2) 15,000</code><br>
                    <strong>Report Impact:</strong> Balance Sheet Assets ↓ 15,000, Equity ↓ 15,000. P&L Impact = 0.<br>
                    <span style="color:#b91c1c;"><strong>Common Mistake:</strong> Never record drawings as "Miscellaneous Expense". Personal withdrawals do NOT reduce Net Profit!</span>
                </div>
            </div>

            <!-- C. Loans & Interest -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-university" style="color:#9333ea;"></i> C. Bank Loans, Repayments & Interest</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event 1 (Receive Loan):</strong> Shop receives NPR 300,000 loan from Sahakari.<br>
                    <code>Dr Prabhu Bank (Acc 3) 300,000 / Cr Bank Loan (Acc 16) 300,000</code> [Assets ↑, Liabilities ↑]<br><br>
                    <strong>Event 2 (Repay Principal + Interest):</strong> Repay NPR 20,000 loan principal + NPR 3,000 monthly interest from bank.<br>
                    <code>Dr Bank Loan (Acc 16) 20,000 (Liability Reduction)</code><br>
                    <code>Dr Interest Expense (Acc 35) 3,000 (Operating Expense)</code><br>
                    <code>Cr Prabhu Bank (Acc 3) 23,000 (Asset Decrease)</code><br>
                    <span style="color:#b91c1c;"><strong>Critical Rule:</strong> Loan Principal Repayment (20,000) is NOT an expense! Only Interest (3,000) is a P&L Operating Expense.</span>
                </div>
            </div>

            <!-- D. Cash & Bank Transfers -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-exchange-alt" style="color:#0284c7;"></i> D. Cash & Bank Transfers</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event:</strong> Deposit NPR 50,000 register cash into Prabhu Bank account.<br>
                    <code>Dr Prabhu Bank (Acc 3) 50,000 / Cr Cash (Acc 2) 50,000</code><br>
                    <strong>Report Impact:</strong> Total Assets remain unchanged. P&L Impact = 0.<br>
                    <span style="color:#b91c1c;"><strong>Common Mistake:</strong> Internal money transfers do NOT create income or expense!</span>
                </div>
            </div>

            <!-- E. Purchases & Inventory Intake -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-shopping-cart" style="color:#d97706;"></i> E. Purchases & Stock Intake</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event:</strong> Purchase NPR 100,000 liquor inventory on credit from ABC Suppliers + 13% VAT (NPR 13,000).<br>
                    <code>Dr Inventory Asset (Acc 7) 100,000 (Asset Increase)</code><br>
                    <code>Dr VAT Receivable / Input VAT 13,000 (Asset/Tax Claim)</code><br>
                    <code>Cr Accounts Payable (Acc 12) 113,000 (Liability Increase)</code><br>
                    <span style="color:#b91c1c;"><strong>Critical Cost Rule:</strong> Buying stock increases Inventory Asset on the Balance Sheet. Stock is NOT an expense until it is SOLD!</span>
                </div>
            </div>

            <!-- F. Sales & COGS -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-cash-register" style="color:#16a34a;"></i> F. Retail / B2B Sales & COGS Matching</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event:</strong> Sell goods for NPR 150,000 cash. Moving-average cost of goods sold is NPR 100,000.<br>
                    <strong>Part 1 (Revenue Recognition):</strong><br>
                    <code>Dr Cash (Acc 2) 150,000 / Cr Sales Income (Acc 25) 150,000</code><br>
                    <strong>Part 2 (Cost & Stock Matching):</strong><br>
                    <code>Dr Cost of Goods Sold - COGS (Acc 26) 100,000 / Cr Inventory Asset (Acc 7) 100,000</code><br>
                    <strong>Report Impact:</strong> Sales Revenue +150,000, COGS Expense +100,000 $\rightarrow$ Gross Profit = NPR 50,000.
                </div>
            </div>

            <!-- G & H. AR/AP Payments -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-receipt" style="color:#2563eb;"></i> G & H. Customer Receivables & Supplier Payables</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event 1 (Receive Customer Payment):</strong> Customer pays NPR 40,000 against open invoice.<br>
                    <code>Dr Cash/Bank (Acc 2/3) 40,000 / Cr Accounts Receivable (Acc 6) 40,000</code><br><br>
                    <strong>Event 2 (Pay Supplier Bill):</strong> Pay NPR 80,000 to ABC Supplier from bank.<br>
                    <code>Dr Accounts Payable (Acc 12) 80,000 / Cr Prabhu Bank (Acc 3) 80,000</code><br>
                    <span style="color:#b91c1c;"><strong>Common Mistake:</strong> Customer payments & supplier bill payments settle open balances; they do NOT alter P&L Sales or Purchases.</span>
                </div>
            </div>

            <!-- I. Operating Expenses -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-bolt" style="color:#eab308;"></i> I. Monthly Operating Expenses</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event:</strong> Pay monthly shop rent NPR 35,000 and electricity bill NPR 6,500 from bank.<br>
                    <code>Dr Rent Expense (Acc 30) 35,000</code><br>
                    <code>Dr Electricity & Utilities (Acc 32) 6,500</code><br>
                    <code>Cr Prabhu Bank (Acc 3) 41,500</code><br>
                    <strong>Report Impact:</strong> Operating Expenses ↑ 41,500 $\rightarrow$ Net Profit ↓ 41,500.
                </div>
            </div>

            <!-- J. Fixed Assets & Depreciation -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-desktop" style="color:#6366f1;"></i> J. Fixed Assets & Monthly Depreciation</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event 1 (Buy Refrigerator):</strong> Buy shop display chiller for NPR 80,000 cash.<br>
                    <code>Dr Fixed Assets - Equipment (Acc 8) 80,000 / Cr Cash (Acc 2) 80,000</code> [Capital Expenditure]<br><br>
                    <strong>Event 2 (Monthly Depreciation):</strong> Record NPR 1,500 monthly depreciation.<br>
                    <code>Dr Depreciation Expense (Acc 29) 1,500 / Cr Accumulated Depreciation (Acc 10) 1,500</code><br>
                    <span style="color:#b91c1c;"><strong>Critical Rule:</strong> Buying long-term equipment is an Asset purchase, NOT an immediate expense. Value is expensed over time via Depreciation.</span>
                </div>
            </div>

            <!-- K. Inventory Adjustments & Stock Damage -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-boxes" style="color:#0d9488;"></i> K. Damaged / Expired Stock & Adjustments</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event:</strong> Write off NPR 4,200 broken liquor bottles discovered during physical stock count.<br>
                    <code>Dr Inventory Shrinkage/Damage Expense (Acc 27) 4,200 / Cr Inventory Asset (Acc 7) 4,200</code><br>
                    <strong>Report Impact:</strong> Stock Valuation ↓ 4,200, Operating Expenses ↑ 4,200.
                </div>
            </div>

            <!-- L. VAT / Tax Settlement -->
            <div style="background: #fafafa; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="margin:0 0 6px 0; color:#1e293b; font-size:14px;"><i class="fas fa-percent" style="color:#ec4899;"></i> L. VAT / Tax Accounting & Settlement</h4>
                <div style="font-size:12px; color:#334155; line-height:1.5;">
                    <strong>Event:</strong> Settle net VAT liability. Sales Output VAT collected = NPR 52,000; Purchase Input VAT paid = NPR 38,000. Net payable = NPR 14,000.<br>
                    <code>Dr Sales Output VAT Payable (Acc 13) 52,000</code><br>
                    <code>Cr Purchase Input VAT Asset 38,000</code><br>
                    <code>Cr Cash/Bank (Acc 2/3) 14,000</code><br>
                    <strong>Report Impact:</strong> Tax Liabilities cleared; Bank Asset ↓ 14,000. P&L Impact = 0.
                </div>
            </div>

        </div>

        <!-- Part 5: Retained Earnings & Profit Equation -->
        <div style="margin-bottom: 28px;">
            <h3 style="font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                5. Retained Earnings & Profit Reconciliation Logic
            </h3>
            <div style="background: #f1f5f9; padding: 14px; border-radius: 8px; font-size: 12.5px; color: #334155;">
                <strong>Profit Formula:</strong> <code>Net Profit = Sales Revenue - COGS - Operating Expenses + Other Income</code><br><br>
                At period closing, Net Profit automatically flows into <strong>Current Year Net Income (Acc 23)</strong> and subsequently transfers into <strong>Retained Earnings (Acc 22)</strong> on the Balance Sheet.<br>
                <span style="color:#b91c1c;"><strong>Mandatory Rule:</strong> Never post normal daily sales or expenses directly into Retained Earnings (Acc 22). Retained Earnings is reserved strictly for prior-year accumulated profits and formal period-closing entries.</span>
            </div>
        </div>

        <!-- Part 6: Journal Entry Classification Decision Table -->
        <div style="margin-bottom: 28px;">
            <h3 style="font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                6. Journal Entry Quick Classification Table
            </h3>
            
            <table class="ns-table" style="width: 100%; border-collapse: collapse; font-size: 12.5px;">
                <thead>
                    <tr style="background: #0f172a; color: white;">
                        <th style="padding: 8px 12px;">Business Event</th>
                        <th style="padding: 8px 12px;">Debit Account (DR)</th>
                        <th style="padding: 8px 12px;">Credit Account (CR)</th>
                        <th style="padding: 8px 12px;">P&L Impact?</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom: 1px solid #e2e8f0;"><td>Owner invests capital cash</td><td>Cash (Acc 2)</td><td>Owner Capital (Acc 21)</td><td>No (BS Only)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background:#f8fafc;"><td>Receive bank loan</td><td>Prabhu Bank (Acc 3)</td><td>Bank Loan (Acc 16)</td><td>No (BS Only)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;"><td>Repay loan principal</td><td>Bank Loan (Acc 16)</td><td>Prabhu Bank (Acc 3)</td><td>No (BS Only)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background:#f8fafc;"><td>Pay loan interest</td><td>Interest Expense (Acc 35)</td><td>Prabhu Bank (Acc 3)</td><td>Yes (Expense ↑)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;"><td>Cash retail sale</td><td>Cash (Acc 2)</td><td>Sales Income (Acc 25)</td><td>Yes (Revenue ↑)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background:#f8fafc;"><td>Record sale COGS</td><td>Cost of Goods Sold (Acc 26)</td><td>Inventory Asset (Acc 7)</td><td>Yes (COGS ↑)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;"><td>Credit B2B sale</td><td>Accounts Receivable (Acc 6)</td><td>Sales Income (Acc 25)</td><td>Yes (Revenue ↑)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background:#f8fafc;"><td>Receive customer payment</td><td>Cash / Bank (Acc 2/3)</td><td>Accounts Receivable (Acc 6)</td><td>No (BS Only)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;"><td>Credit stock purchase</td><td>Inventory Asset (Acc 7)</td><td>Accounts Payable (Acc 12)</td><td>No (BS Only)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background:#f8fafc;"><td>Pay supplier bill</td><td>Accounts Payable (Acc 12)</td><td>Prabhu Bank (Acc 3)</td><td>No (BS Only)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;"><td>Pay shop rent / utilities</td><td>Rent Expense (Acc 30)</td><td>Prabhu Bank (Acc 3)</td><td>Yes (Expense ↑)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background:#f8fafc;"><td>Buy computer / equipment</td><td>Fixed Assets (Acc 8)</td><td>Prabhu Bank (Acc 3)</td><td>No (BS Only)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;"><td>Record monthly depreciation</td><td>Depreciation Expense (Acc 29)</td><td>Accumulated Depreciation (Acc 10)</td><td>Yes (Expense ↑)</td></tr>
                    <tr style="border-bottom: 1px solid #e2e8f0; background:#f8fafc;"><td>Owner cash withdrawal</td><td>Owner Drawings (Acc 20)</td><td>Cash (Acc 2)</td><td>No (BS Only)</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Part 7: What Should NOT Be a Manual Journal Entry -->
        <div style="margin-bottom: 28px;">
            <h3 style="font-size: 16px; color: #dc2626; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                7. What Should NOT Be a Manual Journal Entry (Prevent Duplicate Entries)
            </h3>
            <div style="background: #fff5f5; border-left: 4px solid #ef4444; padding: 14px; border-radius: 6px; font-size: 12.5px; color: #991b1b;">
                <strong><i class="fas fa-exclamation-triangle"></i> WARNING: Avoid Double-Posting Transactions!</strong><br>
                The ERP's operational modules automatically generate General Ledger postings upon save. You should <u>NEVER create a manual journal entry</u> for:
                <ul style="margin: 6px 0 0 0; padding-left: 18px;">
                    <li><strong>POS Counter Sales:</strong> Already posted by POS Terminal (`pos_entry`).</li>
                    <li><strong>Sales Invoices:</strong> Already posted by Customer Invoice module (`customer_invoices`).</li>
                    <li><strong>Vendor Bills:</strong> Already posted by Vendor Bill module (`vendor_bills`).</li>
                    <li><strong>Customer Payments:</strong> Already posted by Receive Payment module (`payments`).</li>
                    <li><strong>Supplier Payments:</strong> Already posted by Vendor Payment module (`payments`).</li>
                    <li><strong>Inter-Store Stock Transfers:</strong> Already posted by Inventory Transfer module (`inventory_transfers`).</li>
                </ul>
                <em style="color:#7f1d1d;">Creating manual journal entries for module transactions causes double-counting of revenue, expenses, cash, and inventory!</em>
            </div>
        </div>

        <!-- Part 8: Manual Journal Entry Rules & Validation Checklist -->
        <div style="margin-bottom: 28px;">
            <h3 style="font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                8. Manual Journal Entry Validation Checklist
            </h3>
            <div class="hc-grid-2">
                <div style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 14px; border-radius: 8px; font-size: 12px; color: #334155;">
                    <strong>Pre-Posting Verification Checklist:</strong>
                    <ol style="margin: 4px 0 0 0; padding-left: 18px;">
                        <li><strong>Valid Transaction Date:</strong> Ensure date falls in an open fiscal period.</li>
                        <li><strong>Reference Number:</strong> Assign unique JV reference (e.g. `JV-GOKA-00021`).</li>
                        <li><strong>Clear Description / Memo:</strong> Explain the exact business reason for the entry.</li>
                        <li><strong>Correct Account Selection:</strong> Select valid active GL accounts.</li>
                        <li><strong>Location Assignment:</strong> Assign correct store location (e.g. Gokarna).</li>
                        <li><strong>Double-Entry Balance:</strong> <code>Total Debit Amount == Total Credit Amount</code>.</li>
                    </ol>
                </div>

                <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 14px; border-radius: 8px; font-size: 12px; color: #991b1b;">
                    <strong>Automatic ERP System Blockers:</strong>
                    <ul style="margin: 4px 0 0 0; padding-left: 18px;">
                        <li>🚫 <strong>Unbalanced Entry:</strong> Blocked if <code>SUM(Debit) != SUM(Credit)</code>.</li>
                        <li>🚫 <strong>Closed Period:</strong> Blocked if posting to a locked/closed fiscal year.</li>
                        <li>🚫 <strong>Inactive Account:</strong> Blocked if account is deleted or inactive.</li>
                        <li>🚫 <strong>Control Account Direct Edit:</strong> Blocked if AR/AP control accounts are edited without specifying entity party.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Part 9: Common Accounting Mistakes & Troubleshooting -->
        <div style="margin-bottom: 24px;">
            <h3 style="font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;">
                9. Common Accounting Mistakes & Troubleshooting Guide
            </h3>
            
            <div class="hc-grid-2">
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 12px; color: #334155;">
                    <strong style="color: #dc2626;"><i class="fas fa-times-circle"></i> Mistake 1: Recording Loan as Income</strong><br>
                    <em>Wrong:</em> `Cr Sales Income` when bank loan arrives.<br>
                    <span style="color:#16a34a;"><strong>Correct:</strong> `Cr Bank Loan (Acc 16)`. Loans are liabilities, not revenue!</span>
                </div>

                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 12px; color: #334155;">
                    <strong style="color: #dc2626;"><i class="fas fa-times-circle"></i> Mistake 2: Recording Drawings as Expense</strong><br>
                    <em>Wrong:</em> `Dr Miscellaneous Expense` when owner takes cash.<br>
                    <span style="color:#16a34a;"><strong>Correct:</strong> `Dr Owner Drawings (Acc 20)`. Drawings reduce equity, not net profit!</span>
                </div>

                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 12px; color: #334155;">
                    <strong style="color: #dc2626;"><i class="fas fa-times-circle"></i> Mistake 3: Expensing Asset Purchases</strong><br>
                    <em>Wrong:</em> `Dr Equipment Expense` when buying NPR 80k refrigerator.<br>
                    <span style="color:#16a34a;"><strong>Correct:</strong> `Dr Fixed Assets (Acc 8)`. Expense over time via monthly depreciation!</span>
                </div>

                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 12px; color: #334155;">
                    <strong style="color: #dc2626;"><i class="fas fa-times-circle"></i> Mistake 4: Loan Repayment as Expense</strong><br>
                    <em>Wrong:</em> `Dr Bank Expense` for full loan repayment amount.<br>
                    <span style="color:#16a34a;"><strong>Correct:</strong> `Dr Bank Loan (Acc 16)` for principal + `Dr Interest Expense (Acc 35)` for interest.</span>
                </div>
            </div>
        </div>

    </div>

<script>
function hcFilterDocs(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.doc-item').forEach(item => {
        const kw = (item.getAttribute('data-keywords') || '').toLowerCase();
        const text = item.innerText.toLowerCase();
        if (!q || kw.includes(q) || text.includes(q)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}
</script>
