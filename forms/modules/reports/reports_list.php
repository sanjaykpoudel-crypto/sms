<?php
// Reports Index Page - All Reports
?>
<style>
    .reports-page { padding: 24px; background: #f4f7fa; min-height: 80vh; }
    .reports-page-header {
        display: flex; align-items: center; gap: 14px;
        margin-bottom: 28px;
    }
    .reports-page-header h1 {
        font-size: 20px; font-weight: 800; color: #2c3e50; margin: 0;
        letter-spacing: 0.3px;
    }
    .reports-page-header .header-icon {
        width: 44px; height: 44px; background: linear-gradient(135deg, #3498db, #2ecc71);
        border-radius: 12px; display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 20px; box-shadow: 0 4px 12px rgba(52,152,219,0.3);
    }

    .reports-categories { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 22px; }

    .report-category-card {
        background: #fff; border-radius: 16px;
        box-shadow: 0 4px 18px rgba(0,0,0,0.05);
        border: 1px solid #eef2f6; overflow: hidden;
        transition: box-shadow 0.3s;
    }
    .report-category-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,0.09); }

    .report-category-header {
        padding: 14px 20px;
        display: flex; align-items: center; gap: 12px;
        border-bottom: 1px solid #f1f4f8;
    }
    .report-category-header .cat-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; color: #fff; flex-shrink: 0;
    }
    .report-category-header h3 {
        font-size: 13px; font-weight: 800; color: #34495e; margin: 0;
        text-transform: uppercase; letter-spacing: 0.5px;
    }

    .report-links-list { padding: 8px 0; }
    .report-link-item {
        display: flex; align-items: center; gap: 12px;
        padding: 11px 20px;
        text-decoration: none; color: #2c3e50;
        font-size: 13px; font-weight: 500;
        border-bottom: 1px solid #f8f9fb;
        transition: all 0.2s;
        position: relative;
    }
    .report-link-item:last-child { border-bottom: none; }
    .report-link-item:hover {
        background: #f0f7ff;
        color: #3498db;
        padding-left: 26px;
    }
    .report-link-item .rpt-dot {
        width: 7px; height: 7px; border-radius: 50%;
        flex-shrink: 0;
        transition: transform 0.2s;
    }
    .report-link-item:hover .rpt-dot { transform: scale(1.4); }
    .report-link-item .rpt-arrow {
        margin-left: auto; font-size: 10px; opacity: 0;
        transition: opacity 0.2s, transform 0.2s;
        color: #3498db;
        transform: translateX(-4px);
    }
    .report-link-item:hover .rpt-arrow { opacity: 1; transform: translateX(0); }
    .report-link-item .rpt-desc {
        font-size: 10px; color: #95a5a6; font-weight: 400;
        display: block; margin-top: 1px;
    }
</style>

