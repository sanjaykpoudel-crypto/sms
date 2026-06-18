-- POS Entry System Tables

CREATE TABLE IF NOT EXISTS pos_entry (
    id VARCHAR(36) PRIMARY KEY,
    invoice_no VARCHAR(50) NOT NULL UNIQUE,
    date_time DATETIME NOT NULL,
    customer_id VARCHAR(36) DEFAULT NULL,
    gross_amount DECIMAL(14,2) NOT NULL,
    discount_type ENUM('percentage', 'fixed') DEFAULT 'fixed',
    discount_value DECIMAL(14,2) DEFAULT 0,
    discount_amount DECIMAL(14,2) DEFAULT 0,
    tax_amount DECIMAL(14,2) NOT NULL,
    net_amount DECIMAL(14,2) NOT NULL,
    status ENUM('draft', 'completed', 'returned', 'voided') DEFAULT 'completed',
    created_by VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE IF NOT EXISTS pos_items (
    id VARCHAR(36) PRIMARY KEY,
    pos_id VARCHAR(36) NOT NULL,
    item_id VARCHAR(36) NOT NULL,
    quantity DECIMAL(14,2) NOT NULL,
    rate DECIMAL(14,2) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    discount DECIMAL(14,2) DEFAULT 0,
    tax DECIMAL(14,2) DEFAULT 0,
    net_amount DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (pos_id) REFERENCES pos_entry(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);

CREATE TABLE IF NOT EXISTS pos_payments (
    id VARCHAR(36) PRIMARY KEY,
    pos_id VARCHAR(36) NOT NULL,
    payment_mode ENUM('cash', 'qr', 'card', 'bank') NOT NULL,
    account_id VARCHAR(36) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    FOREIGN KEY (pos_id) REFERENCES pos_entry(id),
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);

CREATE TABLE IF NOT EXISTS pos_returns (
    id VARCHAR(36) PRIMARY KEY,
    original_pos_id VARCHAR(36) NOT NULL,
    return_date DATE NOT NULL,
    total_return_amount DECIMAL(14,2) NOT NULL,
    refund_mode ENUM('cash', 'qr', 'credit_note') NOT NULL,
    status ENUM('completed', 'reversed') DEFAULT 'completed',
    created_by VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (original_pos_id) REFERENCES pos_entry(id)
);

-- Add some indices for performance
CREATE INDEX idx_pos_date ON pos_entry(date_time);
CREATE INDEX idx_pos_customer ON pos_entry(customer_id);
CREATE INDEX idx_pos_item ON pos_items(item_id);
CREATE INDEX idx_pos_payment_mode ON pos_payments(payment_mode);
