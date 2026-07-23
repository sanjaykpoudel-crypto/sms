<?php
// Master Lists Center - All Master Lists & Directories
?>
<style>
    .mst-page { padding: 24px; background: #f4f7fa; min-height: 80vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .mst-page-header {
        display: flex; align-items: center; gap: 14px;
        margin-bottom: 28px;
    }
    .mst-page-header h1 {
        font-size: 20px; font-weight: 800; color: #2c3e50; margin: 0;
        letter-spacing: 0.3px;
    }
    .mst-page-header .header-icon {
        width: 44px; height: 44px; background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        border-radius: 12px; display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 20px; box-shadow: 0 4px 12px rgba(139,92,246,0.3);
    }

    .mst-categories { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 22px; }

    .mst-category-card {
        background: #fff; border-radius: 16px;
        box-shadow: 0 4px 18px rgba(0,0,0,0.05);
        border: 1px solid #eef2f6; overflow: hidden;
        transition: box-shadow 0.3s;
    }
    .mst-category-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,0.09); }

    .mst-category-header {
        padding: 14px 20px;
        display: flex; align-items: center; gap: 12px;
        border-bottom: 1px solid #f1f4f8;
    }
    .mst-category-header .cat-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; color: #fff; flex-shrink: 0;
    }
    .mst-category-header h3 {
        font-size: 13px; font-weight: 800; color: #34495e; margin: 0;
        text-transform: uppercase; letter-spacing: 0.5px;
    }

    .mst-links-list { padding: 8px 0; }
    .mst-link-item {
        display: flex; align-items: center; gap: 12px;
        padding: 11px 20px;
        text-decoration: none; color: #2c3e50;
        font-size: 13px; font-weight: 500;
        border-bottom: 1px solid #f8f9fb;
        transition: all 0.2s;
    }
    .mst-link-item:last-child { border-bottom: none; }
    .mst-link-item:hover {
        background: #f5f3ff;
        color: #7c3aed;
        padding-left: 26px;
    }
    .mst-link-item .mst-dot {
        width: 7px; height: 7px; border-radius: 50%;
        flex-shrink: 0;
        transition: transform 0.2s;
    }
    .mst-link-item:hover .mst-dot { transform: scale(1.4); }
    .mst-link-item .mst-arrow {
        margin-left: auto; font-size: 10px; opacity: 0;
        transition: opacity 0.2s, transform 0.2s;
        color: #7c3aed;
        transform: translateX(-4px);
    }
    .mst-link-item:hover .mst-arrow { opacity: 1; transform: translateX(0); }
    .mst-link-item .mst-desc {
        font-size: 10px; color: #95a5a6; font-weight: 400;
        display: block; margin-top: 1px;
    }
</style>

<div class="mst-page">
    <div class="mst-page-header">
        <div class="header-icon"><i class="fas fa-list-ul"></i></div>
        <div>
            <h1>Lists Center</h1>
            <div style="font-size: 12px; color: #7f8c8d; margin-top: 2px;">Directory of master records, accounts, and system entities</div>
        </div>
    </div>

    <div class="mst-categories">

        <!-- Parties & Relationships -->
        <div class="mst-category-card">
            <div class="mst-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="fas fa-address-book"></i>
                </div>
                <div>
                    <h3>Parties & Relationships</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">Customers, Vendors & Users</div>
                </div>
            </div>
            <div class="mst-links-list">
                <a href="?page=master/customer" class="mst-link-item">
                    <span class="mst-dot" style="background:#ef4444;"></span>
                    <span>
                        Customer Master List
                        <span class="mst-desc">Directory of all customers, phone, and credit limits</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
                <a href="?page=master/vendor" class="mst-link-item">
                    <span class="mst-dot" style="background:#8b5cf6;"></span>
                    <span>
                        Vendor Master List
                        <span class="mst-desc">Directory of suppliers and distributor contacts</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
                <a href="?page=system/users" class="mst-link-item">
                    <span class="mst-dot" style="background:#3b82f6;"></span>
                    <span>
                        Employees & System Users
                        <span class="mst-desc">User accounts, roles, and security permissions</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Inventory & Products -->
        <div class="mst-category-card">
            <div class="mst-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="fas fa-boxes"></i>
                </div>
                <div>
                    <h3>Item Catalog & Inventory</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">Products, Categories & Units</div>
                </div>
            </div>
            <div class="mst-links-list">
                <a href="?page=master/item" class="mst-link-item">
                    <span class="mst-dot" style="background:#f59e0b;"></span>
                    <span>
                        Item Master Catalog
                        <span class="mst-desc">Product catalog with cost, selling prices & stock levels</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
            </div>
        </div>

        <!-- Accounting & Finance -->
        <div class="mst-category-card">
            <div class="mst-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="fas fa-calculator"></i>
                </div>
                <div>
                    <h3>Accounts & Banking</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">Chart of Accounts & Opening Balances</div>
                </div>
            </div>
            <div class="mst-links-list">
                <a href="?page=master/account" class="mst-link-item">
                    <span class="mst-dot" style="background:#10b981;"></span>
                    <span>
                        Chart of Accounts
                        <span class="mst-desc">List of all asset, liability, equity, income & expense accounts</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
                <a href="?page=master/account/opening_balance" class="mst-link-item">
                    <span class="mst-dot" style="background:#059669;"></span>
                    <span>
                        Bank & Cash Opening Balances
                        <span class="mst-desc">Configure starting balances for cash & bank accounts</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
            </div>
        </div>

        <!-- System & Configuration -->
        <div class="mst-category-card">
            <div class="mst-category-header">
                <div class="cat-icon" style="background: linear-gradient(135deg, #64748b, #475569);">
                    <i class="fas fa-cogs"></i>
                </div>
                <div>
                    <h3>System Configuration</h3>
                    <div style="font-size: 10px; color: #95a5a6; font-weight: 500;">Numbering, Periods & Settings</div>
                </div>
            </div>
            <div class="mst-links-list">
                <a href="?page=system/company/manage" class="mst-link-item">
                    <span class="mst-dot" style="background:#64748b;"></span>
                    <span>
                        Company System Information
                        <span class="mst-desc">Business details, logo, and contact info</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
                <a href="?page=system/fiscal_years" class="mst-link-item">
                    <span class="mst-dot" style="background:#475569;"></span>
                    <span>
                        Fiscal Years & Period Closing
                        <span class="mst-desc">Manage accounting fiscal years and closing dates</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
                <a href="?page=system/ref_codes/manage" class="mst-link-item">
                    <span class="mst-dot" style="background:#0284c7;"></span>
                    <span>
                        Auto-Generated Reference Numbers
                        <span class="mst-desc">Prefixes and numbering sequence rules for invoices/bills</span>
                    </span>
                    <i class="fas fa-arrow-right mst-arrow"></i>
                </a>
            </div>
        </div>

    </div>
</div>
