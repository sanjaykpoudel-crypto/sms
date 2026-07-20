-- liquor_erp_schema.sql
-- Consolidated ERP Schema and Seed Data
-- Database: sms_db

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. DROP EXISTING TABLES
-- ============================================================
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS system_info;
DROP TABLE IF EXISTS system_logs;
DROP TABLE IF EXISTS journal_entries;
DROP TABLE IF EXISTS cash_denominations;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS account_transfers;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS customer_invoices;
DROP TABLE IF EXISTS vendor_bills;
DROP TABLE IF EXISTS transaction_lines;
DROP TABLE IF EXISTS transaction_links;
DROP TABLE IF EXISTS transaction_headers;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS vendors;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS reference_codes;

-- ============================================================
-- 2. CREATE TABLES
-- ============================================================

-- system_info (Settings)
CREATE TABLE system_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meta_field VARCHAR(100) NOT NULL UNIQUE,
    meta_value TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- audit_logs
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(50) NOT NULL,
    record_id VARCHAR(36) NOT NULL,
    action ENUM('create', 'update', 'delete') NOT NULL,
    old_values TEXT DEFAULT NULL,
    new_values TEXT DEFAULT NULL,
    user_id VARCHAR(36) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (table_name),
    INDEX (record_id),
    INDEX (user_id)
);

-- accounts
CREATE TABLE accounts (
    id VARCHAR(36) PRIMARY KEY,
    account_code VARCHAR(20) NOT NULL UNIQUE,
    account_name VARCHAR(100) NOT NULL,
    account_type ENUM('asset', 'liability', 'equity', 'income', 'expense') NOT NULL,
    account_subtype ENUM('cash', 'bank', 'receivable', 'payable', 'inventory', 'cogs', 'sales', 'tax', 'other') NOT NULL,
    normal_balance ENUM('debit', 'credit') NOT NULL,
    parent_account_id VARCHAR(36) DEFAULT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'NPR',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_account_id) REFERENCES accounts(id) ON DELETE SET NULL
);

-- items
CREATE TABLE items (
    id VARCHAR(36) PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    item_name VARCHAR(150) NOT NULL,
    item_category VARCHAR(36) DEFAULT NULL COMMENT 'FK -> reference_codes (type=category)',
    brand VARCHAR(100) DEFAULT NULL,
    barcode VARCHAR(100) DEFAULT NULL,
    bottle_size_ml DECIMAL(8,2) DEFAULT NULL,
    unit_type VARCHAR(36) DEFAULT NULL COMMENT 'FK -> reference_codes (type=units)',
    units_per_case INT DEFAULT NULL,
    cost_price DECIMAL(12,2) NOT NULL,
    selling_price DECIMAL(12,2) NOT NULL,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    tax_id VARCHAR(36) DEFAULT NULL COMMENT 'FK -> reference_codes (type=tax_code)',
    description TEXT DEFAULT NULL,
    reorder_level INT DEFAULT NULL,
    reorder_qty INT DEFAULT NULL,
    current_stock DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    cogs_account_id VARCHAR(36) NOT NULL,
    income_account_id VARCHAR(36) NOT NULL,
    inventory_account_id VARCHAR(36) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    status_id VARCHAR(36) DEFAULT NULL COMMENT 'FK -> reference_codes (type=status)',
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    ip_address VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cogs_account_id) REFERENCES accounts(id),
    FOREIGN KEY (income_account_id) REFERENCES accounts(id),
    FOREIGN KEY (inventory_account_id) REFERENCES accounts(id)
);

-- vendors
CREATE TABLE vendors (
    id VARCHAR(36) PRIMARY KEY,
    vendor_code VARCHAR(20) UNIQUE,
    company_name VARCHAR(150) NOT NULL,
    contact_name VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    pan_number VARCHAR(20) DEFAULT NULL,
    vat_number VARCHAR(20) DEFAULT NULL,
    payable_account_id VARCHAR(36) NOT NULL,
    payment_terms_days INT DEFAULT NULL,
    credit_limit DECIMAL(14,2) DEFAULT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payable_account_id) REFERENCES accounts(id)
);

