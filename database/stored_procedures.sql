-- ============================================================
-- Consolidated Stored Procedures for Anti-Gravity Liquor ERP
-- Generated: 2026-07-19 11:31:33
-- ============================================================

-- ------------------------------------------------------------
-- Procedure: sp_get_outstanding_bills
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `sp_get_outstanding_bills`;

DELIMITER //

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_get_outstanding_bills`(
    IN p_type INT, 
    IN p_party_id INT, 
    IN p_exclude_code VARCHAR(100)
)
BEGIN
    DECLARE bill_type VARCHAR(20);
    SET bill_type = IF(p_type = 1, 'sale', 'purchase');

    SELECT 
        t.id, 
        t.reference_code as code, 
        t.transaction_date as date_created, 
        t.total_amount as amount,
        (t.total_amount - (
            COALESCE((SELECT SUM(p.total_amount) 
             FROM transactions p 
             WHERE p.parent_id = t.id 
             AND p.type = 'payment'
             AND (p_exclude_code IS NULL OR p.reference_code != p_exclude_code)
            ), 0) +
            COALESCE((SELECT SUM(r.total_amount) 
             FROM transactions r 
             WHERE r.parent_id = t.id 
             AND r.type = 'return'
            ), 0)
        )) as outstanding
    FROM transactions t
    WHERE t.type = bill_type
    AND t.entity_id = p_party_id
    HAVING outstanding > 0.01;
END //

DELIMITER ;

-- ------------------------------------------------------------
-- Procedure: sp_sync_gl_accounts
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `sp_sync_gl_accounts`;

DELIMITER //

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_sync_gl_accounts`()
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

-- ------------------------------------------------------------
-- Procedure: sp_PostGLTransaction
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `sp_PostGLTransaction`;