<div class="reports-page">
    <div class="reports-page-header">
        <div class="header-icon"><i class="fas fa-chart-bar"></i></div>
        <div>
            <h1>Reports Center</h1>
            <div style="font-size: 12px; color: #7f8c8d; margin-top: 2px;">Browse and access all available reports</div>
        </div>
    </div>

    <div class="reports-categories">

        <!-- Financial Reports -->
        <div class="report-category-card">
            <div class="report-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <h3>Financial Reports</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">9 Reports</div>
                </div>
            </div>
            <div class="report-links-list">
                <a href="?page=reports/financial/balance_sheet" class="report-link-item">
                    <span class="rpt-dot" style="background:#3498db;"></span>
                    <span>
                        Balance Sheet
                        <span class="rpt-desc">Assets, liabilities and equity overview</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/financial/comparative_balance_sheet" class="report-link-item">
                    <span class="rpt-dot" style="background:#1abc9c;"></span>
                    <span>
                        Comparative Balance Sheet
                        <span class="rpt-desc">Compare assets, liabilities and equity with the previous fiscal year</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/financial/income_statement" class="report-link-item">
                    <span class="rpt-dot" style="background:#2ecc71;"></span>
                    <span>
                        Income Statement
                        <span class="rpt-desc">Revenue, expenses and net profit</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/financial/comparative_income" class="report-link-item">
                    <span class="rpt-dot" style="background:#2ecc71;"></span>
                    <span>
                        Comparative Income Statement
                        <span class="rpt-desc">Compare revenue/expenses with the previous fiscal year</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/financial/daily_profit" class="report-link-item">
                    <span class="rpt-dot" style="background:#f39c12;"></span>
                    <span>
                        Daily Profit Report
                        <span class="rpt-desc">Day-by-day profit breakdown</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/financial/trial_balance" class="report-link-item">
                    <span class="rpt-dot" style="background:#9b59b6;"></span>
                    <span>
                        Trial Balance
                        <span class="rpt-desc">Debit and credit balance of all accounts</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/financial/general_ledger" class="report-link-item">
                    <span class="rpt-dot" style="background:#e74c3c;"></span>
                    <span>
                        General Ledger
                        <span class="rpt-desc">All journal entries by account</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/financial/cash_book" class="report-link-item">
                    <span class="rpt-dot" style="background:#1abc9c;"></span>
                    <span>
                        Cash Book
                        <span class="rpt-desc">Cash & Bank running account ledgers</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/financial/equity_statement" class="report-link-item">
                    <span class="rpt-dot" style="background:#9b59b6;"></span>
                    <span>
                        Equity Statement
                        <span class="rpt-desc">Statement of changes in Owner's Equity</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Sales Reports -->
        <div class="report-category-card">
            <div class="report-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #2ecc71, #16a085);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div>
                    <h3>Sales Reports</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">5 Reports</div>
                </div>
            </div>
            <div class="report-links-list">
                <a href="?page=reports/sales/by_item" class="report-link-item">
                    <span class="rpt-dot" style="background:#2ecc71;"></span>
                    <span>
                        Sales by Item
                        <span class="rpt-desc">Itemwise sales quantity and amount</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/sales/top_profit_items" class="report-link-item">
                    <span class="rpt-dot" style="background:#f39c12;"></span>
                    <span>
                        Top Profit Items
                        <span class="rpt-desc">Items with highest gross profit</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/sales/by_customer" class="report-link-item">
                    <span class="rpt-dot" style="background:#3498db;"></span>
                    <span>
                        Sales by Customer
                        <span class="rpt-desc">Customer-wise sales summary</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/sales/register" class="report-link-item">
                    <span class="rpt-dot" style="background:#9b59b6;"></span>
                    <span>
                        Sales Register
                        <span class="rpt-desc">Complete list of all sales transactions</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/sales/open_invoices" class="report-link-item">
                    <span class="rpt-dot" style="background:#e67e22;"></span>
                    <span>
                        Open Invoices
                        <span class="rpt-desc">Outstanding customer invoices with open balances</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Purchase Reports -->
        <div class="report-category-card">
            <div class="report-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #9b59b6, #6c3483);">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div>
                    <h3>Purchase Reports</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">2 Reports</div>
                </div>
            </div>
            <div class="report-links-list">
                <a href="?page=reports/purchases/by_item" class="report-link-item">
                    <span class="rpt-dot" style="background:#9b59b6;"></span>
                    <span>
                        Purchase by Item
                        <span class="rpt-desc">Itemwise purchase quantity and cost</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/purchases/by_vendor" class="report-link-item">
                    <span class="rpt-dot" style="background:#6c3483;"></span>
                    <span>
                        Purchase by Vendor
                        <span class="rpt-desc">Vendor-wise purchase summary</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Inventory Reports -->
        <div class="report-category-card">
            <div class="report-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #e67e22, #d35400);">
                    <i class="fas fa-warehouse"></i>
                </div>
                <div>
                    <h3>Inventory Reports</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">8 Reports</div>
                </div>
            </div>
            <div class="report-links-list">
                <a href="?page=reports/inventory/inventory_valuation" class="report-link-item">
                    <span class="rpt-dot" style="background:#e67e22;"></span>
                    <span>
                        Inventory Valuation
                        <span class="rpt-desc">Current inventory values at cost vs retail pricing</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/inventory/stock_summary" class="report-link-item">
                    <span class="rpt-dot" style="background:#d35400;"></span>
                    <span>
                        Current Inventory Snapshot
                        <span class="rpt-desc">Current stock levels of all items</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/inventory/stock_ledger" class="report-link-item">
                    <span class="rpt-dot" style="background:#8e44ad;"></span>
                    <span>
                        Stock Ledger
                        <span class="rpt-desc">Stock movement history per item</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/inventory/inventory_revenue" class="report-link-item">
                    <span class="rpt-dot" style="background:#2ecc71;"></span>
                    <span>
                        Inventory Revenue
                        <span class="rpt-desc">Revenue contribution of each inventory item</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/inventory/inventory_profitability" class="report-link-item">
                    <span class="rpt-dot" style="background:#1abc9c;"></span>
                    <span>
                        Inventory Profitability
                        <span class="rpt-desc">Gross profit and profit margins per item</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/inventory/low_stock" class="report-link-item">
                    <span class="rpt-dot" style="background:#e74c3c;"></span>
                    <span>
                        Low Stock Report
                        <span class="rpt-desc">Items at or below reorder level</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/inventory/less_stock" class="report-link-item">
                    <span class="rpt-dot" style="background:#c0392b;"></span>
                    <span>
                        Less Stock Report
                        <span class="rpt-desc">Items with critically low inventory</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/inventory/urgent_buy" class="report-link-item">
                    <span class="rpt-dot" style="background:#f39c12;"></span>
                    <span>
                        Urgent Purchases
                        <span class="rpt-desc">Items requiring immediate restocking</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
            </div>
        </div>

        <!-- VAT / Tax Reports -->
        <div class="report-category-card">
            <div class="report-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #1abc9c, #16a085);">
                    <i class="fas fa-percent"></i>
                </div>
                <div>
                    <h3>VAT / Tax Reports</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">2 Reports</div>
                </div>
            </div>
            <div class="report-links-list">
                <a href="?page=reports/vat/sales_register" class="report-link-item">
                    <span class="rpt-dot" style="background:#1abc9c;"></span>
                    <span>
                        VAT Sales Register
                        <span class="rpt-desc">Sales with VAT breakdown</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/vat/purchase_register" class="report-link-item">
                    <span class="rpt-dot" style="background:#16a085;"></span>
                    <span>
                        VAT Purchase Register
                        <span class="rpt-desc">Purchases with VAT breakdown</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Vendor Reports -->
        <div class="report-category-card">
            <div class="report-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #8e44ad, #9b59b6);">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div>
                    <h3>Vendor Reports</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">4 Reports</div>
                </div>
            </div>
            <div class="report-links-list">
                <a href="?page=reports/vendors/ap_register" class="report-link-item">
                    <span class="rpt-dot" style="background:#8e44ad;"></span>
                    <span>
                        AP Register
                        <span class="rpt-desc">Complete register of vendor bills and balances</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/vendors/ap_payment_by_bill" class="report-link-item">
                    <span class="rpt-dot" style="background:#2ecc71;"></span>
                    <span>
                        AP Payment by Bill
                        <span class="rpt-desc">Payments applied to specific vendor bills</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/vendors/open_bills" class="report-link-item">
                    <span class="rpt-dot" style="background:#e67e22;"></span>
                    <span>
                        Open Bills
                        <span class="rpt-desc">Outstanding bills with remaining balances</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/vendors/payable_aging" class="report-link-item">
                    <span class="rpt-dot" style="background:#e74c3c;"></span>
                    <span>
                        Accounts Payable (AP) Aging
                        <span class="rpt-desc">Aging of outstanding vendor bills</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Customer Reports -->
        <div class="report-category-card">
            <div class="report-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <h3>Customer Reports</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">4 Reports</div>
                </div>
            </div>
            <div class="report-links-list">
                <a href="?page=reports/customers/statement" class="report-link-item">
                    <span class="rpt-dot" style="background:#e74c3c;"></span>
                    <span>
                        Customer Statement
                        <span class="rpt-desc">Outstanding balance and transaction history per customer</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/customers/ar_register" class="report-link-item">
                    <span class="rpt-dot" style="background:#8e44ad;"></span>
                    <span>
                        AR Register
                        <span class="rpt-desc">Complete register of customer invoices and balances</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/customers/ar_payment_by_invoice" class="report-link-item">
                    <span class="rpt-dot" style="background:#2ecc71;"></span>
                    <span>
                        AR Payment by Invoice
                        <span class="rpt-desc">Customer payments applied to specific invoices</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
                <a href="?page=reports/customers/receivable_aging" class="report-link-item">
                    <span class="rpt-dot" style="background:#3498db;"></span>
                    <span>
                        Accounts Receivable (AR) Aging
                        <span class="rpt-desc">Aging of outstanding customer invoices</span>
                    </span>
                    <i class="fas fa-arrow-right rpt-arrow"></i>
                </a>
            </div>
        </div>

    </div>
</div>