-- customers
CREATE TABLE customers (
    id VARCHAR(36) PRIMARY KEY,
    customer_code VARCHAR(20) UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    customer_type ENUM('retail', 'wholesale', 'bar', 'hotel') NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    pan_number VARCHAR(20) DEFAULT NULL,
    receivable_account_id VARCHAR(36) NOT NULL,
    credit_limit DECIMAL(14,2) DEFAULT NULL,
    payment_terms_days INT DEFAULT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (receivable_account_id) REFERENCES accounts(id)
);

-- users
CREATE TABLE users (
    id VARCHAR(36) PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'cashier', 'accountant') NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_deleted TINYINT(1) DEFAULT 0,
    last_login TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (role),
    INDEX (is_active),
    INDEX (is_deleted)
);

-- transaction_headers
CREATE TABLE transaction_headers (
    id VARCHAR(36) PRIMARY KEY,
    txn_number VARCHAR(30) NOT NULL UNIQUE,
    txn_type ENUM('vendor_bill', 'customer_invoice', 'customer_payment', 'vendor_payment', 'account_transfer', 'expense', 'cash_denomination', 'inventory_adjustment', 'Journal') NOT NULL,
    txn_date DATE NOT NULL,
    fiscal_year INT NOT NULL,
    fiscal_month INT NOT NULL,
    fiscal_period CHAR(7) NOT NULL,
    status ENUM('draft', 'approved', 'posted', 'voided', 'paid', 'partial', 'open') NOT NULL DEFAULT 'draft',
    reference_number VARCHAR(50) DEFAULT NULL,
    memo TEXT DEFAULT NULL,
    created_by VARCHAR(36) NOT NULL,
    approved_by VARCHAR(36) DEFAULT NULL,
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    ip_address VARCHAR(50) NULL,
    net_amount DECIMAL(14,2) DEFAULT 0.00,
    party_id VARCHAR(36) DEFAULT NULL,
    party_type ENUM('customer', 'vendor', 'user') DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    posted_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (txn_type),
    INDEX (status),
    INDEX (txn_date),
    INDEX (is_deleted),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- transaction_lines
CREATE TABLE transaction_lines (
    id VARCHAR(36) PRIMARY KEY,
    header_id VARCHAR(36) NOT NULL,
    item_id VARCHAR(36) DEFAULT NULL,
    account_id VARCHAR(36) NOT NULL,
    line_number INT NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    quantity DECIMAL(12,4) NOT NULL,
    unit VARCHAR(20) DEFAULT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    discount_pct DECIMAL(5,2) DEFAULT NULL,
    tax_rate DECIMAL(5,2) NOT NULL,
    tax_amount DECIMAL(14,2) NOT NULL,
    line_total DECIMAL(14,2) NOT NULL,
    cost_price DECIMAL(12,2) NOT NULL,
    gross_profit DECIMAL(14,2) NOT NULL,
    INDEX (header_id),
    INDEX (item_id),
    INDEX (account_id),
    FOREIGN KEY (header_id) REFERENCES transaction_headers(id),
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);

-- vendor_bills
CREATE TABLE vendor_bills (
    id VARCHAR(36) PRIMARY KEY,
    header_id VARCHAR(36) NOT NULL UNIQUE,
    vendor_id VARCHAR(36) NOT NULL,
    bill_date DATE NOT NULL,
    due_date DATE NOT NULL,
    vendor_invoice_number VARCHAR(50) UNIQUE,
    subtotal DECIMAL(14,2) NOT NULL,
    discount_amount DECIMAL(14,2) NOT NULL,
    tax_amount DECIMAL(14,2) NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    amount_paid DECIMAL(14,2) NOT NULL,
    balance_due DECIMAL(14,2) NOT NULL,
    payment_status ENUM('unpaid', 'partial', 'paid') NOT NULL,
    FOREIGN KEY (header_id) REFERENCES transaction_headers(id),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id)
);

