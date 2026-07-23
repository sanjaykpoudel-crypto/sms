/**
 * DASHBOARD V3 — Modern ERP Dashboard Engine
 * Features:
 *  - AJAX polling (auto-refresh every 30s)
 *  - Chart.js integration for all charts
 *  - Dark/Light mode toggle
 *  - Drill-down clickable reports
 *  - Compact formatted numbers
 */
(function() {
    'use strict';
    if (!document.querySelector('.dv3')) return;

    const CONFIG = {
        API_URL: 'api/get_dashboard_v3.php',
        REFRESH_INTERVAL: 30000,
        ANIMATION_DURATION: 800,
        STORAGE_PREFIX: 'dv3_'
    };

    let state = {
        data: null,
        charts: {},
        intervalId: null,
        lastRefresh: null,
        refreshCount: 0
    };

    const $ = (id) => document.getElementById(id);
    const $$ = (sel) => document.querySelector(sel);
    const $$$ = (sel) => document.querySelectorAll(sel);

    const fmtFull = (n) => 'Rs ' + parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmt = (n) => fmtFull(n);
    const fmtInt = (n) => parseInt(n || 0).toLocaleString('en-IN');
    const fmtK = (n) => fmtFull(n);
    const el = (id) => document.getElementById(id);
    const safe = (fn) => { try { fn(); } catch(e) { console.warn('[DV3]', e); } };

    function trendBadge(kpi) {
        if (!kpi || kpi.trend === undefined) return '';
        const pct = Math.abs(kpi.change_pct || 0).toFixed(1);
        const cls = kpi.trend === 'up' ? 'up' : (kpi.trend === 'down' ? 'down' : 'neutral');
        const icon = kpi.trend === 'up' ? 'fa-arrow-up' : (kpi.trend === 'down' ? 'fa-arrow-down' : 'fa-minus');
        return `<span class="dv3-kpi-trend ${cls}"><i class="fas ${icon}"></i> ${pct}%</span>`;
    }

    function timeAgo(ts) {
        if (!ts) return '';
        const diff = Math.floor((Date.now() / 1000) - ts);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function getStatusDot(status) {
        const colors = { completed: '#10b981', posted: '#10b981', paid: '#10b981', 
                        draft: '#f59e0b', approved: '#3b82f6', partial: '#f59e0b', voided: '#ef4444' };
        return `<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:${colors[status] || '#94a3b8'};margin-right:4px;"></span>`;
    }

    function initTheme() {
        const saved = localStorage.getItem(CONFIG.STORAGE_PREFIX + 'theme');
        if (saved === 'dark') document.body.classList.add('dark-theme');

        const btn = $('dv3-theme-toggle');
        if (btn) {
            btn.addEventListener('click', function() {
                document.body.classList.toggle('dark-theme');
                const isDark = document.body.classList.contains('dark-theme');
                localStorage.setItem(CONFIG.STORAGE_PREFIX + 'theme', isDark ? 'dark' : 'light');
                Object.values(state.charts).forEach(c => { if (c && c.update) c.update(); });
            });
        }
    }

    function isDark() { return document.body.classList.contains('dark-theme'); }
    function gridColor() { return isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)'; }
    function labelColor() { return isDark() ? '#94a3b8' : '#64748b'; }

    function chartOpts(extra) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false, labels: { color: labelColor() } } },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor() }, ticks: { color: labelColor(), font: { size: 10 } } },
                x: { grid: { display: false }, ticks: { color: labelColor(), font: { size: 10 } } }
            }
        }, extra);
    }

    function initCharts() {
        const sCanvas = $('chart-sales');
        if (sCanvas) {
            state.charts.sales = new Chart(sCanvas.getContext('2d'), {
                type: 'line',
                data: { labels: [], datasets: [{
                    label: 'Sales', data: [],
                    borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.08)',
                    fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#3b82f6'
                }] },
                options: chartOpts()
            });
        }

        const pCanvas = $('chart-payments');
        if (pCanvas) {
            state.charts.payments = new Chart(pCanvas.getContext('2d'), {
                type: 'doughnut',
                data: { labels: [], datasets: [{ data: [], backgroundColor: ['#10b981','#3b82f6','#8b5cf6','#6366f1','#14b8a6','#ef4444'], borderWidth: 0, hoverOffset: 8 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '65%',
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { color: labelColor(), padding: 10, font: { size: 10 } } }
                    }
                }
            });
        }

        const hCanvas = $('chart-hourly');
        if (hCanvas) {
            state.charts.hourly = new Chart(hCanvas.getContext('2d'), {
                type: 'bar',
                data: { labels: [], datasets: [{
                    label: 'Sales', data: [],
                    backgroundColor: 'rgba(20,184,166,0.7)', borderRadius: 6, borderSkipped: false
                }] },
                options: chartOpts()
            });
        }

        const gCanvas = $('chart-gp');
        if (gCanvas) {
            state.charts.gp = new Chart(gCanvas.getContext('2d'), {
                type: 'bar',
                data: { labels: [], datasets: [{
                    label: 'Gross Profit', data: [],
                    backgroundColor: 'rgba(245,158,11,0.7)', borderRadius: 6, borderSkipped: false
                }] },
                options: chartOpts()
            });
        }

        const eCanvas = $('chart-expenses');
        if (eCanvas) {
            state.charts.expenses = new Chart(eCanvas.getContext('2d'), {
                type: 'doughnut',
                data: { labels: [], datasets: [{ data: [], backgroundColor: ['#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#14b8a6','#6366f1'], borderWidth: 0, hoverOffset: 8 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '60%',
                    plugins: {
                        legend: { display: true, position: 'right', labels: { color: labelColor(), padding: 8, font: { size: 10 } } }
                    }
                }
            });
        }
    }

    function renderKPI(data) {
        const kpis = data.kpi || {};
        const map = [
            { id: 'kpi-sales-val', sub: 'kpi-sales-sub', k: 'today_sales', suffix: 'vs yesterday', cls: 'kpi-sales' },
            { id: 'kpi-profit-val', sub: 'kpi-profit-sub', k: 'today_profit', suffix: 'vs yesterday', cls: 'kpi-profit' },
            { id: 'kpi-cash-val', sub: 'kpi-cash-sub', k: 'cash_on_hand', suffix: 'vs yesterday', cls: 'kpi-cash' },
            { id: 'kpi-bank-val', sub: 'kpi-bank-sub', k: 'bank_balance', suffix: 'vs yesterday', cls: 'kpi-bank' },
            { id: 'kpi-ar-val', sub: 'kpi-ar-sub', k: 'accounts_receivable', suffix: 'vs yesterday', cls: 'kpi-ar' },
            { id: 'kpi-ap-val', sub: 'kpi-ap-sub', k: 'accounts_payable', suffix: 'vs yesterday', cls: 'kpi-ap' },
            { id: 'kpi-inv-val', sub: 'kpi-inv-sub', k: 'inventory_value', suffix: 'current value', cls: 'kpi-inv' },
            { id: 'kpi-lowstock-val', sub: 'kpi-lowstock-sub', k: 'low_stock_alerts', suffix: 'items need reorder', cls: 'kpi-lowstock' }
        ];

        map.forEach(m => {
            safe(() => {
                const kpi = kpis[m.k];
                const valEl = el(m.id);
                const subEl = el(m.sub);
                if (!kpi || kpi.val === undefined) {
                    const tile = valEl ? valEl.closest('.dv3-kpi') : null;
                    if (tile) tile.style.display = 'none';
                    return;
                }
                if (valEl) {
                    if (m.k === 'low_stock_alerts') {
                        valEl.textContent = fmtInt(kpi.val);
                    } else {
                        valEl.textContent = fmt(kpi.val);
                    }
                }
                if (subEl) {
                    subEl.innerHTML = trendBadge(kpi) + ' <span>' + m.suffix + '</span>';
                }
            });
        });
    }

    function renderCharts(data) {
        const sales = data.sales || {};
        safe(() => {
            if (state.charts.sales && sales.trend) {
                state.charts.sales.data.labels = sales.trend.labels || [];
                state.charts.sales.data.datasets[0].data = sales.trend.values || [];
                state.charts.sales.update();
            }
        });
        safe(() => {
            if (state.charts.payments && sales.by_payment) {
                state.charts.payments.data.labels = sales.by_payment.labels || [];
                state.charts.payments.data.datasets[0].data = sales.by_payment.values || [];
                state.charts.payments.update();
            }
        });
        safe(() => {
            if (state.charts.hourly && sales.hourly) {
                state.charts.hourly.data.labels = sales.hourly.labels || [];
                state.charts.hourly.data.datasets[0].data = sales.hourly.values || [];
                state.charts.hourly.update();
            }
        });
        safe(() => {
            const fin = data.financial || {};
            if (state.charts.gp && fin.gp_trend) {
                state.charts.gp.data.labels = fin.gp_trend.labels || [];
                state.charts.gp.data.datasets[0].data = fin.gp_trend.values || [];
                state.charts.gp.update();
            }
        });
        safe(() => {
            const fin = data.financial || {};
            if (state.charts.expenses && fin.expenses) {
                state.charts.expenses.data.labels = fin.expenses.labels || [];
                state.charts.expenses.data.datasets[0].data = fin.expenses.values || [];
                state.charts.expenses.update();
            }
        });
    }

    function renderInventory(data) {
        const inv = data.inventory || {};
        safe(() => {
            const totalEl = $('inv-total');
            if (totalEl) totalEl.textContent = fmtInt(inv.total_items);
            const activeEl = $('inv-active');
            if (activeEl) activeEl.textContent = fmtInt(inv.active_items);
            const oosEl = $('inv-outofstock');
            if (oosEl) { oosEl.textContent = fmtInt(inv.out_of_stock); oosEl.style.color = inv.out_of_stock > 0 ? '#ef4444' : '#10b981'; }
            const lowEl = $('inv-lowstock');
            if (lowEl) { lowEl.textContent = fmtInt(inv.low_stock); lowEl.style.color = inv.low_stock > 0 ? '#f59e0b' : '#10b981'; }
            const negEl = $('inv-negative');
            if (negEl) { negEl.textContent = fmtInt(inv.negative_stock); negEl.style.color = inv.negative_stock > 0 ? '#ef4444' : '#10b981'; }
            const overEl = $('inv-overstock');
            if (overEl) overEl.textContent = fmtInt(inv.overstock);
            const valueEl = $('inv-value');
            if (valueEl) valueEl.textContent = fmt(inv.inventory_value);
        });
        safe(() => {
            const body = $('top-selling-body');
            if (!body) return;
            const items = inv.top_selling || [];
            if (items.length > 0) {
                body.innerHTML = items.map((item, i) => `<tr>
                    <td style="padding-left:14px;">
                        <span style="font-weight:700;color:var(--dv3-text-muted);margin-right:6px;">${i + 1}.</span>
                        <span style="font-weight:600;">${item.item_name}</span>
                        <span style="font-size:10px;color:var(--dv3-text-muted);margin-left:4px;">${item.sku}</span>
                    </td>
                    <td style="text-align:right;font-weight:600;">${fmtInt(item.total_qty)}</td>
                    <td style="text-align:right;padding-right:14px;font-weight:700;color:#10b981;">${fmt(item.total_amount)}</td>
                </tr>`).join('');
            } else {
                body.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:20px;color:var(--dv3-text-muted);">No sales this month</td></tr>';
            }
        });
        safe(() => {
            const body = $('slow-moving-body');
            if (!body) return;
            const items = inv.slow_moving || [];
            if (items.length > 0) {
                body.innerHTML = items.map(item => `<tr>
                    <td style="padding-left:14px;">
                        <span style="font-weight:600;">${item.item_name}</span>
                        <span style="font-size:10px;color:var(--dv3-text-muted);margin-left:4px;">${item.sku}</span>
                    </td>
                    <td style="text-align:right;">${parseFloat(item.current_stock).toFixed(0)}</td>
                    <td style="text-align:right;padding-right:14px;">
                        <span class="dv3-pill ${parseInt(item.days_inactive) > 90 ? 'dv3-pill-danger' : 'dv3-pill-warning'}">${item.days_inactive}d</span>
                    </td>
                </tr>`).join('');
            } else {
                body.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:20px;color:#10b981;"><i class="fas fa-check-circle"></i> All items moving well</td></tr>';
            }
        });
    }

    function renderFinancial(data) {
        const fin = data.financial || {};
        const monthly = fin.monthly || {};
        safe(() => {
            const vat = fin.vat || {};
            const vatColEl = $('vat-collected');
            if (vatColEl) vatColEl.textContent = fmtFull(vat.vat_collected);
            const vatPaidEl = $('vat-paid');
            if (vatPaidEl) vatPaidEl.textContent = fmtFull(vat.vat_paid);
            const vatLiabilityEl = $('vat-liability');
            if (vatLiabilityEl) {
                const liability = vat.vat_liability || 0;
                vatLiabilityEl.textContent = fmtFull(liability);
                vatLiabilityEl.style.color = liability > 0 ? '#f59e0b' : '#10b981';
            }
            const taxableEl = $('vat-taxable');
            if (taxableEl) taxableEl.textContent = fmtFull(vat.taxable_sales);
        });
        safe(() => {
            const body = $('monthly-body');
            if (!body) return;
            const rows = [
                ['Sales', monthly.sales_this_month, monthly.sales_last_month],
                ['Purchases', monthly.purchases_this_month, 0],
                ['Expenses', monthly.expenses_this_month, 0],
                ['Gross Profit', monthly.profit_this_month, 0]
            ].filter(r => r[1] !== undefined);
            if (rows.length > 0) {
                body.innerHTML = rows.map(([label, curr, prev]) => {
                    curr = parseFloat(curr || 0);
                    prev = parseFloat(prev || 0);
                    const change = prev !== 0 ? ((curr - prev) / prev * 100).toFixed(1) : (curr > 0 ? '100.0' : '0.0');
                    const up = parseFloat(change) >= 0;
                    return `<tr>
                        <td style="padding-left:14px;font-weight:600;">${label}</td>
                        <td style="text-align:right;font-weight:700;">${fmtK(curr)}</td>
                        <td style="text-align:right;padding-right:14px;">
                            <span class="${up ? 'dv3-badge-up' : 'dv3-badge-down'}"><i class="fas fa-arrow-${up ? 'up' : 'down'}"></i> ${Math.abs(change)}%</span>
                        </td>
                    </tr>`;
                }).join('');
            } else {
                body.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:20px;color:var(--dv3-text-muted);">No data</td></tr>';
            }
        });
        safe(() => {
            const fyLabel = $('fy-label');
            if (fyLabel) fyLabel.textContent = monthly.fy_label || 'FY';
            const fyStock = $('fy-stock-val') || $('fy-stock');
            if (fyStock) fyStock.textContent = fmtFull(monthly.fy_stock !== undefined ? monthly.fy_stock : (data.inventory ? data.inventory.value : 0));
            const fyPurchase = $('fy-purchase-val') || $('fy-purchase');
            if (fyPurchase) fyPurchase.textContent = fmtFull(monthly.fy_purchases);
            const fySales = $('fy-sales-val') || $('fy-sales');
            if (fySales) fySales.textContent = fmtFull(monthly.fy_sales);
            const fyExpenses = $('fy-expenses-val') || $('fy-expenses');
            if (fyExpenses) fyExpenses.textContent = fmtFull(monthly.fy_expenses);
            const fyProfit = $('fy-profit-val') || $('fy-profit');
            if (fyProfit) { fyProfit.textContent = fmtFull(monthly.fy_profit); fyProfit.style.color = monthly.fy_profit > 0 ? '#10b981' : '#ef4444'; }
        });
    }

    function renderCustomers(data) {
        const cust = data.customers || {};
        const supp = data.suppliers || {};
        safe(() => {
            const totalEl = $('cust-total');
            if (totalEl) totalEl.textContent = fmtInt(cust.total);
            const newEl = $('cust-new');
            if (newEl) newEl.textContent = fmtInt(cust.new);
            const arEl = $('cust-ar');
            if (arEl) arEl.textContent = fmt(cust.outstanding_ar);
            const supEl = $('supp-total');
            if (supEl) supEl.textContent = fmtInt(supp.total);
            const apEl = $('supp-ap');
            if (apEl) apEl.textContent = fmt(supp.outstanding_ap);
        });
        safe(() => {
            const body = $('top-customers-body');
            if (!body) return;
            const items = cust.top || [];
            if (items.length > 0) {
                body.innerHTML = items.map((c, i) => {
                    const colors = ['#3b82f6', '#10b981', '#8b5cf6', '#f59e0b', '#ec4899'];
                    return `<div class="dv3-entity-item" onclick="window.location='?page=master/customer'">
                        <div class="dv3-entity-info">
                            <div class="dv3-entity-avatar" style="background:${colors[i]}">${c.customer_name.charAt(0)}</div>
                            <div>
                                <div class="dv3-entity-name">${c.customer_name}</div>
                                <div class="dv3-entity-meta">Top ${i + 1} by sales</div>
                            </div>
                        </div>
                        <div class="dv3-entity-amount" style="color:#10b981;">${fmt(c.total_sales)}</div>
                    </div>`;
                }).join('');
            } else {
                body.innerHTML = '<div class="dv3-empty"><i class="fas fa-users"></i><div class="dv3-empty-text">No customer data</div></div>';
            }
        });
        safe(() => {
            const body = $('outstanding-ar-body');
            if (!body) return;
            const items = cust.outstanding_receivables || [];
            if (items.length > 0) {
                body.innerHTML = items.map(c => `<div class="dv3-entity-item" onclick="window.location='?page=master/customer'">
                    <div class="dv3-entity-info">
                        <div class="dv3-entity-avatar" style="background:#ef4444">${c.full_name.charAt(0)}</div>
                        <div>
                            <div class="dv3-entity-name">${c.full_name}</div>
                            <div class="dv3-entity-meta">${c.phone || 'N/A'} · ${c.customer_type || ''}</div>
                        </div>
                    </div>
                    <div class="dv3-entity-amount" style="color:#ef4444;">${fmt(c.balance)}</div>
                </div>`).join('');
            } else {
                body.innerHTML = '<div class="dv3-empty"><i class="fas fa-check-circle" style="color:#10b981;opacity:1;"></i><div class="dv3-empty-text" style="color:#10b981;">No outstanding receivables</div></div>';
            }
        });
        safe(() => {
            const body = $('outstanding-ap-body');
            if (!body) return;
            const items = supp.outstanding_payables || [];
            if (items.length > 0) {
                body.innerHTML = items.map(v => `<div class="dv3-entity-item" onclick="window.location='?page=master/vendor'">
                    <div class="dv3-entity-info">
                        <div class="dv3-entity-avatar" style="background:#ec4899">${v.company_name.charAt(0)}</div>
                        <div>
                            <div class="dv3-entity-name">${v.company_name}</div>
                            <div class="dv3-entity-meta">${v.phone || 'N/A'}</div>
                        </div>
                    </div>
                    <div class="dv3-entity-amount" style="color:#ec4899;">${fmt(v.balance)}</div>
                </div>`).join('');
            } else {
                body.innerHTML = '<div class="dv3-empty"><i class="fas fa-check-circle" style="color:#10b981;opacity:1;"></i><div class="dv3-empty-text" style="color:#10b981;">No outstanding payables</div></div>';
            }
        });
    }

    function renderAlerts(data) {
        const alerts = data.alerts || [];
        safe(() => {
            const container = $('alerts-container');
            if (!container) return;
            if (alerts.length === 0) {
                container.innerHTML = '<div class="dv3-empty"><i class="fas fa-check-circle" style="color:#10b981;opacity:1;"></i><div class="dv3-empty-text" style="color:#10b981;">All systems operational</div></div>';
                return;
            }
            const severityColors = { critical: '#ef4444', danger: '#dc2626', warning: '#f59e0b', info: '#3b82f6' };
            container.innerHTML = alerts.slice(0, 8).map(a => {
                const color = severityColors[a.severity] || '#64748b';
                return `<div class="dv3-alert-item" style="border-left-color:${color};" onclick="window.location='${a.link || '#'}'">
                    <div class="dv3-alert-icon" style="background:${a.icon_bg || color}"><i class="fas ${a.icon || 'fa-bell'}"></i></div>
                    <div class="dv3-alert-content"><div class="dv3-alert-title">${a.title}</div><div class="dv3-alert-desc">${a.description}</div></div>
                </div>`;
            }).join('');
            if (alerts.length > 8) {
                container.innerHTML += `<div style="text-align:center;padding:8px;font-size:11px;color:var(--dv3-text-muted);">+${alerts.length - 8} more alerts</div>`;
            }
        });
    }

    function renderRecentActivities(data) {
        const activities = data.recent_activities || [];
        safe(() => {
            const container = $('activities-container');
            if (!container) return;
            if (activities.length === 0) {
                container.innerHTML = '<div class="dv3-empty"><i class="fas fa-clock"></i><div class="dv3-empty-text">No transactions today</div></div>';
                return;
            }
            const typeColors = { 'POS Sale': '#10b981', 'Customer Invoice': '#3b82f6', 'Vendor Bill': '#f59e0b', 'Customer Payment': '#8b5cf6', 'Vendor Payment': '#ec4899', 'Journal Entry': '#6366f1' };
            container.innerHTML = activities.map(a => {
                const color = typeColors[a.type] || '#64748b';
                const isNeg = a.type.includes('Bill') || a.type.includes('Vendor Payment');
                return `<div class="dv3-timeline-item" onclick="window.location='?page=${a.page || '#'}&id=${a.record_id}'">
                    <div class="dv3-timeline-dot" style="background:${color}"></div>
                    <div class="dv3-timeline-content">
                        <div class="dv3-timeline-header"><div><span class="dv3-timeline-title">${a.txn_number || ''}</span><span class="dv3-pill dv3-pill-neutral" style="margin-left:6px;">${a.type}</span></div><div class="dv3-timeline-amount" style="color:${isNeg ? '#ef4444' : '#10b981'}">${isNeg ? '-' : '+'}${fmt(a.amount)}</div></div>
                        <div class="dv3-timeline-meta"><span>${a.party || ''}</span><span>${a.date || ''}</span>${a.status ? getStatusDot(a.status) + a.status : ''}</div>
                    </div>
                </div>`;
            }).join('');
        });
    }

    function renderBankBalances(data) {
        const balances = data.bank_balances || [];
        safe(() => {
            const container = $('bank-balances-container');
            if (!container) return;
            if (balances.length === 0) {
                container.innerHTML = '<div class="dv3-empty"><i class="fas fa-university"></i><div class="dv3-empty-text">No balances</div></div>';
                return;
            }
            container.innerHTML = balances.map(b => {
                const bal = parseFloat(b.balance || 0);
                return `<div class="dv3-stat-card" style="text-align:left;display:flex;justify-content:space-between;align-items:center;"><div><div style="font-weight:600;font-size:12px;color:var(--dv3-text-primary);">${b.account_name}</div><div style="font-size:10px;color:var(--dv3-text-muted);">${b.account_code} · ${b.account_subtype}</div></div><div style="font-size:16px;font-weight:800;color:${bal >= 0 ? '#10b981' : '#ef4444'};">${fmt(bal)}</div></div>`;
            }).join('');
        });
    }

    function renderReminders(data) {
        const rem = data.reminders || {};
        safe(() => {
            const billsEl = $('rem-bills');
            if (billsEl) billsEl.textContent = rem.bills_to_pay || 0;
            const invEl = $('rem-invoices');
            if (invEl) invEl.textContent = rem.open_invoices || 0;
            const stockEl = $('rem-lowstock');
            if (stockEl) stockEl.textContent = rem.low_stock || 0;
        });
    }

    function refreshDashboard() {
        const loader = $('dv3-loader');
        if (loader) loader.classList.add('active');
        fetch(CONFIG.API_URL)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'error') { console.error('[DV3] API Error:', data.message); if (loader) loader.classList.remove('active'); return; }
                state.data = data; state.lastRefresh = new Date(); state.refreshCount++;
                const refreshEl = $('dv3-refresh-time');
                if (refreshEl) refreshEl.textContent = state.lastRefresh.toLocaleTimeString();
                renderKPI(data); renderCharts(data); renderInventory(data); renderFinancial(data); renderCustomers(data); renderAlerts(data); renderRecentActivities(data); renderBankBalances(data); renderReminders(data);
                const queryEl = $('dv3-query-time');
                if (queryEl && data.query_time_ms) queryEl.textContent = data.query_time_ms + 'ms';
                if (loader) loader.classList.remove('active');
            })
            .catch(err => { console.error('[DV3] Fetch error:', err); if (loader) loader.classList.remove('active'); });
    }

    window.refreshSalesChart = function() {
        const range = $('chart-sales-range');
        if (!range) return;
        fetch(CONFIG.API_URL + '?sales_range=' + range.value).then(r => r.json()).then(data => {
            if (state.charts.sales && data.sales && data.sales.trend) { state.charts.sales.data.labels = data.sales.trend.labels || []; state.charts.sales.data.datasets[0].data = data.sales.trend.values || []; state.charts.sales.update(); }
        });
    };

    function initRefreshBtn() {
        const btn = $('dv3-refresh-btn');
        if (btn) btn.addEventListener('click', function() { refreshDashboard(); });
    }

    function initSalesRange() {
        const sel = $('chart-sales-range');
        if (sel) sel.addEventListener('change', function() { window.refreshSalesChart(); });
    }

    function init() {
        console.log('[DV3] Dashboard V3 initializing...');
        initTheme(); initCharts(); initRefreshBtn(); initSalesRange();
        refreshDashboard();
        state.intervalId = setInterval(refreshDashboard, CONFIG.REFRESH_INTERVAL);
        console.log('[DV3] Dashboard V3 initialized. Auto-refresh every ' + (CONFIG.REFRESH_INTERVAL / 1000) + 's');
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();



/**
 * DASHBOARD V4 — Enterprise ERP Dashboard Engine
 * Modern, responsive, real-time analytics for Liquor Shop ERP
 * Features: Chart.js integration, auto-refresh, dark mode, FAB, drill-down
 */
(function() {
    'use strict';
    if (!document.querySelector('.dv4')) return;

    const CONFIG = {
        API: 'api/get_dashboard_data.php',
        INTERVAL: 30000,
        STORAGE_PREFIX: 'dv4_'
    };

    let state = {
        data: null,
        charts: {},
        intervalId: null,
        lastRefresh: null,
        widgetOrder: [],
        widgetVisibility: {}
    };

    const $ = (id) => document.getElementById(id);
    const $$ = (sel) => document.querySelector(sel);
    const $$$ = (sel) => document.querySelectorAll(sel);
    const el = (id) => document.getElementById(id);

    const fmtFull = (n) => 'Rs ' + parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmt = (n) => fmtFull(n);
    const fmtInt = (n) => parseInt(n || 0).toLocaleString('en-IN');
    const fmtNum = (n) => fmtFull(n);
    const safe = (fn) => { try { fn(); } catch(e) { console.warn('[DV4]', e); } };

    function isDark() { return document.body.classList.contains('dark-theme'); }
    function gridColor() { return isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)'; }
    function labelColor() { return isDark() ? '#8b949e' : '#64748b'; }

    function trendHTML(kpi) {
        if (!kpi || kpi.trend === undefined) return '';
        const pct = Math.abs(kpi.change_pct || 0).toFixed(1);
        const cls = kpi.trend === 'up' ? 'up' : (kpi.trend === 'down' ? 'down' : 'neutral');
        const icon = kpi.trend === 'up' ? 'fa-arrow-up' : (kpi.trend === 'down' ? 'fa-arrow-down' : 'fa-minus');
        return `<span class="dv4-kpi-trend ${cls}"><i class="fas ${icon}"></i> ${pct}%</span>`;
    }

    function statusDot(status) {
        const colors = { completed: '#10b981', posted: '#10b981', paid: '#10b981', draft: '#f59e0b', approved: '#3b82f6', partial: '#f59e0b', voided: '#ef4444' };
        return `<span class="dv4-status-dot" style="background:${colors[status] || '#94a3b8'}"></span>`;
    }

    function kpiLink(kpiKey) {
        const today = new Date().toISOString().slice(0, 10);
        const links = {
            today_sales:        `?page=reports/sales/register&date_from=${today}&date_to=${today}`,
            today_expenses:     `?page=transactions/expense&date_from=${today}&date_to=${today}`,
            today_gross_profit: `?page=reports/financial/daily_profit&date_from=${today}&date_to=${today}`,
            today_purchase:     `?page=reports/purchases/by_vendor&date_from=${today}&date_to=${today}`,
            today_net_profit:   `?page=reports/financial/daily_profit&date_from=${today}&date_to=${today}`,
            cash_on_hand:    `?page=reports/financial/general_ledger&account_type=cash`,
            bank_balance:    `?page=reports/financial/general_ledger&account_type=bank`,
            ar:              `?page=reports/customers/statement`,
            ap:              `?page=reports/purchases/by_vendor`,
            inventory_value: `?page=reports/inventory/stock_summary`,
            low_stock:       `?page=reports/inventory/low_stock`
        };
        return links[kpiKey] || '#';
    }

    function chartOpts(extra) {
        return Object.assign({
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false, labels: { color: labelColor(), font: { size: 10 } } } },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor() }, ticks: { color: labelColor(), font: { size: 9 } } },
                x: { grid: { display: false }, ticks: { color: labelColor(), font: { size: 9 } } }
            }
        }, extra);
    }

    function initTheme() {
        const saved = localStorage.getItem(CONFIG.STORAGE_PREFIX + 'theme');
        if (saved === 'dark') document.body.classList.add('dark-theme');
        const btn = $('dv4-theme-btn');
        if (btn) {
            btn.addEventListener('click', function() {
                document.body.classList.toggle('dark-theme');
                const dark = document.body.classList.contains('dark-theme');
                localStorage.setItem(CONFIG.STORAGE_PREFIX + 'theme', dark ? 'dark' : 'light');
                Object.values(state.charts).forEach(c => { if (c && c.update) c.update({ duration: 0 }); });
            });
        }
    }

    function initCharts() {
        const sCanvas = $('chart-sales');
        if (sCanvas) {
            state.charts.sales = new Chart(sCanvas.getContext('2d'), {
                type: 'line',
                data: { labels: [], datasets: [{ label: 'Sales', data: [], borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.08)', fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#3b82f6', pointHoverRadius: 5 }] },
                options: chartOpts({ interaction: { intersect: false, mode: 'index' } })
            });
        }
        const pCanvas = $('chart-payments');
        if (pCanvas) {
            state.charts.payments = new Chart(pCanvas.getContext('2d'), {
                type: 'doughnut',
                data: { labels: [], datasets: [{ data: [], backgroundColor: ['#10b981','#3b82f6','#8b5cf6','#6366f1','#14b8a6','#ef4444'], borderWidth: 0, hoverOffset: 10 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: true, position: 'bottom', labels: { color: labelColor(), padding: 12, font: { size: 10 }, usePointStyle: true } } } }
            });
        }
        const cCanvas = $('chart-categories');
        if (cCanvas) {
            state.charts.categories = new Chart(cCanvas.getContext('2d'), {
                type: 'doughnut',
                data: { labels: [], datasets: [{ data: [], backgroundColor: ['#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#14b8a6','#6366f1','#f43f5e','#d946ef','#a855f7','#22c55e','#eab308','#3abff8','#f472b6','#06b6d4','#fb7185','#fb923c','#a78bfa','#4ade80'], borderWidth: 0, hoverOffset: 10 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: true, position: 'bottom', labels: { color: labelColor(), padding: 12, font: { size: 10 }, usePointStyle: true } } } }
            });
        }
        const hCanvas = $('chart-hourly');
        if (hCanvas) {
            state.charts.hourly = new Chart(hCanvas.getContext('2d'), {
                type: 'bar',
                data: { labels: [], datasets: [{ label: 'Sales', data: [], backgroundColor: 'rgba(6,182,212,0.7)', borderRadius: 4, borderSkipped: false }] },
                options: chartOpts()
            });
        }
        const gCanvas = $('chart-gp');
        if (gCanvas) {
            state.charts.gp = new Chart(gCanvas.getContext('2d'), {
                type: 'bar',
                data: { labels: [], datasets: [{ label: 'Gross Profit', data: [], backgroundColor: 'rgba(245,158,11,0.7)', borderRadius: 4, borderSkipped: false }] },
                options: chartOpts()
            });
        }
        const eCanvas = $('chart-expenses');
        if (eCanvas) {
            state.charts.expenses = new Chart(eCanvas.getContext('2d'), {
                type: 'doughnut',
                data: { labels: [], datasets: [{ data: [], backgroundColor: ['#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#14b8a6','#6366f1','#f43f5e','#d946ef','#a855f7','#22c55e','#eab308','#3abff8','#f472b6','#06b6d4','#fb7185','#fb923c','#a78bfa','#4ade80'], borderWidth: 0, hoverOffset: 10 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: true, position: 'right', labels: { color: labelColor(), padding: 8, font: { size: 10 }, usePointStyle: true } } } }
            });
        }
    }

    // === RENDER FUNCTIONS ===

    function renderKPIs(data) {
        const kpis = data.kpi || {};
        const map = [
            { id: 'kpi-sales-val',        sub: 'kpi-sales-sub',        k: 'today_sales',        suffix: 'vs yesterday', tile: 'kpi-tile-sales' },
            { id: 'kpi-expenses-val',     sub: 'kpi-expenses-sub',     k: 'today_expenses',     suffix: 'vs yesterday', tile: 'kpi-tile-expenses' },
            { id: 'kpi-gross-profit-val', sub: 'kpi-gross-profit-sub', k: 'today_gross_profit', suffix: 'vs yesterday', tile: 'kpi-tile-gross-profit' },
            { id: 'kpi-purchase-val',     sub: 'kpi-purchase-sub',     k: 'today_purchase',     suffix: 'vs yesterday', tile: 'kpi-tile-purchase' },
            { id: 'kpi-net-profit-val',   sub: 'kpi-net-profit-sub',   k: 'today_net_profit',   suffix: 'vs yesterday', tile: 'kpi-tile-net-profit' },
            { id: 'kpi-cash-val',         sub: 'kpi-cash-sub',         k: 'cash_on_hand',       suffix: 'vs yesterday', tile: 'kpi-tile-cash' },
            { id: 'kpi-bank-val',         sub: 'kpi-bank-sub',         k: 'bank_balance',       suffix: 'vs yesterday', tile: 'kpi-tile-bank' },
            { id: 'kpi-ar-val',           sub: 'kpi-ar-sub',           k: 'ar',                 suffix: 'outstanding',  tile: 'kpi-tile-ar' },
            { id: 'kpi-ap-val',           sub: 'kpi-ap-sub',           k: 'ap',                 suffix: 'outstanding',  tile: 'kpi-tile-ap' },
            { id: 'kpi-inv-val',          sub: 'kpi-inv-sub',          k: 'inventory_value',    suffix: 'current value',tile: 'kpi-tile-inv' },
            { id: 'kpi-low-val',          sub: 'kpi-low-sub',          k: 'low_stock',          suffix: 'items need reorder', tile: 'kpi-tile-low' }
        ];
        map.forEach(m => {
            safe(() => {
                const kpi = kpis[m.k];
                const valEl = el(m.id);
                const subEl = el(m.sub);
                const tile  = el(m.tile);
                if (!kpi || kpi.value === undefined) { if (tile) tile.style.display = 'none'; return; }
                if (tile) {
                    tile.style.display = '';
                    // Set the href on the anchor tile so the whole card is clickable
                    if (tile.tagName === 'A') tile.href = kpiLink(m.k);
                }
                if (valEl) {
                    valEl.textContent = m.k === 'low_stock' ? fmtInt(kpi.value) : fmt(kpi.value);
                    valEl.title = fmtFull(kpi.value); // show exact value on hover
                }
                if (subEl) subEl.innerHTML = trendHTML(kpi) + ' <span style="opacity:0.7;">' + m.suffix + '</span>';
            });
        });
    }

    function renderCharts(data) {
        safe(() => { const st = data.sales_trend || {}; if (state.charts.sales) { state.charts.sales.data.labels = st.labels || []; state.charts.sales.data.datasets[0].data = st.values || []; state.charts.sales.update('none'); } });
        safe(() => {
            const sp = data.sales_payment;
            const card = el('widget-chart-payments');
            if (!sp && card) { card.style.display = 'none'; }
            else if (card) {
                card.style.display = '';
                if (state.charts.payments) {
                    state.charts.payments.data.labels = sp.labels || [];
                    state.charts.payments.data.datasets[0].data = sp.values || [];
                    state.charts.payments.update('none');
                }
            }
        });
        safe(() => {
            const sc = data.sales_category;
            const card = el('widget-chart-categories');
            if (!sc && card) { card.style.display = 'none'; }
            else if (card) {
                card.style.display = '';
                if (state.charts.categories) {
                    state.charts.categories.data.labels = sc.labels || [];
                    state.charts.categories.data.datasets[0].data = sc.values || [];
                    state.charts.categories.update('none');
                }
            }
        });
        safe(() => { const sh = data.sales_hourly || {}; if (state.charts.hourly) { state.charts.hourly.data.labels = sh.labels || []; state.charts.hourly.data.datasets[0].data = sh.values || []; state.charts.hourly.update('none'); } });
        safe(() => { const gp = data.gp_trend || {}; if (state.charts.gp) { state.charts.gp.data.labels = gp.labels || []; state.charts.gp.data.datasets[0].data = gp.values || []; state.charts.gp.update('none'); } });
        safe(() => { const exp = data.expenses || {}; if (state.charts.expenses) { state.charts.expenses.data.labels = exp.labels || []; state.charts.expenses.data.datasets[0].data = exp.values || []; state.charts.expenses.update('none'); } });
    }

    function renderInventory(data) {
        const inv = data.inventory || {};
        safe(() => {
            el('inv-total') && (el('inv-total').textContent = fmtInt(inv.total_items));
            el('inv-active') && (el('inv-active').textContent = fmtInt(inv.active_items));
            const oos = el('inv-oos'); if (oos) { oos.textContent = fmtInt(inv.out_of_stock); oos.style.color = inv.out_of_stock > 0 ? '#ef4444' : '#10b981'; }
            const ls = el('inv-low'); if (ls) { ls.textContent = fmtInt(inv.low_stock); ls.style.color = inv.low_stock > 0 ? '#f59e0b' : '#10b981'; }
            const ns = el('inv-neg'); if (ns) { ns.textContent = fmtInt(inv.negative_stock); ns.style.color = inv.negative_stock > 0 ? '#ef4444' : '#10b981'; }
            el('inv-over') && (el('inv-over').textContent = fmtInt(inv.overstock));
            el('inv-value') && (el('inv-value').textContent = fmt(inv.value));
        });
        safe(() => {
            const body = el('top-selling-body');
            if (!body) return;
            const items = inv.top_selling || [];
            if (items.length) {
                body.innerHTML = items.map((item, i) => `<tr><td style="padding-left:14px;"><span style="font-weight:700;color:var(--dv4-text-muted);margin-right:5px;">${i+1}</span><span style="font-weight:600;">${item.item_name}</span><span style="font-size:9px;color:var(--dv4-text-muted);margin-left:4px;">${item.sku}</span></td><td style="text-align:right;font-weight:600;">${fmtInt(item.total_qty)}</td><td style="text-align:right;padding-right:14px;font-weight:700;color:#10b981;">${fmt(item.total_amount)}</td></tr>`).join('');
            } else {
                body.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:16px;color:var(--dv4-text-muted);">No sales this month</td></tr>';
            }
        });
        safe(() => {
            const body = el('slow-moving-body');
            if (!body) return;
            const items = inv.slow_moving || [];
            if (items.length) {
                body.innerHTML = items.slice(0, 10).map(item => {
                    const days = parseInt(item.days_inactive);
                    const pill = days > 90 ? 'dv4-pill-red' : (days > 60 ? 'dv4-pill-amber' : 'dv4-pill-blue');
                    return `<tr><td style="padding-left:14px;"><span style="font-weight:600;">${item.item_name}</span><span style="font-size:9px;color:var(--dv4-text-muted);margin-left:4px;">${item.sku}</span></td><td style="text-align:right;">${parseFloat(item.current_stock).toFixed(0)}</td><td style="text-align:right;padding-right:14px;"><span class="dv4-pill ${pill}">${days}d</span></td></tr>`;
                }).join('');
            } else {
                body.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:16px;color:#10b981;"><i class="fas fa-check-circle"></i> All items moving well</td></tr>';
            }
        });
    }

    function renderFinancial(data) {
        const vat = data.vat || {};
        const mon = data.monthly || {};
        safe(() => {
            el('vat-taxable') && (el('vat-taxable').textContent = fmtFull(vat.taxable));
            el('vat-collected') && (el('vat-collected').textContent = fmtFull(vat.collected));
            el('vat-paid') && (el('vat-paid').textContent = fmtFull(vat.paid));
            const liab = el('vat-liability');
            if (liab) { const val = vat.liability || 0; liab.textContent = fmtFull(val); liab.style.color = val > 0 ? '#f59e0b' : '#10b981'; }
        });
        safe(() => {
            const body = el('monthly-body');
            if (!body) return;
            const rows = [['Sales', mon.sales, mon.sales_last],['Purchases', mon.purchases, mon.purchases_last],['Expenses', mon.expenses, mon.expenses_last],['Gross Profit', mon.profit, mon.profit_last]].filter(r => r[1] !== undefined);
            if (rows.length) {
                body.innerHTML = rows.map(([label, curr, prev]) => {
                    curr = parseFloat(curr || 0); prev = parseFloat(prev || 0);
                    const chg = prev !== 0 ? ((curr - prev) / prev * 100).toFixed(1) : (curr > 0 ? '100.0' : '0.0');
                    const up = parseFloat(chg) >= 0;
                    return `<tr><td style="padding-left:14px;font-weight:600;">${label}</td><td style="text-align:right;font-weight:700;">${fmt(curr)}</td><td style="text-align:right;padding-right:14px;"><span class="${up ? 'dv4-badge-up' : 'dv4-badge-down'}"><i class="fas fa-arrow-${up ? 'up' : 'down'}"></i> ${Math.abs(chg)}%</span></td></tr>`;
                }).join('');
            } else {
                body.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:16px;color:var(--dv4-text-muted);">No data</td></tr>';
            }
        });
        safe(() => {
            el('fy-label') && (el('fy-label').textContent = mon.fy_label || 'FY');
            const fyStock = el('fy-stock'); if (fyStock) fyStock.textContent = fmtFull(mon.fy_stock !== undefined ? mon.fy_stock : (data.inventory ? data.inventory.value : 0));
            const fyPurch = el('fy-purchase'); if (fyPurch) fyPurch.textContent = fmtFull(mon.fy_purchases);
            const fyS = el('fy-sales'); if (fyS) fyS.textContent = fmtFull(mon.fy_sales);
            const fyExp = el('fy-expenses'); if (fyExp) fyExp.textContent = fmtFull(mon.fy_expenses);
            const fyP = el('fy-profit'); if (fyP) { fyP.textContent = fmtFull(mon.fy_profit); fyP.style.color = mon.fy_profit > 0 ? '#10b981' : '#ef4444'; }
        });
    }

    function renderCustomersSuppliers(data) {
        const cust = data.customers || {};
        const supp = data.suppliers || {};
        safe(() => {
            el('cust-total') && (el('cust-total').textContent = fmtInt(cust.total));
            el('cust-new') && (el('cust-new').textContent = fmtInt(cust.new));
            el('cust-ar') && (el('cust-ar').textContent = fmt(cust.outstanding_ar));
            el('supp-total') && (el('supp-total').textContent = fmtInt(supp.total));
            el('supp-new') && (el('supp-new').textContent = fmtInt(supp.new));
            el('supp-ap') && (el('supp-ap').textContent = fmt(supp.outstanding_ap));
        });
        safe(() => {
            const body = el('top-cust-body');
            if (!body) return;
            const items = cust.top || [];
            if (items.length) {
                const colors = ['#3b82f6','#10b981','#8b5cf6','#f59e0b','#ec4899'];
                body.innerHTML = items.map((c, i) => `<div class="dv4-entity-item" onclick="window.location='?page=master/customer/view&id=${c.id || ''}'"><div class="dv4-entity-info"><div class="dv4-entity-avatar" style="background:${colors[i]}">${c.full_name.charAt(0)}</div><div><div class="dv4-entity-name">${c.full_name}</div><div class="dv4-entity-meta">Top ${i+1} by sales</div></div></div><div class="dv4-entity-amount" style="color:#10b981;">${fmt(c.total_sales)}</div></div>`).join('');
            } else {
                body.innerHTML = '<div class="dv4-empty"><i class="fas fa-users"></i><div class="dv4-empty-text">No customer data</div></div>';
            }
        });
        safe(() => {
            const body = el('out-ar-body');
            if (!body) return;
            const items = cust.out_receivables || [];
            if (items.length) {
                body.innerHTML = items.map(c => `<div class="dv4-entity-item" onclick="window.location='?page=master/customer'"><div class="dv4-entity-info"><div class="dv4-entity-avatar" style="background:#ef4444">${c.full_name.charAt(0)}</div><div><div class="dv4-entity-name">${c.full_name}</div><div class="dv4-entity-meta">${c.phone || 'N/A'} · ${c.customer_type || ''}</div></div></div><div class="dv4-entity-amount" style="color:#ef4444;">${fmt(c.balance)}</div></div>`).join('');
            } else {
                body.innerHTML = '<div class="dv4-empty"><i class="fas fa-check-circle" style="color:#10b981;"></i><div class="dv4-empty-text" style="color:#10b981;">All clear</div></div>';
            }
        });
        safe(() => {
            const body = el('out-ap-body');
            if (!body) return;
            const items = supp.out_payables || [];
            if (items.length) {
                body.innerHTML = items.map(v => `<div class="dv4-entity-item" onclick="window.location='?page=master/vendor'"><div class="dv4-entity-info"><div class="dv4-entity-avatar" style="background:#ec4899">${v.company_name.charAt(0)}</div><div><div class="dv4-entity-name">${v.company_name}</div><div class="dv4-entity-meta">${v.phone || 'N/A'}</div></div></div><div class="dv4-entity-amount" style="color:#ec4899;">${fmt(v.balance)}</div></div>`).join('');
            } else {
                body.innerHTML = '<div class="dv4-empty"><i class="fas fa-check-circle" style="color:#10b981;"></i><div class="dv4-empty-text" style="color:#10b981;">All paid</div></div>';
            }
        });
        safe(() => {
            const body = el('bills-due-body');
            if (!body) return;
            const bills = supp.bills_due || [];
            if (bills.length) {
                body.innerHTML = bills.map(b => `<div class="dv4-entity-item" onclick="window.location='?page=transactions/bill/view&id=${b.id}'"><div class="dv4-entity-info"><div><div class="dv4-entity-name">${b.company_name}</div><div class="dv4-entity-meta">${b.vendor_invoice_number} · Due ${b.due_date}</div></div></div><div class="dv4-entity-amount" style="color:#f59e0b;">${fmt(b.balance_due)}</div></div>`).join('');
            } else {
                body.innerHTML = '<div class="dv4-empty"><i class="fas fa-check-circle" style="color:#10b981;"></i><div class="dv4-empty-text" style="color:#10b981;">No bills due this week</div></div>';
            }
        });
    }

    function renderAlerts(data) {
        const alerts = data.alerts || [];
        safe(() => {
            const container = el('alerts-container');
            const countEl = el('alerts-count');
            if (!container) return;
            if (countEl) { countEl.textContent = alerts.length + ' Active'; countEl.className = 'dv4-pill ' + (alerts.length > 0 ? 'dv4-pill-red' : 'dv4-pill-green'); }
            if (!alerts.length) { container.innerHTML = '<div class="dv4-empty"><i class="fas fa-check-circle" style="color:#10b981;opacity:1;"></i><div class="dv4-empty-text" style="color:#10b981;">All systems operational</div></div>'; return; }
            const sevColors = { critical: '#ef4444', danger: '#dc2626', warning: '#f59e0b', info: '#3b82f6' };
            container.innerHTML = alerts.map(a => {
                const color = sevColors[a.severity] || '#64748b';
                return `<div class="dv4-alert-item" style="border-left-color:${color};" onclick="window.location='${a.link || '#'}'"><div class="dv4-alert-icon" style="background:${a.icon_bg || color}"><i class="fas ${a.icon || 'fa-bell'}"></i></div><div class="dv4-alert-content"><div class="dv4-alert-title">${a.title}</div><div class="dv4-alert-desc">${a.desc}</div></div></div>`;
            }).join('');
        });
    }

    function renderActivities(data) {
        const activities = data.activities || [];
        safe(() => {
            const container = el('activities-container');
            if (!container) return;
            if (!activities.length) { container.innerHTML = '<div class="dv4-empty"><i class="fas fa-clock"></i><div class="dv4-empty-text">No transactions today</div></div>'; return; }
            const typeColors = { 'POS Sale': '#10b981', 'Invoice': '#3b82f6', 'Purchase': '#f59e0b', 'Payment In': '#8b5cf6', 'Payment Out': '#ec4899', 'Journal': '#6366f1' };
            container.innerHTML = activities.map(a => {
                const color = typeColors[a.type] || '#64748b';
                const isNeg = a.type === 'Purchase' || a.type === 'Payment Out';
                return `<div class="dv4-timeline-item" onclick="window.location='${a.link || '#'}'"><div class="dv4-timeline-dot" style="background:${color}"></div><div class="dv4-timeline-content"><div class="dv4-timeline-header"><div><span class="dv4-timeline-title">${a.ref || ''}</span><span class="dv4-pill dv4-pill-gray" style="margin-left:6px;">${a.type}</span></div><div class="dv4-timeline-amount" style="color:${isNeg ? '#ef4444' : '#10b981'}">${isNeg ? '-' : '+'}${fmt(a.amount)}</div></div><div class="dv4-timeline-meta"><span>${a.party || ''}</span><span>${a.time || ''}</span>${a.status ? statusDot(a.status) + a.status : ''}</div></div></div>`;
            }).join('');
        });
    }

    function renderCashSummary(data) {
        const cs = data.cash_summary || {};
        safe(() => {
            const fmtCash = (n) => 'Rs ' + parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const opening = el('cash-opening'); if (opening) opening.textContent = fmtCash(cs.opening);
            const cashIn = el('cash-in'); if (cashIn) cashIn.textContent = fmtCash(cs.cash_in);
            const cashOut = el('cash-out'); if (cashOut) cashOut.textContent = fmtCash(cs.cash_out);
            const closing = el('cash-closing'); if (closing) closing.textContent = fmtCash(cs.closing);
            const diff = el('cash-diff');
            if (diff) { const d = parseFloat(cs.diff || 0); diff.textContent = (d >= 0 ? '+' : '') + fmtCash(d); diff.style.color = d > 0 ? '#10b981' : (d < 0 ? '#ef4444' : 'var(--dv4-text)'); }
            const expected = el('cash-expected'); if (expected) expected.textContent = fmtCash(cs.expected || 0);
            const counted = el('cash-counted-by');
            if (counted) { counted.textContent = cs.counted_by || 'Not Counted'; const cls = cs.counted_by === 'Verified' ? 'dv4-pill-green' : (cs.counted_by === 'Counted' ? 'dv4-pill-blue' : 'dv4-pill-amber'); counted.className = 'dv4-pill ' + cls; }
        });
    }

    function renderReminders(data) {
        const rem = data.reminders || {};
        safe(() => {
            el('rem-bills') && (el('rem-bills').textContent = rem.bills_to_pay || 0);
            el('rem-invoices') && (el('rem-invoices').textContent = rem.open_invoices || 0);
            el('rem-low') && (el('rem-low').textContent = rem.low_stock || 0);
        });
    }

    // === BANK ACCOUNT DETAIL TILE ===
    let bankAccountsCache = [];

    function renderBankAccountDetail(data) {
        const accounts = data.bank_accounts || [];
        bankAccountsCache = accounts;
        safe(() => {
            const select = el('bank-account-select');
            if (!select) return;
            // Remember the currently selected value
            const prevVal = select.value;
            // Always rebuild the dropdown from fresh API data
            select.innerHTML = '<option value="">Select Bank Account...</option>';
            if (accounts.length > 0) {
                accounts.forEach(acc => {
                    const opt = document.createElement('option');
                    opt.value = acc.id;
                    opt.textContent = acc.account_name + ' (' + acc.account_code + ')';
                    select.appendChild(opt);
                });
                // Restore previous selection if it still exists
                if (prevVal) {
                    select.value = prevVal;
                    // Trigger stats display for restored selection
                    if (select.value === prevVal) {
                        showBankAccountStats(prevVal);
                    }
                }
            }
        });
    }

    function showBankAccountStats(accountId) {
        const acc = bankAccountsCache.find(a => a.id == accountId);
        const stats = el('bank-account-stats');
        const empty = el('ba-empty-state');
        const headerName = el('ba-header-account-name');
        if (!acc) {
            if (stats) stats.style.display = 'none';
            if (headerName) headerName.textContent = '';
            if (empty) empty.style.display = '';
            return;
        }
        if (stats) stats.style.display = '';
        if (empty) empty.style.display = 'none';
        safe(() => {
            if (headerName) headerName.textContent = ' — ' + acc.account_name + ' (' + acc.account_code + ')';
            const opening = parseFloat(acc.balance || 0) - parseFloat(acc.today_in || 0) + parseFloat(acc.today_out || 0);
            const openEl = el('ba-opening');
            if (openEl) { openEl.textContent = fmtFull(opening); openEl.style.color = opening >= 0 ? 'var(--dv4-text)' : '#ef4444'; }
            el('ba-today-in') && (el('ba-today-in').textContent = fmtFull(acc.today_in));
            el('ba-today-out') && (el('ba-today-out').textContent = fmtFull(acc.today_out));
            const balEl = el('ba-balance');
            if (balEl) { balEl.textContent = fmtFull(acc.balance); balEl.style.color = acc.balance >= 0 ? '#10b981' : '#ef4444'; }
        });
    }

    function initBankAccountSelect() {
        const select = el('bank-account-select');
        if (select) {
            select.addEventListener('change', function() {
                showBankAccountStats(this.value);
            });
        }
    }

    // === SALES RANGE SWITCHER ===
    window.refreshSalesChart = function() {
        const sel = $('chart-sales-range');
        if (!sel) return;
        fetch(CONFIG.API + '?sales_range=' + sel.value)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'error') return;
                if (state.charts.sales && data.sales_trend) { state.charts.sales.data.labels = data.sales_trend.labels || []; state.charts.sales.data.datasets[0].data = data.sales_trend.values || []; state.charts.sales.update('none'); }
            })
            .catch(() => {});
    };

    // === MAIN REFRESH ===
    function refreshDashboard() {
        const loader = el('dv4-loader');
        if (loader) loader.classList.add('active');
        fetch(CONFIG.API)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'error') { console.error('[DV4] API Error:', data.message); if (loader) loader.classList.remove('active'); return; }
                state.data = data; state.lastRefresh = new Date();
                const rt = el('dv4-refresh-time'); if (rt) rt.textContent = state.lastRefresh.toLocaleTimeString();
                const qt = el('dv4-query-time'); if (qt && data.query_time_ms) qt.textContent = data.query_time_ms + 'ms';
            safe(() => renderKPIs(data));
            safe(() => renderCharts(data));
            safe(() => renderCashSummary(data));
            safe(() => renderInventory(data));
            safe(() => renderFinancial(data));
            safe(() => renderCustomersSuppliers(data));
            safe(() => renderAlerts(data));
            safe(() => renderActivities(data));
            safe(() => renderBankAccountDetail(data));
            safe(() => renderReminders(data));
                if (loader) loader.classList.remove('active');
            })
            .catch(err => { console.error('[DV4] Fetch error:', err); if (loader) loader.classList.remove('active'); });
    }

    // === FAB ===
    function initFAB() {
        const fabBtn = el('dv4-fab-main');
        const fabItems = el('dv4-fab-items');
        if (!fabBtn || !fabItems) return;
        fabBtn.addEventListener('click', function() { this.classList.toggle('open'); fabItems.classList.toggle('open'); });
        document.addEventListener('click', function(e) {
            const container = el('dv4-fab-container');
            if (container && !container.contains(e.target)) { fabBtn.classList.remove('open'); fabItems.classList.remove('open'); }
        });
    }

    // === INITIALIZATION ===
    function init() {
        console.log('[DV4] Dashboard V4 initializing...');
        initTheme(); initCharts(); initFAB(); initBankAccountSelect();
        const refreshBtn = el('dv4-refresh-btn');
        if (refreshBtn) refreshBtn.addEventListener('click', refreshDashboard);
        const rangeSel = $('chart-sales-range');
        if (rangeSel) rangeSel.addEventListener('change', window.refreshSalesChart);
        refreshDashboard();
        state.intervalId = setInterval(refreshDashboard, CONFIG.INTERVAL);
        console.log('[DV4] Dashboard V4 initialized. Auto-refresh every ' + (CONFIG.INTERVAL / 1000) + 's');
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }

})();