<?php
// Transactions Center - All Transaction Modules
?>
<style>
    .txn-page { padding: 24px; background: #f4f7fa; min-height: 80vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .txn-page-header {
        display: flex; align-items: center; gap: 14px;
        margin-bottom: 28px;
    }
    .txn-page-header h1 {
        font-size: 20px; font-weight: 800; color: #2c3e50; margin: 0;
        letter-spacing: 0.3px;
    }
    .txn-page-header .header-icon {
        width: 44px; height: 44px; background: linear-gradient(135deg, #0284c7, #38bdf8);
        border-radius: 12px; display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 20px; box-shadow: 0 4px 12px rgba(2,132,199,0.3);
    }

    .txn-categories { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 22px; }

    .txn-category-card {
        background: #fff; border-radius: 16px;
        box-shadow: 0 4px 18px rgba(0,0,0,0.05);
        border: 1px solid #eef2f6; overflow: hidden;
        transition: box-shadow 0.3s;
    }
    .txn-category-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,0.09); }

    .txn-category-header {
        padding: 14px 20px;
        display: flex; align-items: center; gap: 12px;
        border-bottom: 1px solid #f1f4f8;
    }
    .txn-category-header .cat-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; color: #fff; flex-shrink: 0;
    }
    .txn-category-header h3 {
        font-size: 13px; font-weight: 800; color: #34495e; margin: 0;
        text-transform: uppercase; letter-spacing: 0.5px;
    }

    .txn-links-list { padding: 8px 0; }
    .txn-link-item {
        display: flex; align-items: center; gap: 12px;
        padding: 11px 20px;
        text-decoration: none; color: #2c3e50;
        font-size: 13px; font-weight: 500;
        border-bottom: 1px solid #f8f9fb;
        transition: all 0.2s;
    }
    .txn-link-item:last-child { border-bottom: none; }
    .txn-link-item:hover {
        background: #f0f7ff;
        color: #0284c7;
        padding-left: 26px;
    }
    .txn-link-item .txn-dot {
        width: 7px; height: 7px; border-radius: 50%;
        flex-shrink: 0;
        transition: transform 0.2s;
    }
    .txn-link-item:hover .txn-dot { transform: scale(1.4); }
    .txn-link-item .txn-arrow {
        margin-left: auto; font-size: 10px; opacity: 0;
        transition: opacity 0.2s, transform 0.2s;
        color: #0284c7;
        transform: translateX(-4px);
    }
    .txn-link-item:hover .txn-arrow { opacity: 1; transform: translateX(0); }
    .txn-link-item .txn-desc {
        font-size: 10px; color: #95a5a6; font-weight: 400;
        display: block; margin-top: 1px;
    }
    .txn-badge-new {
        background: #0284c7; color: #fff; font-size: 9px; font-weight: 700;
        padding: 2px 6px; border-radius: 4px; text-transform: uppercase; margin-left: 6px;
    }
</style>