-- customer_invoices
CREATE TABLE customer_invoices (
    id VARCHAR(36) PRIMARY KEY,
    header_id VARCHAR(36) NOT NULL UNIQUE,
    customer_id VARCHAR(36) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    invoice_number VARCHAR(50) UNIQUE,
    subtotal DECIMAL(14,2) NOT NULL,
    discount_amount DECIMAL(14,2) NOT NULL,
    tax_amount DECIMAL(14,2) NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    amount_paid DECIMAL(14,2) NOT NULL,
    balance_due DECIMAL(14,2) NOT NULL,
    payment_status ENUM('unpaid', 'partial', 'paid') NOT NULL,
    sale_type ENUM('cash', 'credit') NOT NULL,
    FOREIGN KEY (header_id) REFERENCES transaction_headers(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

-- payments
CREATE TABLE payments (
    id VARCHAR(36) PRIMARY KEY,
    header_id VARCHAR(36) NOT NULL,
    payment_type ENUM('customer_payment', 'vendor_payment') NOT NULL,
    vendor_id VARCHAR(36) DEFAULT NULL,
    customer_id VARCHAR(36) DEFAULT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'cheque', 'card', 'esewa', 'khalti') NOT NULL,
    bank_account_id VARCHAR(36) NOT NULL,
    applied_to_txn_id VARCHAR(36) DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL,
    cheque_number VARCHAR(50) DEFAULT NULL,
    transaction_reference VARCHAR(100) DEFAULT NULL,
    payment_date DATE NOT NULL,
    FOREIGN KEY (header_id) REFERENCES transaction_headers(id),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (bank_account_id) REFERENCES accounts(id),
    FOREIGN KEY (applied_to_txn_id) REFERENCES transaction_headers(id)
);

-- account_transfers
CREATE TABLE account_transfers (
    id VARCHAR(36) PRIMARY KEY,
    header_id VARCHAR(36) NOT NULL UNIQUE,
    from_account_id VARCHAR(36) NOT NULL,
    to_account_id VARCHAR(36) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    transfer_type ENUM('bank_to_cash', 'cash_to_bank', 'bank_to_bank', 'inter_account') NOT NULL,
    memo VARCHAR(255) DEFAULT NULL,
    transfer_date DATE NOT NULL,
    FOREIGN KEY (header_id) REFERENCES transaction_headers(id),
    FOREIGN KEY (from_account_id) REFERENCES accounts(id),
    FOREIGN KEY (to_account_id) REFERENCES accounts(id)
);

-- expenses
CREATE TABLE expenses (
    id VARCHAR(36) PRIMARY KEY,
    header_id VARCHAR(36) NOT NULL UNIQUE,
    expense_account_id VARCHAR(36) NOT NULL,
    paid_from_account_id VARCHAR(36) NOT NULL,
    vendor_id VARCHAR(36) DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    tax_amount DECIMAL(14,2) NOT NULL,
    expense_category ENUM('utilities', 'rent', 'salaries', 'transport', 'maintenance', 'marketing', 'other') NOT NULL,
    expense_date DATE NOT NULL,
    FOREIGN KEY (header_id) REFERENCES transaction_headers(id),
    FOREIGN KEY (expense_account_id) REFERENCES accounts(id),
    FOREIGN KEY (paid_from_account_id) REFERENCES accounts(id),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id)
);

-- cash_denominations
CREATE TABLE cash_denominations (
    id VARCHAR(36) PRIMARY KEY,
    header_id VARCHAR(36) NOT NULL UNIQUE,
    denomination_date DATE NOT NULL,
    denomination_type ENUM('opening', 'closing', 'mid_day') NOT NULL,
    note_1000 INT NOT NULL,
    note_500 INT NOT NULL,
    note_100 INT NOT NULL,
    note_50 INT NOT NULL,
    note_20 INT NOT NULL,
    note_10 INT NOT NULL,
    coin_5 INT NOT NULL,
    coin_2 INT NOT NULL,
    coin_1 INT NOT NULL,
    total_cash DECIMAL(14,2) NOT NULL,
    system_cash_balance DECIMAL(14,2) NOT NULL,
    difference DECIMAL(14,2) NOT NULL,
    counted_by VARCHAR(36) NOT NULL,
    FOREIGN KEY (header_id) REFERENCES transaction_headers(id),
    FOREIGN KEY (counted_by) REFERENCES users(id)
);

