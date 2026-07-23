<!-- Enterprise ERP Dashboard V4 -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="dv4" id="dashboard-root">
    <!-- Top Progress Loader -->
    <div class="dv4-loader" id="dv4-loader"></div>

    <!-- ═══════════════════════════════════════════════════════════════════
         PAGE HEADER
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-header">
        <div class="dv4-header-left">
            <div class="dv4-header-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="dv4-header-info">
                <h1 class="dv4-header-title">Executive Dashboard</h1>
                <div class="dv4-header-subtitle">Real-time enterprise analytics & liquor store ERP operations</div>
            </div>
        </div>
        <div class="dv4-header-right">
            <div class="dv4-refresh-info">
                <i class="fas fa-sync fa-spin" style="font-size: 10px; opacity: 0.7;"></i>
                <span>Sync: <strong id="dv4-refresh-time">—</strong></span>
                <span style="opacity: 0.3;">|</span>
                <span>Query: <strong id="dv4-query-time">—</strong></span>
            </div>
            <button class="dv4-btn" id="dv4-theme-btn" title="Toggle Dark/Light Mode">
                <i class="fas fa-moon"></i> Theme
            </button>
            <button class="dv4-btn" id="dv4-refresh-btn" title="Manual Refresh">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         QUICK SHORTCUTS
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-shortcuts">
        <a href="?page=transactions/pos/manage" class="dv4-shortcut"><i class="fas fa-keyboard" style="color: #3b82f6;"></i> POS Terminal</a>
        <a href="?page=transactions/bill/manage" class="dv4-shortcut"><i class="fas fa-truck-loading" style="color: #10b981;"></i> Purchase Bill</a>
        <a href="?page=transactions/invoice/manage" class="dv4-shortcut"><i class="fas fa-file-invoice-dollar" style="color: #8b5cf6;"></i> Invoice</a>
        <a href="?page=transactions/journal/manage" class="dv4-shortcut"><i class="fas fa-book" style="color: #6366f1;"></i> Journal Entry</a>
        <a href="?page=transactions/cash_denom/manage" class="dv4-shortcut"><i class="fas fa-calculator" style="color: #f59e0b;"></i> Cash Count</a>
        <a href="?page=transactions/expense/manage" class="dv4-shortcut"><i class="fas fa-wallet" style="color: #ef4444;"></i> Expense</a>
        <a href="?page=master/customer/manage" class="dv4-shortcut"><i class="fas fa-user-plus" style="color: #ec4899;"></i> Customers</a>
        <a href="?page=reports" class="dv4-shortcut"><i class="fas fa-chart-bar" style="color: #06b6d4;"></i> Reports</a>
    </div>

    <!-- ROW 1 — KPI TILES -->
    <div class="dv4-kpi-grid">
        <!-- Today's Sales -->
        <a class="dv4-kpi kpi-sales dv4-kpi-link" id="kpi-tile-sales" href="#" title="View Sales Register">
            <i class="fas fa-money-bill-wave dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Today's Sales</div>
            <div class="dv4-kpi-value" id="kpi-sales-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-sales-sub"></div>
        </a>
        <!-- Daily Expenses -->
        <a class="dv4-kpi kpi-lowstock dv4-kpi-link" id="kpi-tile-expenses" href="#" title="View Expenses Report">
            <i class="fas fa-wallet dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Daily Expenses</div>
            <div class="dv4-kpi-value" id="kpi-expenses-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-expenses-sub"></div>
        </a>
        <!-- Today's Gross Profit -->
        <a class="dv4-kpi kpi-profit dv4-kpi-link" id="kpi-tile-gross-profit" href="#" title="View Daily P&amp;L Report">
            <i class="fas fa-chart-line dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Today's Gross Profit</div>
            <div class="dv4-kpi-value" id="kpi-gross-profit-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-gross-profit-sub"></div>
        </a>
        <!-- Today's Purchase -->
        <a class="dv4-kpi kpi-bank dv4-kpi-link" id="kpi-tile-purchase" style="background: linear-gradient(135deg, #6366f1, #4f46e5);" href="#" title="View Purchases Report">
            <i class="fas fa-shopping-cart dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Today's Purchase</div>
            <div class="dv4-kpi-value" id="kpi-purchase-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-purchase-sub"></div>
        </a>
        <!-- Today's Net Profit -->
        <a class="dv4-kpi kpi-profit dv4-kpi-link" id="kpi-tile-net-profit" href="#" title="View Daily P&amp;L Report">
            <i class="fas fa-hand-holding-usd dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Today's Net Profit</div>
            <div class="dv4-kpi-value" id="kpi-net-profit-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-net-profit-sub"></div>
        </a>
        <!-- Cash on Hand -->
        <a class="dv4-kpi kpi-cash dv4-kpi-link" id="kpi-tile-cash" href="#" title="View Cash Ledger">
            <i class="fas fa-coins dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Cash on Hand</div>
            <div class="dv4-kpi-value" id="kpi-cash-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-cash-sub"></div>
        </a>
        <!-- Bank Balance -->
        <a class="dv4-kpi kpi-bank dv4-kpi-link" id="kpi-tile-bank" href="#" title="View Bank Ledger">
            <i class="fas fa-university dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Bank Balance</div>
            <div class="dv4-kpi-value" id="kpi-bank-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-bank-sub"></div>
        </a>
        <!-- Receivables (AR) -->
        <a class="dv4-kpi kpi-ar dv4-kpi-link" id="kpi-tile-ar" href="#" title="View AR Statement">
            <i class="fas fa-hand-holding-usd dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Receivables (AR)</div>
            <div class="dv4-kpi-value" id="kpi-ar-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-ar-sub"></div>
        </a>
        <!-- Payables (AP) -->
        <a class="dv4-kpi kpi-ap dv4-kpi-link" id="kpi-tile-ap" href="#" title="View AP Report">
            <i class="fas fa-file-invoice dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Payables (AP)</div>
            <div class="dv4-kpi-value" id="kpi-ap-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-ap-sub"></div>
        </a>
        <!-- Inventory Value -->
        <a class="dv4-kpi kpi-inv dv4-kpi-link" id="kpi-tile-inv" href="#" title="View Stock Summary">
            <i class="fas fa-boxes dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Inventory Value</div>
            <div class="dv4-kpi-value" id="kpi-inv-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-inv-sub"></div>
        </a>
        <!-- Low Stock Alerts -->
        <a class="dv4-kpi kpi-lowstock dv4-kpi-link" id="kpi-tile-low" href="#" title="View Low Stock Report">
            <i class="fas fa-exclamation-triangle dv4-kpi-icon"></i>
            <div class="dv4-kpi-label">Low Stock Alerts</div>
            <div class="dv4-kpi-value" id="kpi-low-val">—</div>
            <div class="dv4-kpi-sub" id="kpi-low-sub"></div>
        </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         ROW 1B — BANK ACCOUNT DETAILS (Dropdown)
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-grid" style="margin-top: 16px;">
        <div class="dv4-card dv4-col-12" id="widget-bank-account-detail">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-university" style="color: #6366f1; font-size: 15px; margin-right: 6px;"></i> Bank Account Details<span id="ba-header-account-name" style="font-weight: 500; font-size: 13px; color: var(--dv4-text-muted); margin-left: 8px;"></span>
                </div>
                <select id="bank-account-select" class="dv4-select" style="min-width: 220px;">
                    <option value="">Select Bank Account...</option>
                </select>
            </div>
            <div class="dv4-card-body" style="padding: 20px;">
                <div class="dv4-stat-grid" id="bank-account-stats" style="display: none; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                    <!-- Opening -->
                    <div class="dv4-stat-card" style="border-left: 4px solid #6366f1; padding: 24px 16px; min-height: 105px; display: flex; flex-direction: column; justify-content: center;">
                        <div class="dv4-stat-value" id="ba-opening" style="font-size: 24px; font-weight: 800; color: var(--dv4-text);">—</div>
                        <div class="dv4-stat-label" style="font-size: 11px; margin-top: 6px;">Opening</div>
                    </div>
                    <!-- Today's In -->
                    <div class="dv4-stat-card" style="border-left: 4px solid #10b981; padding: 24px 16px; min-height: 105px; display: flex; flex-direction: column; justify-content: center;">
                        <div class="dv4-stat-value" id="ba-today-in" style="font-size: 24px; font-weight: 800; color: #10b981;">—</div>
                        <div class="dv4-stat-label" style="font-size: 11px; margin-top: 6px;">Today's In</div>
                    </div>
                    <!-- Amount Out -->
                    <div class="dv4-stat-card" style="border-left: 4px solid #ef4444; padding: 24px 16px; min-height: 105px; display: flex; flex-direction: column; justify-content: center;">
                        <div class="dv4-stat-value" id="ba-today-out" style="font-size: 24px; font-weight: 800; color: #ef4444;">—</div>
                        <div class="dv4-stat-label" style="font-size: 11px; margin-top: 6px;">Amount Out</div>
                    </div>
                    <!-- Remaining Amount -->
                    <div class="dv4-stat-card" style="border-left: 4px solid #3b82f6; padding: 24px 16px; min-height: 105px; display: flex; flex-direction: column; justify-content: center;">
                        <div class="dv4-stat-value" id="ba-balance" style="font-size: 24px; font-weight: 800; color: #3b82f6;">—</div>
                        <div class="dv4-stat-label" style="font-size: 11px; margin-top: 6px;">Remaining Amount</div>
                    </div>
                </div>
                <div id="ba-empty-state" style="text-align: center; padding: 48px 24px; color: var(--dv4-text-muted);">
                    <i class="fas fa-university" style="font-size: 36px; opacity: 0.3; margin-bottom: 12px; display: block;"></i>
                    Select a bank account from the dropdown to view details
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         ROW 2 — DAILY CASH SUMMARY
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-grid">
        <div class="dv4-card dv4-col-12" id="widget-cash-summary">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-cash-register" style="color: #10b981; font-size: 15px; margin-right: 6px;"></i> Daily Cash Reconciliation
                </div>
                <span class="dv4-pill dv4-pill-gray" id="cash-counted-by">Not Counted</span>
            </div>
            <div class="dv4-card-body">
                <div class="dv4-stat-grid">
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="cash-opening">—</div>
                        <div class="dv4-stat-label">Morning Count</div>
                    </div>
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="cash-in" style="color: #10b981;">—</div>
                        <div class="dv4-stat-label">Total Amount In</div>
                    </div>
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="cash-out" style="color: #ef4444;">—</div>
                        <div class="dv4-stat-label">Amount Out</div>
                    </div>
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="cash-expected" style="color: #3b82f6;">—</div>
                        <div class="dv4-stat-label">Remaining Amount</div>
                    </div>
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="cash-closing">—</div>
                        <div class="dv4-stat-label">Evening Count</div>
                    </div>
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="cash-diff">—</div>
                        <div class="dv4-stat-label">Variation</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         ROW 3 — MAIN PERFORMANCE CHART & REMINDERS
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-grid">
        <!-- Sales Chart -->
        <div class="dv4-card dv4-col-8" id="widget-chart-sales">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-chart-line" style="color: #3b82f6;"></i> Sales Performance
                </div>
                <select id="chart-sales-range" class="dv4-select">
                    <option value="7days">Last 7 Days</option>
                    <option value="thismonth">This Month</option>
                    <option value="30days">Last 30 Days</option>
                    <option value="today">Today (Hourly)</option>
                    <option value="thisyear">This Year</option>
                </select>
            </div>
            <div class="dv4-card-body">
                <div class="dv4-chart-wrap" style="height: 280px;">
                    <canvas id="chart-sales"></canvas>
                </div>
            </div>
        </div>

        <!-- Reminders & FY Cumulative -->
        <div class="dv4-col-4" style="display: flex; flex-direction: column; gap: 16px;">
            <!-- Reminders -->
            <div class="dv4-card" id="widget-reminders">
                <div class="dv4-card-header">
                    <div class="dv4-card-title">
                        <i class="fas fa-bell" style="color: #f59e0b;"></i> Reminders
                    </div>
                </div>
                <div class="dv4-card-body-flush">
                    <table class="dv4-table">
                        <tbody>
                            <tr style="cursor: pointer;" onclick="window.location='?page=transactions/bill'">
                                <td style="padding-left: 18px;"><i class="fas fa-file-invoice" style="color: #f59e0b; margin-right: 10px;"></i> Bills to Pay</td>
                                <td style="text-align: right; padding-right: 18px;"><span class="dv4-pill dv4-pill-amber" id="rem-bills">0</span></td>
                            </tr>
                            <tr style="cursor: pointer;" onclick="window.location='?page=transactions/invoice'">
                                <td style="padding-left: 18px;"><i class="fas fa-file-invoice-dollar" style="color: #3b82f6; margin-right: 10px;"></i> Open Invoices</td>
                                <td style="text-align: right; padding-right: 18px;"><span class="dv4-pill dv4-pill-blue" id="rem-invoices">0</span></td>
                            </tr>
                            <tr style="cursor: pointer;" onclick="window.location='?page=reports/inventory/low_stock'">
                                <td style="padding-left: 18px;"><i class="fas fa-boxes" style="color: #ef4444; margin-right: 10px;"></i> Low Stock Items</td>
                                <td style="text-align: right; padding-right: 18px;"><span class="dv4-pill dv4-pill-red" id="rem-low">0</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Fiscal Year Cumulative -->
            <div class="dv4-card" id="widget-fy-cumulative">
                <div class="dv4-card-header">
                    <div class="dv4-card-title">
                        <i class="fas fa-history" style="color: #6366f1;"></i> <span id="fy-label">FY Cumulative</span>
                    </div>
                </div>
                <div class="dv4-card-body" style="height: 100px; display: flex; align-items: center;">
                    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px; width: 100%;">
                        <div>
                           <div style="font-size: 9px; font-weight: 700; color: var(--dv4-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Stock Amount</div>
                           <div id="fy-stock" style="font-size: 12px; font-weight: 800; color: #06b6d4; margin-top: 4px;">—</div>
                        </div>
                        <div style="text-align: center;">
                           <div style="font-size: 9px; font-weight: 700; color: var(--dv4-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Purchase</div>
                           <div id="fy-purchase" style="font-size: 12px; font-weight: 800; color: #6366f1; margin-top: 4px;">—</div>
                        </div>
                        <div style="text-align: center;">
                           <div style="font-size: 9px; font-weight: 700; color: var(--dv4-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Sales</div>
                           <div id="fy-sales" style="font-size: 12px; font-weight: 800; color: var(--dv4-text); margin-top: 4px;">—</div>
                        </div>
                        <div style="text-align: center;">
                           <div style="font-size: 9px; font-weight: 700; color: var(--dv4-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Expenses</div>
                           <div id="fy-expenses" style="font-size: 12px; font-weight: 800; color: #ef4444; margin-top: 4px;">—</div>
                        </div>
                        <div style="text-align: right;">
                           <div style="font-size: 9px; font-weight: 700; color: var(--dv4-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Profit</div>
                           <div id="fy-profit" style="font-size: 12px; font-weight: 800; color: #10b981; margin-top: 4px;">—</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         ROW 4 — CHARTS ROW: PAYMENTS, CATEGORY & HOURLY SALES
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-sep"><i class="fas fa-chart-pie"></i> Payment, Category & Hourly Analytics</div>
    <div class="dv4-grid">
        <!-- Payment Methods -->
        <div class="dv4-card dv4-col-4" id="widget-chart-payments">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-credit-card" style="color: #8b5cf6;"></i> Payment Methods
                </div>
            </div>
            <div class="dv4-card-body">
                <div class="dv4-chart-wrap" style="height: 220px;">
                    <canvas id="chart-payments"></canvas>
                </div>
            </div>
        </div>

        <!-- Beverage Categories -->
        <div class="dv4-card dv4-col-4" id="widget-chart-categories">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-wine-glass" style="color: #ef4444;"></i> Sales by Category
                </div>
            </div>
            <div class="dv4-card-body">
                <div class="dv4-chart-wrap" style="height: 220px;">
                    <canvas id="chart-categories"></canvas>
                </div>
            </div>
        </div>

        <!-- Hourly Sales -->
        <div class="dv4-card dv4-col-4" id="widget-chart-hourly">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-clock" style="color: #06b6d4;"></i> Hourly Sales (Today)
                </div>
            </div>
            <div class="dv4-card-body">
                <div class="dv4-chart-wrap" style="height: 220px;">
                    <canvas id="chart-hourly"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         ROW 5 — INVENTORY & SALES DETAIL
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-sep"><i class="fas fa-boxes"></i> Inventory & Sales Analytics</div>
    <div class="dv4-grid">
        <!-- Stock Overview -->
        <div class="dv4-card dv4-col-4" id="widget-inventory-summary">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-warehouse" style="color: #06b6d4;"></i> Stock Overview
                </div>
            </div>
            <div class="dv4-card-body" style="height: 320px; overflow-y: auto;">
                <div class="dv4-inv-grid">
                    <div class="dv4-inv-card">
                        <div class="dv4-inv-count" id="inv-total">—</div>
                        <div class="dv4-inv-label">Total SKUs</div>
                    </div>
                    <div class="dv4-inv-card">
                        <div class="dv4-inv-count" id="inv-active">—</div>
                        <div class="dv4-inv-label">Active SKUs</div>
                    </div>
                    <div class="dv4-inv-card">
                        <div class="dv4-inv-count" id="inv-oos">—</div>
                        <div class="dv4-inv-label">Out of Stock</div>
                    </div>
                    <div class="dv4-inv-card">
                        <div class="dv4-inv-count" id="inv-low">—</div>
                        <div class="dv4-inv-label">Low Stock</div>
                    </div>
                    <div class="dv4-inv-card">
                        <div class="dv4-inv-count" id="inv-neg">—</div>
                        <div class="dv4-inv-label">Negative Stock</div>
                    </div>
                    <div class="dv4-inv-card">
                        <div class="dv4-inv-count" id="inv-over">—</div>
                        <div class="dv4-inv-label">Overstock</div>
                    </div>
                    <div class="dv4-inv-card full">
                        <div class="dv4-inv-count" id="inv-value" style="font-size: 18px; color: #10b981;">—</div>
                        <div class="dv4-inv-label">Total Valuation (Cost)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Selling -->
        <div class="dv4-card dv4-col-4" id="widget-top-selling">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-fire" style="color: #f59e0b;"></i> Top Selling Products
                </div>
            </div>
            <div class="dv4-card-body-flush dv4-scroll" style="height: 320px; overflow-y: auto;">
                <table class="dv4-table">
                    <thead>
                        <tr>
                            <th style="padding-left: 14px;">Item</th>
                            <th style="text-align: right;">Qty</th>
                            <th style="text-align: right; padding-right: 14px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody id="top-selling-body">
                        <tr><td colspan="3" style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Slow Moving -->
        <div class="dv4-card dv4-col-4" id="widget-slow-moving">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-snowflake" style="color: #3b82f6;"></i> Slow Moving (30d+)
                </div>
            </div>
            <div class="dv4-card-body-flush dv4-scroll" style="height: 320px; overflow-y: auto;">
                <table class="dv4-table">
                    <thead>
                        <tr>
                            <th style="padding-left: 14px;">Item</th>
                            <th style="text-align: right;">Stock</th>
                            <th style="text-align: right; padding-right: 14px;">Inactive</th>
                        </tr>
                    </thead>
                    <tbody id="slow-moving-body">
                        <tr><td colspan="3" style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         ROW 6 — FINANCIAL OVERVIEWS & VAT
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-sep"><i class="fas fa-file-invoice-dollar"></i> Financials & Reconciliation</div>
    <div class="dv4-grid">
        <!-- GP Trend -->
        <div class="dv4-card dv4-col-6" id="widget-chart-gp">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-chart-bar" style="color: #f59e0b;"></i> Gross Profit Trend (6m)
                </div>
            </div>
            <div class="dv4-card-body">
                <div class="dv4-chart-wrap" style="height: 220px;">
                    <canvas id="chart-gp"></canvas>
                </div>
            </div>
        </div>

        <!-- Expenses -->
        <div class="dv4-card dv4-col-6" id="widget-chart-expenses">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-receipt" style="color: #ef4444;"></i> Expenses Breakdown
                </div>
            </div>
            <div class="dv4-card-body">
                <div class="dv4-chart-wrap" style="height: 220px;">
                    <canvas id="chart-expenses"></canvas>
                </div>
            </div>
        </div>

        <!-- VAT Summary -->
        <div class="dv4-card dv4-col-6" id="widget-vat-summary">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-percent" style="color: #8b5cf6;"></i> VAT Summary (Current Month)
                </div>
            </div>
            <div class="dv4-card-body" style="height: 180px; display: flex; align-items: center;">
                <div class="dv4-stat-grid" style="width: 100%;">
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="vat-taxable">—</div>
                        <div class="dv4-stat-label">Taxable Sales</div>
                    </div>
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="vat-collected" style="color: #10b981;">—</div>
                        <div class="dv4-stat-label">VAT Collected</div>
                    </div>
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="vat-paid" style="color: #ef4444;">—</div>
                        <div class="dv4-stat-label">VAT Paid</div>
                    </div>
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="vat-liability">—</div>
                        <div class="dv4-stat-label">VAT Liability</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Comparatives -->
        <div class="dv4-card dv4-col-6" id="widget-monthly-compare">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-calendar-alt" style="color: #6366f1;"></i> Monthly Comparatives
                </div>
            </div>
            <div class="dv4-card-body-flush dv4-scroll" style="height: 180px; overflow-y: auto;">
                <table class="dv4-table">
                    <thead>
                        <tr>
                            <th style="padding-left: 14px;">Metric</th>
                            <th style="text-align: right;">This Month</th>
                            <th style="text-align: right; padding-right: 14px;">Change</th>
                        </tr>
                    </thead>
                    <tbody id="monthly-body">
                        <tr><td colspan="3" style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         ROW 7 — CUSTOMER & SUPPLIER OVERVIEWS
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-sep"><i class="fas fa-user-friends"></i> Relationship Management</div>
    <div class="dv4-grid">
        <!-- Customer Stats -->
        <div class="dv4-card dv4-col-3" id="widget-customer-stats">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-user-tag" style="color: #3b82f6;"></i> Customers Overview
                </div>
            </div>
            <div class="dv4-card-body" style="height: 280px; overflow-y: auto;">
                <div class="dv4-stat-grid" style="grid-template-columns: 1fr;">
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="cust-total">—</div>
                        <div class="dv4-stat-label">Total Accounts</div>
                    </div>
                    <div class="dv4-stat-card" style="margin-top: 8px;">
                        <div class="dv4-stat-value" id="cust-new" style="color: #10b981;">—</div>
                        <div class="dv4-stat-label">New This Month</div>
                    </div>
                    <div class="dv4-stat-card" style="margin-top: 8px;">
                        <div class="dv4-stat-value" id="cust-ar" style="color: #ef4444;">—</div>
                        <div class="dv4-stat-label">Receivables (AR)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Supplier Stats -->
        <div class="dv4-card dv4-col-3" id="widget-supplier-stats">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-truck" style="color: #ec4899;"></i> Suppliers Overview
                </div>
            </div>
            <div class="dv4-card-body" style="height: 280px; overflow-y: auto;">
                <div class="dv4-stat-grid" style="grid-template-columns: 1fr;">
                    <div class="dv4-stat-card">
                        <div class="dv4-stat-value" id="supp-total">—</div>
                        <div class="dv4-stat-label">Total Vendors</div>
                    </div>
                    <div class="dv4-stat-card" style="margin-top: 8px;">
                        <div class="dv4-stat-value" id="supp-new" style="color: #10b981;">—</div>
                        <div class="dv4-stat-label">New This Month</div>
                    </div>
                    <div class="dv4-stat-card" style="margin-top: 8px;">
                        <div class="dv4-stat-value" id="supp-ap" style="color: #ec4899;">—</div>
                        <div class="dv4-stat-label">Payables (AP)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Customers -->
        <div class="dv4-card dv4-col-6" id="widget-top-customers">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-medal" style="color: #f59e0b;"></i> Top Customers (This Month)
                </div>
            </div>
            <div class="dv4-card-body dv4-scroll" style="height: 280px; overflow-y: auto;">
                <div class="dv4-entity-list" id="top-cust-body">
                    <div style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>

        <!-- Outstanding Receivables (AR) -->
        <div class="dv4-card dv4-col-4" id="widget-outstanding-ar">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-clock" style="color: #ef4444;"></i> Outstanding Receivables
                </div>
            </div>
            <div class="dv4-card-body dv4-scroll" style="height: 280px; overflow-y: auto;">
                <div class="dv4-entity-list" id="out-ar-body">
                    <div style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>

        <!-- Outstanding Payables (AP) -->
        <div class="dv4-card dv4-col-4" id="widget-outstanding-ap">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-file-invoice-dollar" style="color: #ec4899;"></i> Outstanding Payables
                </div>
            </div>
            <div class="dv4-card-body dv4-scroll" style="height: 280px; overflow-y: auto;">
                <div class="dv4-entity-list" id="out-ap-body">
                    <div style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>

        <!-- Bills Due This Week -->
        <div class="dv4-card dv4-col-4" id="widget-bills-due">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-exclamation-circle" style="color: #f59e0b;"></i> Bills Due This Week
                </div>
            </div>
            <div class="dv4-card-body dv4-scroll" style="height: 280px; overflow-y: auto;">
                <div class="dv4-entity-list" id="bills-due-body">
                    <div style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         ROW 8 — SYSTEM CONTROL & RECENT LOG (FIXED HEIGHT)
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="dv4-sep"><i class="fas fa-shield-alt"></i> Control & Timeline</div>
    <div class="dv4-grid">
        <!-- Operational Alerts -->
        <div class="dv4-card dv4-col-5" id="widget-operational-alerts">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-exclamation-triangle" style="color: #f97316;"></i> Operational Alerts
                </div>
                <span class="dv4-pill" id="alerts-count">0 Active</span>
            </div>
            <div class="dv4-card-body dv4-scroll" style="height: 320px; overflow-y: auto;">
                <div class="dv4-alert-list" id="alerts-container">
                    <div style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>

        <!-- Recent Activities Timeline -->
        <div class="dv4-card dv4-col-7" id="widget-recent-activities">
            <div class="dv4-card-header">
                <div class="dv4-card-title">
                    <i class="fas fa-history" style="color: #3b82f6;"></i> Recent Activities (Today)
                </div>
            </div>
            <div class="dv4-card-body-flush dv4-scroll" style="height: 320px; overflow-y: auto;">
                <div class="dv4-timeline" id="activities-container">
                    <div style="text-align: center; padding: 20px; color: var(--dv4-text-muted);"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button (FAB) -->
    <div class="dv4-fab-container" id="dv4-fab-container">
        <div class="dv4-fab-items" id="dv4-fab-items">
            <a href="?page=transactions/pos/manage" class="dv4-fab-item"><i class="fas fa-keyboard"></i> New POS Sale</a>
            <a href="?page=transactions/bill/manage" class="dv4-fab-item"><i class="fas fa-truck-loading"></i> New Purchase</a>
            <a href="?page=transactions/expense/manage" class="dv4-fab-item"><i class="fas fa-wallet"></i> Record Expense</a>
            <a href="?page=transactions/cash_denom/manage" class="dv4-fab-item"><i class="fas fa-calculator"></i> Cash Count</a>
        </div>
        <button class="dv4-fab-main" id="dv4-fab-main" title="Quick Actions">
            <i class="fas fa-plus"></i>
        </button>
    </div>
</div>

<script src="assets/js/dashboard.js?v=<?php echo time(); ?>"></script>