DELIMITER //

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_PostGLTransaction`(
    IN p_source_module VARCHAR(30),
    IN p_source_document_id VARCHAR(36),
    IN p_user_id VARCHAR(36),
    IN p_company_id VARCHAR(36),
    IN p_branch_id VARCHAR(36)
)
posting_block: BEGIN
    -- Declare local variables for headers
    DECLARE v_journal_id VARCHAR(36);
    DECLARE v_journal_number VARCHAR(30);
    DECLARE v_txn_date DATE;
    DECLARE v_doc_number VARCHAR(30);
    DECLARE v_status VARCHAR(20);
    DECLARE v_memo TEXT;
    DECLARE v_party_id VARCHAR(36);
    DECLARE v_currency CHAR(3);
    DECLARE v_exchange_rate DECIMAL(10,4) DEFAULT 1.0000;
    
    -- Fiscal Year variables
    DECLARE v_fiscal_year INT;
    DECLARE v_fiscal_month INT;
    DECLARE v_fiscal_period CHAR(7);
    DECLARE v_is_closed INT DEFAULT 0;

    -- Financial amounts
    DECLARE v_subtotal DECIMAL(14,2) DEFAULT 0.00;
    DECLARE v_tax_amount DECIMAL(14,2) DEFAULT 0.00;
    DECLARE v_discount_amount DECIMAL(14,2) DEFAULT 0.00;
    DECLARE v_total_amount DECIMAL(14,2) DEFAULT 0.00;
    
    -- Helper/Cursor variables
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_line_id VARCHAR(36);
    DECLARE v_item_id VARCHAR(36);
    DECLARE v_account_id VARCHAR(36);
    DECLARE v_qty DECIMAL(12,4);
    DECLARE v_unit_price DECIMAL(12,2);
    DECLARE v_line_tax DECIMAL(14,2);
    DECLARE v_line_total DECIMAL(14,2);
    DECLARE v_line_cost DECIMAL(12,2);
    DECLARE v_line_desc VARCHAR(255);
    
    -- Account mappings
    DECLARE v_ap_account VARCHAR(36);
    DECLARE v_ar_account VARCHAR(36);
    DECLARE v_tax_account VARCHAR(36);
    DECLARE v_discount_account VARCHAR(36);
    DECLARE v_cogs_account VARCHAR(36);
    
    -- Verification totals
    DECLARE v_sum_debit DECIMAL(14,2) DEFAULT 0.00;
    DECLARE v_sum_credit DECIMAL(14,2) DEFAULT 0.00;

    -- Declare Cursors
    -- Cursor for vendor bills and customer invoices lines
    DECLARE cur_txn_lines CURSOR FOR 
        SELECT id, item_id, account_id, quantity, unit_price, tax_amount, line_total, cost_price, description
        FROM transaction_lines 
        WHERE header_id = p_source_document_id;

    -- Cursor for payments lines
    DECLARE cur_payments CURSOR FOR
        SELECT id, bank_account_id, amount, cheque_number, transaction_reference, payment_date
        FROM payments
        WHERE header_id = p_source_document_id;
        
    -- Declare handlers
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    -- ------------------------------------------------------------
    -- A. Retrieve Transaction Header & Verify Status
    -- ------------------------------------------------------------
    SELECT txn_date, txn_number, status, memo, party_id, net_amount
    INTO v_txn_date, v_doc_number, v_status, v_memo, v_party_id, v_total_amount
    FROM transaction_headers
    WHERE id = p_source_document_id AND txn_type = p_source_module AND is_deleted = 0;

    IF v_doc_number IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Source transaction not found or has been deleted.';
    END IF;

    -- Rule: Ignore Draft and Voided/Cancelled transactions for GL Posting
    IF v_status = 'draft' THEN
        -- Silent exit for drafts
        LEAVE posting_block;
    END IF;

    IF v_status = 'voided' THEN
        -- If transaction is voided, handle reversal if journal already exists, then exit
        IF EXISTS(SELECT 1 FROM gl_journal_headers WHERE source_document_id = p_source_document_id) THEN
            UPDATE gl_journal_headers SET status = 'voided' WHERE source_document_id = p_source_document_id;
            UPDATE gl_journal_lines jl
            JOIN gl_journal_headers jh ON jl.journal_id = jh.journal_id
            SET jl.debit = 0.00, jl.credit = 0.00
            WHERE jh.source_document_id = p_source_document_id;
        END IF;
        LEAVE posting_block;
    END IF;

    -- ------------------------------------------------------------
    -- B. Validate Fiscal Period Lock
    -- ------------------------------------------------------------
    SET v_fiscal_year = YEAR(v_txn_date);
    SET v_fiscal_month = MONTH(v_txn_date);
    SET v_fiscal_period = DATE_FORMAT(v_txn_date, '%Y-%m');

    SELECT COUNT(*) INTO v_is_closed
    FROM fiscal_years 
    WHERE v_txn_date BETWEEN start_date AND end_date AND status = 'closed';

    IF v_is_closed > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posting failed: Transaction date falls within a closed fiscal year.';
    END IF;

    -- ------------------------------------------------------------
    -- C. Reversal/Cleanup of Prior Postings (For Reposting support)
    -- ------------------------------------------------------------
    -- If a journal entry already exists, delete its lines & header to prepare for recreation
    IF EXISTS(SELECT 1 FROM gl_journal_headers WHERE source_document_id = p_source_document_id) THEN
        DELETE FROM gl_journal_headers WHERE source_document_id = p_source_document_id;
    END IF;

    -- ------------------------------------------------------------
    -- D. Generate New Journal Headers & Default Mappings
    -- ------------------------------------------------------------
    SET v_journal_id = UUID();
    SET v_journal_number = CONCAT('JV-', DATE_FORMAT(v_txn_date, '%y%m%d'), '-', SUBSTRING(v_journal_id, 1, 6));

    -- Get System Default Accounts (with LIMIT 1 to prevent duplication errors)
    SELECT meta_value INTO v_tax_account FROM system_info WHERE meta_field = 'default_tax_account' LIMIT 1;
    SELECT meta_value INTO v_discount_account FROM system_info WHERE meta_field = 'default_discount_account' LIMIT 1;
    
    IF v_tax_account IS NULL THEN SET v_tax_account = 'acc-2200'; END IF;
    IF v_discount_account IS NULL THEN SET v_discount_account = 'acc-6160'; END IF;

    -- Write header record first (values will be updated at completion)
    INSERT INTO gl_journal_headers (
        journal_id, journal_number, posting_date, document_date, fiscal_year, accounting_period,
        source_module, source_document_id, document_number, company_id, branch_id, status, currency, exchange_rate, posted_by
    ) VALUES (
        v_journal_id, v_journal_number, CURRENT_DATE(), v_txn_date, v_fiscal_year, v_fiscal_period,
        p_source_module, p_source_document_id, v_doc_number, p_company_id, p_branch_id, 'posted', 'NPR', 1.0000, p_user_id
    );

    -- ------------------------------------------------------------
    -- E. Core Posting Logic by Transaction Type
    -- ------------------------------------------------------------
    
    -- ---------------------------------------
    -- TYPE 1: Vendor Bill
    -- ---------------------------------------
    IF p_source_module = 'vendor_bill' THEN
        -- Fetch bill summary values
        SELECT subtotal, discount_amount, tax_amount, total_amount, payable_account_id
        INTO v_subtotal, v_discount_amount, v_tax_amount, v_total_amount, v_ap_account
        FROM vendor_bills vb
        JOIN vendors v ON vb.vendor_id = v.id
        WHERE vb.header_id = p_source_document_id;

        IF v_ap_account IS NULL THEN
            SELECT meta_value INTO v_ap_account FROM system_info WHERE meta_field = 'default_ap_account' LIMIT 1;
        END IF;
        IF v_ap_account IS NULL THEN SET v_ap_account = 'acc-2100'; END IF;

        -- Process Lines
        OPEN cur_txn_lines;
        SET v_done = 0;
        
        bill_line_loop: LOOP
            FETCH cur_txn_lines INTO v_line_id, v_item_id, v_account_id, v_qty, v_unit_price, v_line_tax, v_line_total, v_line_cost, v_line_desc;
            IF v_done THEN
                LEAVE bill_line_loop;
            END IF;

            -- Determine account: If item exists, Dr Inventory Asset; else Dr specific expense account
            IF v_item_id IS NOT NULL THEN
                SELECT inventory_account_id INTO v_account_id FROM items WHERE id = v_item_id;
                IF v_account_id IS NULL THEN
                    SELECT meta_value INTO v_account_id FROM system_info WHERE meta_field = 'default_asset_account' LIMIT 1;
                END IF;
                IF v_account_id IS NULL THEN SET v_account_id = 'acc-1200'; END IF;
            END IF;

            -- GL: Dr Inventory Asset or Expense
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
            VALUES (UUID(), v_journal_id, v_account_id, (v_qty * v_unit_price), 0.00, COALESCE(v_line_desc, CONCAT('Inventory purchase - ', v_doc_number)), v_line_id);
            
            SET v_sum_debit = v_sum_debit + (v_qty * v_unit_price);
        END LOOP bill_line_loop;
        CLOSE cur_txn_lines;

        -- GL: Dr VAT Input Tax (if tax exists)
        IF v_tax_amount > 0 THEN
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
            VALUES (UUID(), v_journal_id, v_tax_account, v_tax_amount, 0.00, CONCAT('Purchase VAT - ', v_doc_number));
            SET v_sum_debit = v_sum_debit + v_tax_amount;
        END IF;

        -- GL: Cr Purchase Discount (if discount exists)
        IF v_discount_amount > 0 THEN
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
            VALUES (UUID(), v_journal_id, v_discount_account, 0.00, v_discount_amount, CONCAT('Purchase Discount - ', v_doc_number));
            SET v_sum_credit = v_sum_credit + v_discount_amount;
        END IF;

        -- GL: Cr Accounts Payable (for net total of bill)
        IF v_total_amount > 0 THEN
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
            VALUES (UUID(), v_journal_id, v_ap_account, 0.00, v_total_amount, CONCAT('Payable liability - ', v_doc_number));
            SET v_sum_credit = v_sum_credit + v_total_amount;
        END IF;

    -- ---------------------------------------
    -- TYPE 2: Vendor Payment
    -- ---------------------------------------
    ELSEIF p_source_module = 'vendor_payment' THEN
        -- Fetch payment party details
        SELECT payable_account_id INTO v_ap_account
        FROM transaction_headers th
        JOIN vendors v ON th.party_id = v.id
        WHERE th.id = p_source_document_id;

        IF v_ap_account IS NULL THEN
            SELECT meta_value INTO v_ap_account FROM system_info WHERE meta_field = 'default_ap_account' LIMIT 1;
        END IF;
        IF v_ap_account IS NULL THEN SET v_ap_account = 'acc-2100'; END IF;

        OPEN cur_payments;
        SET v_done = 0;
        
        pay_line_loop: LOOP
            FETCH cur_payments INTO v_line_id, v_account_id, v_total_amount, v_line_desc, v_line_cost, v_txn_date;
            IF v_done THEN
                LEAVE pay_line_loop;
            END IF;

            -- GL: Dr Accounts Payable
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
            VALUES (UUID(), v_journal_id, v_ap_account, v_total_amount, 0.00, CONCAT('Vendor Payment - Ref: ', COALESCE(v_line_desc, '')), v_line_id);
            
            -- GL: Cr Cash/Bank Account
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
            VALUES (UUID(), v_journal_id, v_account_id, 0.00, v_total_amount, CONCAT('Bank Cash dispersion - Ref: ', COALESCE(v_line_desc, '')), v_line_id);

            SET v_sum_debit = v_sum_debit + v_total_amount;
            SET v_sum_credit = v_sum_credit + v_total_amount;
        END LOOP pay_line_loop;
        CLOSE cur_payments;

    -- ---------------------------------------
    -- TYPE 3: Customer Invoice
    -- ---------------------------------------
    ELSEIF p_source_module = 'customer_invoice' THEN
        -- Fetch invoice summary values
        SELECT subtotal, discount_amount, tax_amount, total_amount, receivable_account_id
        INTO v_subtotal, v_discount_amount, v_tax_amount, v_total_amount, v_ar_account
        FROM customer_invoices ci
        JOIN customers c ON ci.customer_id = c.id
        WHERE ci.header_id = p_source_document_id;

        IF v_ar_account IS NULL THEN
            SELECT meta_value INTO v_ar_account FROM system_info WHERE meta_field = 'default_ar_account' LIMIT 1;
        END IF;
        IF v_ar_account IS NULL THEN SET v_ar_account = 'acc-1100'; END IF;

        -- GL: Dr Accounts Receivable (for grand total invoice amount)
        IF v_total_amount > 0 THEN
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
            VALUES (UUID(), v_journal_id, v_ar_account, v_total_amount, 0.00, CONCAT('Receivable Asset - ', v_doc_number));
            SET v_sum_debit = v_sum_debit + v_total_amount;
        END IF;

        -- GL: Dr Discounts Given (if discount exists)
        IF v_discount_amount > 0 THEN
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
            VALUES (UUID(), v_journal_id, v_discount_account, v_discount_amount, 0.00, CONCAT('Invoice discount - ', v_doc_number));
            SET v_sum_debit = v_sum_debit + v_discount_amount;
        END IF;

        -- GL: Cr Sales Revenue (split per sales account configuration for item lines)
        OPEN cur_txn_lines;
        SET v_done = 0;
        
        inv_line_loop: LOOP
            FETCH cur_txn_lines INTO v_line_id, v_item_id, v_account_id, v_qty, v_unit_price, v_line_tax, v_line_total, v_line_cost, v_line_desc;
            IF v_done THEN
                LEAVE inv_line_loop;
            END IF;

            -- Resolve income account for item sales
            IF v_item_id IS NOT NULL THEN
                SELECT income_account_id INTO v_account_id FROM items WHERE id = v_item_id;
                IF v_account_id IS NULL THEN
                    SELECT meta_value INTO v_account_id FROM system_info WHERE meta_field = 'default_income_account' LIMIT 1;
                END IF;
                IF v_account_id IS NULL THEN SET v_account_id = 'acc-4100'; END IF;
            END IF;

            -- GL: Cr Sales Revenue
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
            VALUES (UUID(), v_journal_id, v_account_id, 0.00, (v_qty * v_unit_price), COALESCE(v_line_desc, CONCAT('Sales Revenue - ', v_doc_number)), v_line_id);
            
            SET v_sum_credit = v_sum_credit + (v_qty * v_unit_price);

            -- ------------------------------------------------------------
            -- Inventory Cost Entry (Perpetual Inventory System)
            -- ------------------------------------------------------------
            -- Create inventory release and cost entries for physical items only
            IF v_item_id IS NOT NULL THEN
                -- Resolve items accounts
                SELECT cogs_account_id, inventory_account_id, cost_price 
                INTO v_cogs_account, v_account_id, v_line_cost
                FROM items WHERE id = v_item_id;
                
                IF v_cogs_account IS NULL THEN
                    SELECT meta_value INTO v_cogs_account FROM system_info WHERE meta_field = 'default_cogs_account' LIMIT 1;
                END IF;
                IF v_cogs_account IS NULL THEN SET v_cogs_account = 'acc-5100'; END IF;

                IF v_account_id IS NULL THEN
                    SELECT meta_value INTO v_account_id FROM system_info WHERE meta_field = 'default_asset_account' LIMIT 1;
                END IF;
                IF v_account_id IS NULL THEN SET v_account_id = 'acc-1200'; END IF;

                -- Dr COGS / Cr Inventory Asset (based on item standard/moving average cost)
                IF (v_qty * v_line_cost) > 0 THEN
                    INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
                    VALUES (UUID(), v_journal_id, v_cogs_account, (v_qty * v_line_cost), 0.00, CONCAT('COGS - Item release - ', v_doc_number), v_line_id);

                    INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
                    VALUES (UUID(), v_journal_id, v_account_id, 0.00, (v_qty * v_line_cost), CONCAT('Inventory Release - ', v_doc_number), v_line_id);
                    
                    -- Balance checking internally registers this separate parallel entry
                    SET v_sum_debit = v_sum_debit + (v_qty * v_line_cost);
                    SET v_sum_credit = v_sum_credit + (v_qty * v_line_cost);
                END IF;
            END IF;

        END LOOP inv_line_loop;
        CLOSE cur_txn_lines;

        -- GL: Cr VAT Output Tax (if tax exists)
        IF v_tax_amount > 0 THEN
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
            VALUES (UUID(), v_journal_id, v_tax_account, 0.00, v_tax_amount, CONCAT('Sales Output VAT - ', v_doc_number));
            SET v_sum_credit = v_sum_credit + v_tax_amount;
        END IF;

    -- ---------------------------------------
    -- TYPE 4: Expense Entry
    -- ---------------------------------------
    ELSEIF p_source_module = 'expense' THEN
        -- Fetch expense details
        SELECT expense_account_id, paid_from_account_id, amount, tax_amount, description
        INTO v_account_id, v_ap_account, v_subtotal, v_tax_amount, v_line_desc
        FROM expenses
        WHERE header_id = p_source_document_id;

        -- GL: Dr Expense Account
        INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
        VALUES (UUID(), v_journal_id, v_account_id, v_subtotal, 0.00, COALESCE(v_line_desc, 'Expense entry'));
        SET v_sum_debit = v_sum_debit + v_subtotal;

        -- GL: Dr Input Tax (if tax exists)
        IF v_tax_amount > 0 THEN
            INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
            VALUES (UUID(), v_journal_id, v_tax_account, v_tax_amount, 0.00, CONCAT('Expense VAT - ', v_doc_number));
            SET v_sum_debit = v_sum_debit + v_tax_amount;
        END IF;

        -- GL: Cr Cash/Bank or Accounts Payable (total amount inclusive of tax)
        INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description)
        VALUES (UUID(), v_journal_id, v_ap_account, 0.00, (v_subtotal + v_tax_amount), CONCAT('Expense offset - ', v_doc_number));
        SET v_sum_credit = v_sum_credit + (v_subtotal + v_tax_amount);

    -- ---------------------------------------
    -- TYPE 5: Inventory Adjustment
    -- ---------------------------------------
    ELSEIF p_source_module = 'inventory_adjustment' THEN
        -- Get system offset accounts for stock adjustment gains/losses
        SELECT meta_value INTO v_cogs_account FROM system_info WHERE meta_field = 'default_cogs_account' LIMIT 1;
        IF v_cogs_account IS NULL THEN SET v_cogs_account = 'acc-5100'; END IF; -- default expense/COGS fallback

        OPEN cur_txn_lines;
        SET v_done = 0;
        
        adj_line_loop: LOOP
            FETCH cur_txn_lines INTO v_line_id, v_item_id, v_account_id, v_qty, v_unit_price, v_line_tax, v_line_total, v_line_cost, v_line_desc;
            IF v_done THEN
                LEAVE adj_line_loop;
            END IF;

            -- Resolve inventory asset account
            SELECT inventory_account_id INTO v_account_id FROM items WHERE id = v_item_id;
            IF v_account_id IS NULL THEN SET v_account_id = 'acc-1200'; END IF;

            -- Calculate total value of adjustment
            SET v_line_total = v_qty * v_unit_price;

            -- Positive Adjustment: Dr Inventory Asset, Cr Adjustment Gain
            IF v_qty > 0 THEN
                INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
                VALUES (UUID(), v_journal_id, v_account_id, v_line_total, 0.00, CONCAT('Stock positive adjustment - ', v_doc_number), v_line_id);

                INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
                VALUES (UUID(), v_journal_id, v_cogs_account, 0.00, v_line_total, CONCAT('Stock adjustment gain - ', v_doc_number), v_line_id);

                SET v_sum_debit = v_sum_debit + v_line_total;
                SET v_sum_credit = v_sum_credit + v_line_total;
                
            -- Negative Adjustment: Dr Adjustment Loss (Expense), Cr Inventory Asset
            ELSEIF v_qty < 0 THEN
                SET v_line_total = ABS(v_line_total);
                
                INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
                VALUES (UUID(), v_journal_id, v_cogs_account, v_line_total, 0.00, CONCAT('Stock adjustment loss - ', v_doc_number), v_line_id);

                INSERT INTO gl_journal_lines (journal_line_id, journal_id, account_id, debit, credit, description, source_line_id)
                VALUES (UUID(), v_journal_id, v_account_id, 0.00, v_line_total, CONCAT('Stock negative adjustment - ', v_doc_number), v_line_id);

                SET v_sum_debit = v_sum_debit + v_line_total;
                SET v_sum_credit = v_sum_credit + v_line_total;
            END IF;

        END LOOP adj_line_loop;
        CLOSE cur_txn_lines;

    END IF;

    -- ------------------------------------------------------------
    -- F. Double-Entry Accounting Validation (Balance Check)
    -- ------------------------------------------------------------
    -- Ensure total debits equal total credits within a small rounding margin
    IF ABS(v_sum_debit - v_sum_credit) > 0.001 THEN
        -- Roll back automatically via database transaction block by raising exception
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Posting failed: Trial Balance check failed. Total Debits and Total Credits are not equal.';
    END IF;

    -- ------------------------------------------------------------
    -- G. Update Journal Header with Actual Totals
    -- ------------------------------------------------------------
    UPDATE gl_journal_headers 
    SET total_debit = v_sum_debit, total_credit = v_sum_credit 
    WHERE journal_id = v_journal_id;

END //

DELIMITER ;