-- journal_entries
CREATE TABLE journal_entries (
    id VARCHAR(36) PRIMARY KEY,
    header_id VARCHAR(36) NOT NULL,
    account_id VARCHAR(36) NOT NULL,
    item_id VARCHAR(36) DEFAULT NULL,
    entry_type ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    memo VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(36) DEFAULT NULL,
    entry_date DATE NOT NULL,
    fiscal_period CHAR(7) NOT NULL,
    fiscal_year INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    party_id VARCHAR(36) DEFAULT NULL,
    party_type ENUM('customer', 'vendor', 'user') DEFAULT NULL,
    FOREIGN KEY (header_id) REFERENCES transaction_headers(id),
    FOREIGN KEY (account_id) REFERENCES accounts(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);

-- transaction_links
CREATE TABLE transaction_links (
    id VARCHAR(36) PRIMARY KEY,
    parent_id VARCHAR(36) NOT NULL,
    child_id VARCHAR(36) NOT NULL,
    link_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- system_logs
CREATE TABLE system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) DEFAULT NULL,
    action TEXT NOT NULL,
    action_type VARCHAR(50) DEFAULT NULL,
    table_name VARCHAR(50) DEFAULT NULL,
    module VARCHAR(255) NOT NULL,
    ref_id VARCHAR(100) NOT NULL,
    field_name VARCHAR(100) DEFAULT NULL,
    old_data TEXT DEFAULT NULL,
    new_data TEXT DEFAULT NULL,
    ip_address VARCHAR(50) DEFAULT NULL,
    device_info TEXT DEFAULT NULL,
    date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- reference_codes
CREATE TABLE reference_codes (
    id VARCHAR(36) PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50),
    value DECIMAL(12,4) DEFAULT 0.0000,
    symbol VARCHAR(10),
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (type),
    INDEX (is_active)
);

-- ============================================================
-- 3. SEED DATA
-- ============================================================

-- System Information Defaults
INSERT INTO system_info (meta_field, meta_value) VALUES
('name', 'Anti-Gravity Liquor ERP'),
('short_name', 'SMS ERP'),
('email', 'contact@mnsliquors.com'),
('phone', '+977-1-4000000'),
('address', 'Lazimpat, Kathmandu, Nepal'),
('pan_number', '300000000'),
('currency', 'NPR'),
('logo', 'uploads/logo.png'),
('default_asset_account', 'acc-1200'),
('default_income_account', 'acc-4100'),
('default_cogs_account', 'acc-5100'),
('default_ar_account', 'acc-1100'),
('default_ap_account', 'acc-2100'),
('default_tax_account', 'acc-2200'),
('default_bank_account', 'acc-1020'),
('default_cash_account', 'acc-1010');