<div class="txn-page">
    <div class="txn-page-header">
        <div class="header-icon"><i class="fas fa-exchange-alt"></i></div>
        <div>
            <h1>Transactions Center</h1>
            <div style="font-size: 12px; color: #7f8c8d; margin-top: 2px;">Manage and record all business transactions</div>
        </div>
    </div>

    <div class="txn-categories">

        <!-- Sales Transactions -->
        <div class="txn-category-card">
            <div class="txn-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <h3>Sales Transactions</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">Invoices, POS & Sales Payments</div>
                </div>
            </div>
            <div class="txn-links-list">
                <a href="?page=transactions/invoice/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#10b981;"></span>
                    <span>
                        New Sales Invoice <span class="txn-badge-new">New</span>
                        <span class="txn-desc">Create customer credit or cash sales invoice</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/invoice" class="txn-link-item">
                    <span class="txn-dot" style="background:#059669;"></span>
                    <span>
                        Sales Invoice Register
                        <span class="txn-desc">View and search all customer sales invoices</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/pos/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#f59e0b;"></span>
                    <span>
                        POS Counter Sale <span class="txn-badge-new">POS</span>
                        <span class="txn-desc">Fast counter cash/card checkout terminal</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/payment/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#3b82f6;"></span>
                    <span>
                        Receive Customer Payment
                        <span class="txn-desc">Record payment collections for outstanding invoices</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Purchasing & Vendor Bills -->
        <div class="txn-category-card">
            <div class="txn-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div>
                    <h3>Purchases & Bills</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">Vendor Bills & Payables</div>
                </div>
            </div>
            <div class="txn-links-list">
                <a href="?page=transactions/bill/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#8b5cf6;"></span>
                    <span>
                        New Vendor Bill <span class="txn-badge-new">New</span>
                        <span class="txn-desc">Enter incoming inventory purchase bills</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/bill" class="txn-link-item">
                    <span class="txn-dot" style="background:#6d28d9;"></span>
                    <span>
                        Vendor Bill Register
                        <span class="txn-desc">View and manage all vendor bills</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/payment/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#ec4899;"></span>
                    <span>
                        Pay Vendor Bill
                        <span class="txn-desc">Record payment disbursements to vendors</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/payment" class="txn-link-item">
                    <span class="txn-dot" style="background:#64748b;"></span>
                    <span>
                        Payment Register
                        <span class="txn-desc">History of all incoming and outgoing payments</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Expenses & Banking -->
        <div class="txn-category-card">
            <div class="txn-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #0284c7, #0369a1);">
                    <i class="fas fa-wallet"></i>
                </div>
                <div>
                    <h3>Banking & Expenses</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">Expenses, Transfers & Denominations</div>
                </div>
            </div>
            <div class="txn-links-list">
                <a href="?page=transactions/expense/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#ef4444;"></span>
                    <span>
                        Enter Expense <span class="txn-badge-new">Expense</span>
                        <span class="txn-desc">Record daily operating expenses and cash disbursements</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/expense" class="txn-link-item">
                    <span class="txn-dot" style="background:#dc2626;"></span>
                    <span>
                        Expense Register
                        <span class="txn-desc">View all recorded operating expenses</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/cash_denom/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#f59e0b;"></span>
                    <span>
                        Cash Denomination Entry
                        <span class="txn-desc">Record daily cash drawer physical count</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/cash_denom" class="txn-link-item">
                    <span class="txn-dot" style="background:#d97706;"></span>
                    <span>
                        Cash Count History
                        <span class="txn-desc">Review physical cash count logs</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/transfer/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#0284c7;"></span>
                    <span>
                        Bank Funds Transfer
                        <span class="txn-desc">Transfer funds between cash and bank accounts</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
            </div>
        </div>

        <!-- General Ledger & Accounting -->
        <div class="txn-category-card">
            <div class="txn-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #d97706, #b45309);">
                    <i class="fas fa-book"></i>
                </div>
                <div>
                    <h3>Accounting & Journals</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">General Journal Entries</div>
                </div>
            </div>
            <div class="txn-links-list">
                <a href="?page=transactions/journal/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#d97706;"></span>
                    <span>
                        New Journal Entry <span class="txn-badge-new">JV</span>
                        <span class="txn-desc">Post manual debit/credit journal voucher</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/journal" class="txn-link-item">
                    <span class="txn-dot" style="background:#b45309;"></span>
                    <span>
                        Journal Entry Register
                        <span class="txn-desc">Search and review posted journal entries</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Inventory & Stock Transactions -->
        <div class="txn-category-card">
            <div class="txn-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                    <i class="fas fa-boxes"></i>
                </div>
                <div>
                    <h3>Stock & Inventory</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">Adjustments & Transfers</div>
                </div>
            </div>
            <div class="txn-links-list">
                <a href="?page=transactions/adjustment/manage" class="txn-link-item">
                    <span class="txn-dot" style="background:#06b6d4;"></span>
                    <span>
                        New Stock Adjustment <span class="txn-badge-new">Stock</span>
                        <span class="txn-desc">Adjust physical inventory count, damage or breakage</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
                <a href="?page=transactions/adjustment" class="txn-link-item">
                    <span class="txn-dot" style="background:#0891b2;"></span>
                    <span>
                        Stock Adjustment Register
                        <span class="txn-desc">Review inventory count adjustments log</span>
                    </span>
                    <i class="fas fa-arrow-right txn-arrow"></i>
                </a>
            </div>
        </div>

    </div>
</div>