-- Accounts
INSERT INTO accounts (id, account_code, account_name, account_type, account_subtype, normal_balance, parent_account_id, currency, is_active) VALUES
('acc-1000', '1000', 'Assets',               'asset',     'other',       'debit',  NULL,      'NPR', 1),
('acc-1010', '1010', 'Cash on Hand',          'asset',     'cash',        'debit',  'acc-1000','NPR', 1),
('acc-1020', '1020', 'Bank Account (Main)',   'asset',     'bank',        'debit',  'acc-1000','NPR', 1),
('acc-1030', '1030', 'Bank Account (eSewa)',  'asset',     'bank',        'debit',  'acc-1000','NPR', 1),
('acc-1100', '1100', 'Accounts Receivable',   'asset',     'receivable',  'debit',  'acc-1000','NPR', 1),
('acc-1200', '1200', 'Inventory Asset',       'asset',     'inventory',   'debit',  'acc-1000','NPR', 1),
('acc-2000', '2000', 'Liabilities',           'liability', 'other',       'credit', NULL,      'NPR', 1),
('acc-2100', '2100', 'Accounts Payable',      'liability', 'payable',     'credit', 'acc-2000','NPR', 1),
('acc-2200', '2200', 'VAT Payable',           'liability', 'tax',         'credit', 'acc-2000','NPR', 1),
('acc-2300', '2300', 'VAT Input Recoverable', 'liability', 'tax',         'debit',  'acc-2000','NPR', 1),
('acc-3000', '3000', 'Equity',                'equity',    'other',       'credit', NULL,      'NPR', 1),
('acc-3100', '3100', 'Owner Capital',         'equity',    'other',       'credit', 'acc-3000','NPR', 1),
('acc-3200', '3200', 'Retained Earnings',     'equity',    'other',       'credit', 'acc-3000','NPR', 1),
('acc-4000', '4000', 'Income',                'income',    'other',       'credit', NULL,      'NPR', 1),
('acc-4100', '4100', 'Sales - Spirits',       'income',    'sales',       'credit', 'acc-4000','NPR', 1),
('acc-4110', '4110', 'Sales - Beer',          'income',    'sales',       'credit', 'acc-4000','NPR', 1),
('acc-4120', '4120', 'Sales - Wine',          'income',    'sales',       'credit', 'acc-4000','NPR', 1),
('acc-4130', '4130', 'Sales - RTD',           'income',    'sales',       'credit', 'acc-4000','NPR', 1),
('acc-4140', '4140', 'Sales - Tobacco',       'income',    'sales',       'credit', 'acc-4000','NPR', 1),
('acc-4150', '4150', 'Sales - Other',         'income',    'sales',       'credit', 'acc-4000','NPR', 1),
('acc-5000', '5000', 'Cost of Goods Sold',    'expense',   'cogs',        'debit',  NULL,      'NPR', 1),
('acc-5100', '5100', 'COGS - Spirits',        'expense',   'cogs',        'debit',  'acc-5000','NPR', 1),
('acc-5110', '5110', 'COGS - Beer',           'expense',   'cogs',        'debit',  'acc-5000','NPR', 1),
('acc-5120', '5120', 'COGS - Wine',           'expense',   'cogs',        'debit',  'acc-5000','NPR', 1),
('acc-5130', '5130', 'COGS - RTD',            'expense',   'cogs',        'debit',  'acc-5000','NPR', 1),
('acc-5140', '5140', 'COGS - Tobacco',        'expense',   'cogs',        'debit',  'acc-5000','NPR', 1),
('acc-5150', '5150', 'COGS - Other',          'expense',   'cogs',        'debit',  'acc-5000','NPR', 1),
('acc-6000', '6000', 'Operating Expenses',    'expense',   'other',       'debit',  NULL,      'NPR', 1),
('acc-6100', '6100', 'Utilities',             'expense',   'other',       'debit',  'acc-6000','NPR', 1),
('acc-6110', '6110', 'Rent',                  'expense',   'other',       'debit',  'acc-6000','NPR', 1),
('acc-6120', '6120', 'Salaries',              'expense',   'other',       'debit',  'acc-6000','NPR', 1),
('acc-6130', '6130', 'Transport',             'expense',   'other',       'debit',  'acc-6000','NPR', 1),
('acc-6140', '6140', 'Maintenance',           'expense',   'other',       'debit',  'acc-6000','NPR', 1),
('acc-6150', '6150', 'Marketing',             'expense',   'other',       'debit',  'acc-6000','NPR', 1),
('acc-6160', '6160', 'Miscellaneous',         'expense',   'other',       'debit',  'acc-6000','NPR', 1);

-- Users
INSERT INTO users (id, username, full_name, email, password_hash, role, is_active) VALUES
('usr-admin-001', 'admin', 'System Admin', 'admin@mnsliquors.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);

-- Reference Codes
INSERT INTO reference_codes (id, type, name, code, value, is_active) VALUES 
-- Tax Codes
('tax-001', 'tax_code', 'VAT 13%', 'VAT13', 13.00, 1),
('tax-002', 'tax_code', 'Non-Taxable', 'NON', 0.00, 1),
-- Currencies
('cur-001', 'currency', 'Nepalese Rupee', 'NPR', 1.00, 1),
('cur-002', 'currency', 'US Dollar', 'USD', 132.50, 1),
-- Payment Methods
('pm-001', 'payment_method', 'Cash', 'CASH', 0.00, 1),
('pm-002', 'payment_method', 'Bank Transfer', 'BANK', 0.00, 1),
('pm-003', 'payment_method', 'Cheque', 'CHQ', 0.00, 1),
('pm-004', 'payment_method', 'eSewa', 'ESEWA', 0.00, 1),
-- Units (type=units for item linking)
('u-001', 'units', 'Bottle', 'BTL', 0.00, 1),
('u-002', 'units', 'Case', 'CASE', 0.00, 1),
('u-003', 'units', 'Piece', 'PC', 0.00, 1),
('u-004', 'units', 'Can', 'CAN', 0.00, 1),
-- Status
('st-001', 'status', 'Active', 'ACT', 0.00, 1),
('st-002', 'status', 'Inactive', 'INACT', 0.00, 1),
-- Categories (type=category for item linking)
('cat-001', 'category', 'Spirits', 'SPIR', 0.00, 1),
('cat-002', 'category', 'Beer', 'BEER', 0.00, 1),
('cat-003', 'category', 'Wine', 'WINE', 0.00, 1),
('cat-004', 'category', 'RTD', 'RTD', 0.00, 1),
('cat-005', 'category', 'Tobacco', 'TOB', 0.00, 1),
('cat-006', 'category', 'Soft Drinks', 'SOFT', 0.00, 1),
('cat-007', 'category', 'Other', 'OTH', 0.00, 1);

-- ============================================================
-- 4. MOCK DATA (Items, Customers, Vendors)
-- ============================================================

-- Items (item_category and unit_type store reference_codes UUIDs; use IDs defined above)
INSERT IGNORE INTO items (id, sku, item_name, item_category, brand, bottle_size_ml, unit_type, units_per_case, cost_price, selling_price, tax_rate, tax_id, status_id, reorder_level, reorder_qty, cogs_account_id, income_account_id, inventory_account_id, is_active) VALUES
('item-1001', 'JD-001', 'Jack Daniels Old No.7', 'cat-001', 'Jack Daniels', 750.00, 'u-001', 12, 3500.00, 5000.00, 13.00, 'tax-001', 'st-001', 10, 24, 'acc-5100', 'acc-4100', 'acc-1200', 1),
('item-1002', 'CB-001', 'Carlsberg Premium',      'cat-002', 'Carlsberg',    650.00, 'u-001', 12, 250.00,  350.00,  13.00, 'tax-001', 'st-001', 50, 100,'acc-5110', 'acc-4110', 'acc-1200', 1),
('item-1003', 'JC-001', 'Jacob''s Creek Shiraz',  'cat-003', 'Jacob''s Creek',750.00,'u-001', 6,  1500.00, 2200.00, 13.00, 'tax-001', 'st-001', 15, 30, 'acc-5120', 'acc-4120', 'acc-1200', 1),
('item-1004', 'BK-001', 'Breezer Cranberry',      'cat-004', 'Breezer',      275.00, 'u-004', 24, 180.00,  250.00,  13.00, 'tax-001', 'st-001', 40, 96, 'acc-5130', 'acc-4130', 'acc-1200', 1),
('item-1005', 'SX-001', 'Surya Surya Classic',    'cat-005', 'Surya',        0.00,   'u-002', 1,  200.00,  250.00,  13.00, 'tax-001', 'st-001', 50, 100,'acc-5140', 'acc-4140', 'acc-1200', 1);

-- Vendors
INSERT IGNORE INTO vendors (id, vendor_code, company_name, contact_name, phone, email, address, pan_number, payable_account_id, payment_terms_days, credit_limit, is_active) VALUES
('vendor-101', 'V-001', 'Global Spirits Distributors', 'Rajesh Sharma', '9841000001', 'info@globalspirits.com', 'Lazimpat, Kathmandu', '300000001', 'acc-2100', 30, 500000.00, 1),
('vendor-102', 'V-002', 'Himalayan Breweries', 'Sita Thapa', '9841000002', 'sales@himalayanbrew.com', 'Pokhara, Nepal', '300000002', 'acc-2100', 15, 200000.00, 1),
('vendor-103', 'V-003', 'Everest Impex', 'Ram Kumar', '9841000003', 'contact@everestimpex.com', 'Birgunj, Nepal', '300000003', 'acc-2100', 45, 1000000.00, 1);

-- Customers
INSERT IGNORE INTO customers (id, customer_code, full_name, customer_type, phone, email, pan_number, receivable_account_id, credit_limit, payment_terms_days, is_active) VALUES
('cust-101', 'C-001', 'Yeti Lounge Bar', 'bar', '9851000001', 'accounts@yetilounge.com', '600000001', 'acc-1100', 100000.00, 15, 1),
('cust-102', 'C-002', 'Everest View Hotel', 'hotel', '9851000002', 'purchase@everestview.com', '600000002', 'acc-1100', 300000.00, 30, 1),
('cust-103', 'C-003', 'Shyam Bahadur (Retail)', 'retail', '9851000003', 'shyam@gmail.com', NULL, 'acc-1100', 0.00, 0, 1),
('cust-104', 'C-004', 'Kathmandu Wholesale Mart', 'wholesale', '9851000004', 'mart@ktmwholesale.com', '600000004', 'acc-1100', 500000.00, 30, 1);

-- Additional Users
INSERT IGNORE INTO users (id, username, full_name, email, password_hash, role, is_active) VALUES
('usr-mgr-001', 'john_mgr', 'John Doe', 'john.mgr@mnsliquors.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 1),
('usr-csh-001', 'sarah_csh', 'Sarah Shrestha', 'sarah.csh@mnsliquors.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 1),
('usr-acc-001', 'hari_acc', 'Hari Prasad', 'hari.acc@mnsliquors.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'accountant', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 5. STORED PROCEDURES
-- ============================================================
DROP PROCEDURE IF EXISTS sp_sync_gl_accounts;

DELIMITER //

CREATE PROCEDURE sp_sync_gl_accounts()
BEGIN
    -- 1. Sync Receivable account for Invoices
    UPDATE journal_entries je
    JOIN transaction_headers th ON je.header_id = th.id
    JOIN customer_invoices ci ON ci.header_id = th.id
    JOIN customers c ON ci.customer_id = c.id
    SET je.account_id = COALESCE(
        NULLIF(c.receivable_account_id, ''),
        (SELECT meta_value FROM system_info WHERE meta_field = 'default_ar_account')
    )
    WHERE th.txn_type = 'customer_invoice'
      AND je.entry_type = 'debit'
      AND (je.item_id IS NULL OR je.item_id = '')
      AND je.memo LIKE 'Invoice%';

    -- 2. Sync Payable account for Bills
    UPDATE journal_entries je
    JOIN transaction_headers th ON je.header_id = th.id
    JOIN vendor_bills vb ON vb.header_id = th.id
    JOIN vendors v ON vb.vendor_id = v.id
    SET je.account_id = COALESCE(
        NULLIF(v.payable_account_id, ''),
        (SELECT meta_value FROM system_info WHERE meta_field = 'default_ap_account')
    )
    WHERE th.txn_type = 'vendor_bill'
      AND je.entry_type = 'credit'
      AND (je.item_id IS NULL OR je.item_id = '')
      AND je.memo LIKE 'Bill%';

    -- 3. Sync Item Accounts for Invoices (Sales, COGS, Inventory Out)
    -- 3a. Sales Revenue (credit, memo LIKE 'Invoice%')
    UPDATE journal_entries je
    JOIN transaction_headers th ON je.header_id = th.id
    JOIN items i ON je.item_id = i.id
    SET je.account_id = COALESCE(
        NULLIF(i.income_account_id, ''),
        (SELECT meta_value FROM system_info WHERE meta_field = 'default_income_account')
    )
    WHERE th.txn_type = 'customer_invoice'
      AND je.entry_type = 'credit'
      AND je.item_id IS NOT NULL
      AND je.memo LIKE 'Invoice%';

    -- 3b. Inventory Out (credit, memo LIKE 'Inventory Out%')
    UPDATE journal_entries je
    JOIN transaction_headers th ON je.header_id = th.id
    JOIN items i ON je.item_id = i.id
    SET je.account_id = COALESCE(
        NULLIF(i.inventory_account_id, ''),
        (SELECT meta_value FROM system_info WHERE meta_field = 'default_asset_account')
    )
    WHERE th.txn_type = 'customer_invoice'
      AND je.entry_type = 'credit'
      AND je.item_id IS NOT NULL
      AND je.memo LIKE 'Inventory Out%';

    -- 3c. COGS (debit, memo LIKE 'COGS%')
    UPDATE journal_entries je
    JOIN transaction_headers th ON je.header_id = th.id
    JOIN items i ON je.item_id = i.id
    SET je.account_id = COALESCE(
        NULLIF(i.cogs_account_id, ''),
        (SELECT meta_value FROM system_info WHERE meta_field = 'default_cogs_account')
    )
    WHERE th.txn_type = 'customer_invoice'
      AND je.entry_type = 'debit'
      AND je.item_id IS NOT NULL
      AND je.memo LIKE 'COGS%';

    -- 4. Sync Item Accounts for Bills
    -- 4a. Inventory In (debit, memo LIKE 'Bill%')
    UPDATE journal_entries je
    JOIN transaction_headers th ON je.header_id = th.id
    JOIN items i ON je.item_id = i.id
    SET je.account_id = COALESCE(
        NULLIF(i.inventory_account_id, ''),
        (SELECT meta_value FROM system_info WHERE meta_field = 'default_asset_account')
    )
    WHERE th.txn_type = 'vendor_bill'
      AND je.entry_type = 'debit'
      AND je.item_id IS NOT NULL
      AND je.memo LIKE 'Bill%';

    -- 5. Sync Receivable account for Payments (Customer payments)
    UPDATE journal_entries je
    JOIN transaction_headers th ON je.header_id = th.id
    JOIN payments p ON p.header_id = th.id
    JOIN customers c ON p.customer_id = c.id
    SET je.account_id = COALESCE(
        NULLIF(c.receivable_account_id, ''),
        (SELECT meta_value FROM system_info WHERE meta_field = 'default_ar_account')
    )
    WHERE th.txn_type = 'customer_payment'
      AND je.entry_type = 'credit'
      AND (je.item_id IS NULL OR je.item_id = '')
      AND je.memo LIKE 'Payment%';

    -- 6. Sync Payable account for Payments (Vendor payments)
    UPDATE journal_entries je
    JOIN transaction_headers th ON je.header_id = th.id
    JOIN payments p ON p.header_id = th.id
    JOIN vendors v ON p.vendor_id = v.id
    SET je.account_id = COALESCE(
        NULLIF(v.payable_account_id, ''),
        (SELECT meta_value FROM system_info WHERE meta_field = 'default_ap_account')
    )
    WHERE th.txn_type = 'vendor_payment'
      AND je.entry_type = 'debit'
      AND (je.item_id IS NULL OR je.item_id = '')
      AND je.memo LIKE 'Payment%';
END //

DELIMITER ;

