-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 20, 2026 at 01:15 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `sms_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_get_outstanding_bills` (IN `p_type` INT, IN `p_party_id` INT, IN `p_exclude_code` VARCHAR(100))   BEGIN
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
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_PostGLTransaction` (IN `p_source_module` VARCHAR(30), IN `p_source_document_id` VARCHAR(36), IN `p_user_id` VARCHAR(36), IN `p_company_id` VARCHAR(36), IN `p_branch_id` VARCHAR(36))   posting_block: BEGIN
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

END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_sync_gl_accounts` ()   BEGIN
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
    END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` varchar(36) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `account_type` enum('asset','liability','equity','income','expense') NOT NULL,
  `account_subtype` enum('cash','bank','receivable','payable','inventory','cogs','sales','tax','other') NOT NULL,
  `normal_balance` enum('debit','credit') NOT NULL,
  `parent_account_id` varchar(36) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'NPR',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`id`, `account_code`, `account_name`, `account_type`, `account_subtype`, `normal_balance`, `parent_account_id`, `currency`, `is_active`, `created_at`, `is_deleted`, `updated_at`, `opening_balance`, `deleted_at`) VALUES
('acc-1000', '1000', 'Assets', 'asset', 'other', 'debit', NULL, 'NPR', 0, '2026-04-29 10:39:44', 0, '2026-05-21 10:53:19', 0.00, NULL),
('acc-1010', '1010', 'Cash on Hand', 'asset', 'bank', 'debit', 'acc-1000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-07-20 08:09:03', 19118.00, NULL),
('acc-1020', '1020', 'Prabhu Bank', 'asset', 'bank', 'debit', 'acc-1000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-07-20 08:09:03', 345094.00, NULL),
('acc-1030', '1030', 'Esewa', 'asset', 'bank', 'debit', 'acc-1000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-07-19 09:14:26', 0.00, NULL),
('acc-1100', '1100', 'Accounts Receivable', 'asset', 'receivable', 'debit', 'acc-1000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-1200', '1200', 'Inventory', 'asset', 'inventory', 'debit', 'acc-1000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-07-19 07:44:59', 0.00, NULL),
('acc-1500', '1500', 'Fixed Assets (Equipment)', 'asset', 'other', 'debit', NULL, 'NPR', 1, '2026-05-04 11:01:13', 0, '2026-05-04 11:01:13', 0.00, NULL),
('acc-2000', '2000', 'Liabilities', 'liability', 'other', 'credit', NULL, 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-2100', '2100', 'Accounts Payable', 'liability', 'payable', 'credit', 'acc-2000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-2200', '2200', 'VAT Payable (13%)', 'liability', 'tax', 'credit', 'acc-2000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-2300', '2300', 'Excise Duty Payable', 'liability', 'tax', 'debit', 'acc-2000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-2400', '2400', 'Payroll Liabilities', 'liability', 'other', 'credit', NULL, 'NPR', 1, '2026-05-04 11:01:13', 0, '2026-05-04 11:01:13', 0.00, NULL),
('acc-3000', '3000', 'Equity', 'equity', 'other', 'credit', NULL, 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-3100', '3100', 'Capital Account', 'equity', 'other', 'credit', 'acc-3000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-3200', '3200', 'Retained Earnings', 'equity', 'other', 'credit', 'acc-3000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-3300', '3300', 'Income Summary', 'equity', 'other', 'credit', 'acc-3000', 'NPR', 1, '2026-07-19 08:28:10', 0, '2026-07-19 08:28:10', 0.00, NULL),
('acc-4000', '4000', 'Income', 'income', 'other', 'credit', NULL, 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-4100', '4100', 'Sales', 'income', 'sales', 'credit', 'acc-4000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-07-19 07:44:59', 0.00, NULL),
('acc-5000', '5000', 'Cost of Goods Sold', 'expense', 'cogs', 'debit', NULL, 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-5100', '5100', 'COGS', 'expense', 'cogs', 'debit', 'acc-5000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-07-19 07:44:59', 0.00, NULL),
('acc-5200', '5200', 'Inventory Shrinkage/Damage', 'expense', 'other', 'debit', NULL, 'NPR', 1, '2026-05-04 11:01:13', 0, '2026-05-04 11:01:13', 0.00, NULL),
('acc-6000', '6000', 'Operating Expenses', 'expense', 'other', 'debit', NULL, 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-6100', '6100', 'Rent Expense', 'expense', 'other', 'debit', 'acc-6000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-6110', '6110', 'Salaries & Wages', 'expense', 'other', 'debit', 'acc-6000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-6120', '6120', 'Electricity & Utilities', 'expense', 'other', 'debit', 'acc-6000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-6130', '6130', 'Marketing & Advertising', 'expense', 'other', 'debit', 'acc-6000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-6140', '6140', 'License & Permit Fees', 'expense', 'other', 'debit', 'acc-6000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-6150', '6150', 'Bank Charges & Fees', 'expense', 'other', 'debit', 'acc-6000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-6160', '6160', 'Discounts Given', 'expense', 'other', 'debit', 'acc-6000', 'NPR', 1, '2026-04-29 10:39:44', 0, '2026-04-29 10:39:44', 0.00, NULL),
('acc-6170', '6170', 'Miscellaneous Expenses', 'expense', 'other', 'debit', NULL, 'NPR', 1, '2026-05-04 11:01:13', 0, '2026-05-04 11:01:13', 0.00, NULL),
('bbe5c26b-091b-4b2c-939c-8a18220bcc5a', 'open', 'Opening Balance', 'equity', 'other', 'credit', NULL, 'NPR', 1, '2026-06-10 13:54:43', 0, '2026-06-10 13:54:43', 0.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `account_transfers`
--

CREATE TABLE `account_transfers` (
  `id` varchar(36) NOT NULL,
  `header_id` varchar(36) NOT NULL,
  `from_account_id` varchar(36) NOT NULL,
  `to_account_id` varchar(36) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `transfer_type` enum('bank_to_cash','cash_to_bank','bank_to_bank','inter_account') NOT NULL,
  `memo` varchar(255) DEFAULT NULL,
  `transfer_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `account_transfers`
--

INSERT INTO `account_transfers` (`id`, `header_id`, `from_account_id`, `to_account_id`, `amount`, `transfer_type`, `memo`, `transfer_date`) VALUES
('72b89194-3134-4b2b-a811-1181aaa03427', 'd062aea6-6bef-4a34-9ebc-79fe4aa1add9', 'acc-1030', 'acc-1020', 4465.00, 'bank_to_bank', 'automatically transfered to bank ', '2026-07-17'),
('fdf7bb62-385f-4697-802c-d083c4625f4b', 'eb86ca66-4db1-4447-807d-8891c8ba4cd3', 'acc-1010', 'acc-1020', 7100.00, 'cash_to_bank', '', '2026-07-19');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` varchar(36) NOT NULL,
  `action` enum('create','update','delete') NOT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_values`, `new_values`, `user_id`, `created_at`) VALUES
(1, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-15 16:01:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-15 14:01:50'),
(2, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-15 16:02:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-15 14:02:17'),
(3, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-15 16:04:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-15 14:04:40'),
(4, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-15 17:41:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-15 15:41:38'),
(5, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-15 17:41:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-15 15:41:55'),
(6, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-15 17:45:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-15 15:45:42'),
(7, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 13:02:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 11:02:40'),
(8, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 13:04:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 11:04:52'),
(9, 'items', 'cc197d32-be40-4c35-aee8-b553af156838', 'create', 'null', '{\"id\":\"cc197d32-be40-4c35-aee8-b553af156838\",\"sku\":\"I-00027\",\"item_name\":\"Mustang Black 180ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"180\",\"units_per_case\":\"48\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"0.00\",\"selling_price\":\"340\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"\",\"inventory_account_id\":\"acc-1210\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\"}', 'usr-admin-001', '2026-07-16 11:24:17'),
(10, 'vendors', 'bc4d29cf-78a4-4da5-b84e-f2f0922ac431', 'delete', '{\"id\":\"bc4d29cf-78a4-4da5-b84e-f2f0922ac431\",\"vendor_code\":\"V-00008\",\"company_name\":\"Suraj Store\",\"contact_name\":null,\"phone\":\"\",\"email\":null,\"address\":null,\"pan_number\":null,\"vat_number\":null,\"payable_account_id\":\"acc-2100\",\"payment_terms_days\":null,\"credit_limit\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-16 11:35:26'),
(11, 'vendors', 'ad3b13fe-8a01-461f-a46c-01bf644c1845', 'delete', '{\"id\":\"ad3b13fe-8a01-461f-a46c-01bf644c1845\",\"vendor_code\":\"V-00004\",\"company_name\":\"Bharat dai\",\"contact_name\":null,\"phone\":\"\",\"email\":null,\"address\":null,\"pan_number\":null,\"vat_number\":null,\"payable_account_id\":\"acc-2100\",\"payment_terms_days\":null,\"credit_limit\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-16 11:35:38'),
(12, 'vendors', '4e37ee48-0033-4075-95a4-c37790199f0a', 'delete', '{\"id\":\"4e37ee48-0033-4075-95a4-c37790199f0a\",\"vendor_code\":\"V-00018\",\"company_name\":\"Ajay Tamang Dew\",\"contact_name\":null,\"phone\":\"9801971871\",\"email\":null,\"address\":null,\"pan_number\":null,\"vat_number\":null,\"payable_account_id\":\"acc-2100\",\"payment_terms_days\":null,\"credit_limit\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-16 11:35:51'),
(13, 'vendors', 'ab521303-ba70-44d7-a485-45211e4a3f04', 'delete', '{\"id\":\"ab521303-ba70-44d7-a485-45211e4a3f04\",\"vendor_code\":\"V-00003\",\"company_name\":\"No Vendor\",\"contact_name\":null,\"phone\":\"\",\"email\":null,\"address\":null,\"pan_number\":null,\"vat_number\":null,\"payable_account_id\":\"acc-2100\",\"payment_terms_days\":null,\"credit_limit\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-16 11:36:03'),
(14, 'items', '58704d38-49ac-4535-a4c2-344aa9acf53b', 'create', 'null', '{\"id\":\"58704d38-49ac-4535-a4c2-344aa9acf53b\",\"sku\":\"I-00028\",\"item_name\":\"Mustang Black 750 ml\",\"item_category\":\"\",\"brand\":\"Mustang\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"750\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-16 12:08:09'),
(15, 'items', '58704d38-49ac-4535-a4c2-344aa9acf53b', 'update', '{\"id\":\"58704d38-49ac-4535-a4c2-344aa9acf53b\",\"sku\":\"I-00028\",\"item_name\":\"Mustang Black 750 ml\",\"item_category\":\"\",\"brand\":\"Mustang\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-07-16 17:53:09\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 17:53:09\"}', '{\"id\":\"58704d38-49ac-4535-a4c2-344aa9acf53b\",\"sku\":\"I-00028\",\"item_name\":\"Mustang Black 750 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"Mustang\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-16 12:08:18'),
(16, 'items', 'd20f5087-48f0-4ec3-950c-d7393884aed4', 'create', 'null', '{\"id\":\"d20f5087-48f0-4ec3-950c-d7393884aed4\",\"sku\":\"I-00029\",\"item_name\":\"Highlander 750 ml \",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"750\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-16 12:13:55'),
(17, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 14:35:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 12:35:45'),
(18, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 15:24:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 13:24:54'),
(19, 'system_navigation', 'reports/inventory/low_stock', '', NULL, '{\"page\":\"reports\\/inventory\\/low_stock\",\"accessed_at\":\"2026-07-16 15:25:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 13:25:01'),
(20, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 15:37:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 13:37:17'),
(21, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 15:41:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 13:41:53'),
(22, 'items', '9e2df543-8630-4d81-b273-1cd77a32ae65', 'update', '{\"id\":\"9e2df543-8630-4d81-b273-1cd77a32ae65\",\"sku\":\"I-00012\",\"item_name\":\"OD 750 ml\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":null,\"barcode\":null,\"bottle_size_ml\":null,\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":null,\"units_per_case\":null,\"cost_price\":\"2675.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"reorder_level\":null,\"reorder_qty\":null,\"current_stock\":\"4.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 18:19:45\"}', '{\"id\":\"9e2df543-8630-4d81-b273-1cd77a32ae65\",\"sku\":\"I-00012\",\"item_name\":\"OD 750 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"750\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"2675.00\",\"selling_price\":\"2850\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-16 14:04:55'),
(23, 'items', '58704d38-49ac-4535-a4c2-344aa9acf53b', 'update', '{\"id\":\"58704d38-49ac-4535-a4c2-344aa9acf53b\",\"sku\":\"I-00028\",\"item_name\":\"Mustang Black 750 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"Mustang\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"1200.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"3.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-07-16 17:53:09\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 18:19:45\"}', '{\"id\":\"58704d38-49ac-4535-a4c2-344aa9acf53b\",\"sku\":\"I-00028\",\"item_name\":\"Mustang Black 750 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"Mustang\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"1200.00\",\"selling_price\":\"1400\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-16 14:05:16'),
(24, 'items', 'cc197d32-be40-4c35-aee8-b553af156838', 'update', '{\"id\":\"cc197d32-be40-4c35-aee8-b553af156838\",\"sku\":\"I-00027\",\"item_name\":\"Mustang Black 180ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"180.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":48,\"cost_price\":\"300.00\",\"selling_price\":\"340.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"12.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1210\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-07-16 17:09:17\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 18:19:45\"}', '{\"id\":\"cc197d32-be40-4c35-aee8-b553af156838\",\"sku\":\"I-00027\",\"item_name\":\"Mustang Black 180ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"180.00\",\"units_per_case\":\"48\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"300.00\",\"selling_price\":\"340\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1210\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\"}', 'usr-admin-001', '2026-07-16 14:05:33'),
(25, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-16 16:05:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 14:05:54'),
(26, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 16:21:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 14:21:21'),
(27, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 16:31:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 14:31:44'),
(28, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 16:52:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 14:52:36'),
(29, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-16 16:54:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 14:54:05'),
(30, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-16 16:54:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 14:54:12'),
(31, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 16:56:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 14:56:46'),
(32, 'items', 'e88436f2-0460-408d-9494-190246334d27', 'update', '{\"id\":\"e88436f2-0460-408d-9494-190246334d27\",\"sku\":\"I-00008\",\"item_name\":\"Big Master\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":null,\"barcode\":null,\"bottle_size_ml\":null,\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":null,\"units_per_case\":null,\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"reorder_level\":null,\"reorder_qty\":null,\"current_stock\":\"6.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 18:19:45\"}', '{\"id\":\"e88436f2-0460-408d-9494-190246334d27\",\"sku\":\"I-00008\",\"item_name\":\"Big Master\",\"item_category\":\"01e6a28d-9b57-437e-84f9-94a75eeb19a6\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"750\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"\",\"inventory_account_id\":\"acc-1220\",\"cogs_account_id\":\"acc-5120\",\"income_account_id\":\"acc-4120\"}', 'usr-admin-001', '2026-07-16 15:27:23'),
(33, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-16 17:30:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 15:30:17'),
(34, 'system_navigation', 'reports/inventory/urgent_buy', '', NULL, '{\"page\":\"reports\\/inventory\\/urgent_buy\",\"accessed_at\":\"2026-07-16 17:31:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 15:31:49'),
(35, 'system_navigation', 'reports/purchases/by_item', '', NULL, '{\"page\":\"reports\\/purchases\\/by_item\",\"accessed_at\":\"2026-07-16 17:32:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 15:32:10'),
(36, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-16 17:32:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 15:32:50'),
(37, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-16 17:33:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 15:33:04'),
(38, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-16 17:33:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 15:33:44'),
(39, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-16 17:33:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 15:33:58'),
(40, 'items', 'd20f5087-48f0-4ec3-950c-d7393884aed4', 'update', '{\"id\":\"d20f5087-48f0-4ec3-950c-d7393884aed4\",\"sku\":\"I-00029\",\"item_name\":\"Highlander 750 ml \",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"1034.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"3.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-07-16 17:58:55\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 21:14:46\"}', '{\"id\":\"d20f5087-48f0-4ec3-950c-d7393884aed4\",\"sku\":\"I-00029\",\"item_name\":\"Highlander 750 ml \",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"1034.00\",\"selling_price\":\"1150\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-16 15:48:42'),
(41, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-16 17:52:25\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 15:52:25'),
(42, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-16 18:37:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-16 16:37:37'),
(43, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 15:40:14\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 13:40:14'),
(44, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 15:41:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 13:41:52'),
(45, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 15:43:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 13:43:34'),
(46, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 15:43:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 13:43:34'),
(47, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 15:46:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 13:46:33'),
(48, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 15:53:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 13:53:55'),
(49, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 15:56:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 13:56:52'),
(50, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:01:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:01:37'),
(51, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:05:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:05:19'),
(52, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-17 16:05:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:05:32'),
(53, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:08:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:08:56'),
(54, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-17 16:18:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:18:48'),
(55, 'items', '3d0e5228-5462-4ae9-b843-51b352361479', 'create', 'null', '{\"id\":\"3d0e5228-5462-4ae9-b843-51b352361479\",\"sku\":\"I-00030\",\"item_name\":\"OD 350 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"350\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"0.00\",\"selling_price\":\"1450\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-17 14:26:29'),
(56, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:37:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:37:53'),
(57, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:39:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:39:41'),
(58, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:40:28\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:40:28'),
(59, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:40:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:40:35'),
(60, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:40:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:40:37'),
(61, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-17 16:50:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:50:58'),
(62, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-17 16:51:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:51:04'),
(63, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:51:51\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:51:51'),
(64, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-17 16:52:11\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:52:11'),
(65, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-17 16:52:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:52:29'),
(66, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 16:53:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:53:46'),
(67, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-17 16:56:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:56:44'),
(68, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-17 16:56:51\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:56:51'),
(69, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-17 16:58:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 14:58:34'),
(70, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-17 17:03:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:03:43'),
(71, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 17:03:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:03:57'),
(72, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-17 17:04:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:04:21'),
(73, 'items', 'a48cf127-40e9-404e-a9ea-2814657da992', 'update', '{\"id\":\"a48cf127-40e9-404e-a9ea-2814657da992\",\"sku\":\"CB-064\",\"item_name\":\"Shikher Ice\",\"item_category\":\"5581720a-90fe-4f2c-8cbb-56d1c7a3da56\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"-1726.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"13.05\",\"selling_price\":\"15.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":60,\"current_stock\":\"350.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:53:55\"}', '{\"id\":\"a48cf127-40e9-404e-a9ea-2814657da992\",\"sku\":\"CB-064\",\"item_name\":\"Shikher Ice\",\"item_category\":\"5581720a-90fe-4f2c-8cbb-56d1c7a3da56\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"0\",\"units_per_case\":\"20\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"13.05\",\"selling_price\":\"15.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"60\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5140\",\"income_account_id\":\"acc-4140\"}', 'usr-admin-001', '2026-07-17 15:09:35'),
(74, 'items', '3d0e5228-5462-4ae9-b843-51b352361479', 'update', '{\"id\":\"3d0e5228-5462-4ae9-b843-51b352361479\",\"sku\":\"I-00030\",\"item_name\":\"OD 350 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"350.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":24,\"cost_price\":\"1341.75\",\"selling_price\":\"1450.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"9.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-07-17 20:11:29\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:47:40\"}', '{\"id\":\"3d0e5228-5462-4ae9-b843-51b352361479\",\"sku\":\"I-00030\",\"item_name\":\"OD 350 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"350.00\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"1341.75\",\"selling_price\":\"1450.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"5\",\"reorder_qty\":\"5\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-17 15:09:54'),
(75, 'items', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'update', '{\"id\":\"dd1ac436-ebca-4fa2-9407-44f158256b13\",\"sku\":\"CB-031\",\"item_name\":\"Gorkha 650 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"650.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"329.17\",\"selling_price\":\"365.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":60,\"current_stock\":\"38.0000\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\",\"inventory_account_id\":\"acc-1230\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:47:40\"}', '{\"id\":\"dd1ac436-ebca-4fa2-9407-44f158256b13\",\"sku\":\"CB-031\",\"item_name\":\"Gorkha 650\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"650\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"329.17\",\"selling_price\":\"365.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"20\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:10:45'),
(76, 'items', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'update', '{\"id\":\"c1cac95d-404c-424f-8e9d-8d74198e7b9e\",\"sku\":\"I-00026\",\"item_name\":\"Nepal Ice 330 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"Nepal Ice\",\"barcode\":\"\",\"bottle_size_ml\":\"330.00\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":\"\",\"units_per_case\":24,\"cost_price\":\"156.25\",\"selling_price\":\"180.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"16.0000\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\",\"inventory_account_id\":\"acc-1230\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-06-09 15:26:37\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:38:43\"}', '{\"id\":\"c1cac95d-404c-424f-8e9d-8d74198e7b9e\",\"sku\":\"I-00026\",\"item_name\":\"Nepal Ice 330\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"Nepal Ice\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"330.00\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"156.25\",\"selling_price\":\"180.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"20\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:11:05'),
(77, 'items', '1a8166b8-3107-444c-9634-2f27df10e913', 'update', '{\"id\":\"1a8166b8-3107-444c-9634-2f27df10e913\",\"sku\":\"CB-057\",\"item_name\":\"Nepal Ice 650 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"650.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"304.17\",\"selling_price\":\"340.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":5,\"current_stock\":\"-1.0000\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\",\"inventory_account_id\":\"acc-1230\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:24:16\"}', '{\"id\":\"1a8166b8-3107-444c-9634-2f27df10e913\",\"sku\":\"CB-057\",\"item_name\":\"Nepal Ice 650\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"650\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"304.17\",\"selling_price\":\"340.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"5\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:11:28'),
(78, 'items', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'update', '{\"id\":\"17d37cfe-9fd1-4dca-bc6d-af63fa236373\",\"sku\":\"CB-048\",\"item_name\":\"Gorkha can\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"500.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"243.75\",\"selling_price\":\"275.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":12,\"current_stock\":\"24.0000\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\",\"inventory_account_id\":\"acc-1230\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 19:28:08\"}', '{\"id\":\"17d37cfe-9fd1-4dca-bc6d-af63fa236373\",\"sku\":\"CB-048\",\"item_name\":\"Gorkha can\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"500.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"243.75\",\"selling_price\":\"275.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"12\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:11:38'),
(79, 'items', '49963ff8-5d2d-4c0d-a531-f5048e644817', 'update', '{\"id\":\"49963ff8-5d2d-4c0d-a531-f5048e644817\",\"sku\":\"CB-023\",\"item_name\":\"Carlsberg 650 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"0.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"440.00\",\"selling_price\":\"480.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":24,\"current_stock\":\"9.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 17:51:59\"}', '{\"id\":\"49963ff8-5d2d-4c0d-a531-f5048e644817\",\"sku\":\"CB-023\",\"item_name\":\"Carlsberg 650 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"0.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"440.00\",\"selling_price\":\"480.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"10\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:12:00'),
(80, 'items', '919b9e31-52a2-48f9-8795-533b0a081663', 'update', '{\"id\":\"919b9e31-52a2-48f9-8795-533b0a081663\",\"sku\":\"CB-055\",\"item_name\":\"Arna Premium Eight Can\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"0.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"231.25\",\"selling_price\":\"260.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":12,\"current_stock\":\"26.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 17:51:59\"}', '{\"id\":\"919b9e31-52a2-48f9-8795-533b0a081663\",\"sku\":\"CB-055\",\"item_name\":\"Arna Eight Can\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"500\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"231.25\",\"selling_price\":\"270.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"12\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:12:37'),
(81, 'items', '9e894c3b-f0ab-40bb-8d13-17dc033754d6', 'update', '{\"id\":\"9e894c3b-f0ab-40bb-8d13-17dc033754d6\",\"sku\":\"CB-059\",\"item_name\":\"Tuborg 650 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"Tuborg\",\"barcode\":\"\",\"bottle_size_ml\":\"650.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"385.42\",\"selling_price\":\"440.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":24,\"current_stock\":\"16.0000\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\",\"inventory_account_id\":\"acc-1230\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 17:51:59\"}', '{\"id\":\"9e894c3b-f0ab-40bb-8d13-17dc033754d6\",\"sku\":\"CB-059\",\"item_name\":\"Tuborg 650\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"Tuborg\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"650.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"385.42\",\"selling_price\":\"440.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"12\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:12:54'),
(82, 'items', 'f78c3fcb-7c77-4c35-a371-72075d6f61e5', 'update', '{\"id\":\"f78c3fcb-7c77-4c35-a371-72075d6f61e5\",\"sku\":\"CB-047\",\"item_name\":\"Arna Premium Eight 650 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"-2.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"300.00\",\"selling_price\":\"330.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":12,\"current_stock\":\"25.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 17:51:59\"}', '{\"id\":\"f78c3fcb-7c77-4c35-a371-72075d6f61e5\",\"sku\":\"CB-047\",\"item_name\":\"Arna Eight 650\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"650\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"300.00\",\"selling_price\":\"330.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"12\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:13:18'),
(83, 'items', '12791f5d-6176-48a3-88d5-1a059307244c', 'update', '{\"id\":\"12791f5d-6176-48a3-88d5-1a059307244c\",\"sku\":\"CB-053\",\"item_name\":\"Arna Premium Eight 330 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"0.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"153.13\",\"selling_price\":\"170.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":12,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-15 19:30:53\"}', '{\"id\":\"12791f5d-6176-48a3-88d5-1a059307244c\",\"sku\":\"CB-053\",\"item_name\":\"Arna Eight 330 \",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"330\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"153.13\",\"selling_price\":\"170.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"12\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:14:08');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_values`, `new_values`, `user_id`, `created_at`) VALUES
(84, 'items', '49963ff8-5d2d-4c0d-a531-f5048e644817', 'update', '{\"id\":\"49963ff8-5d2d-4c0d-a531-f5048e644817\",\"sku\":\"CB-023\",\"item_name\":\"Carlsberg 650 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"0.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"440.00\",\"selling_price\":\"480.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":10,\"current_stock\":\"9.0000\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\",\"inventory_account_id\":\"acc-1230\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:57:00\"}', '{\"id\":\"49963ff8-5d2d-4c0d-a531-f5048e644817\",\"sku\":\"CB-023\",\"item_name\":\"Carlsberg 650\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"Carlsberg \",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"650\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"440.00\",\"selling_price\":\"480.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"10\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:14:55'),
(85, 'items', 'd16048f0-158c-40ee-9f4f-e52a209d7ee2', 'update', '{\"id\":\"d16048f0-158c-40ee-9f4f-e52a209d7ee2\",\"sku\":\"CB-024\",\"item_name\":\"Gorkha 330 ml\",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"330.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":24,\"cost_price\":\"166.67\",\"selling_price\":\"190.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":24,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\",\"inventory_account_id\":\"acc-1230\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-15 19:30:53\"}', '{\"id\":\"d16048f0-158c-40ee-9f4f-e52a209d7ee2\",\"sku\":\"CB-024\",\"item_name\":\"Gorkha 330 \",\"item_category\":\"ccaa5d61-5fdd-4cd2-924d-6eff7b5999de\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"330\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"166.67\",\"selling_price\":\"200\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"24\",\"description\":\"\",\"inventory_account_id\":\"acc-1230\",\"cogs_account_id\":\"acc-5130\",\"income_account_id\":\"acc-4130\"}', 'usr-admin-001', '2026-07-17 15:15:15'),
(86, 'items', '581f69c5-b0a8-4e0f-9d7d-43e9225a9b40', 'update', '{\"id\":\"581f69c5-b0a8-4e0f-9d7d-43e9225a9b40\",\"sku\":\"CB-054\",\"item_name\":\"Soju\",\"item_category\":\"738b9b15-7d82-4f6e-81e0-036df7634221\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"-3.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"135.00\",\"selling_price\":\"170.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":10,\"current_stock\":\"5.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 17:51:59\"}', '{\"id\":\"581f69c5-b0a8-4e0f-9d7d-43e9225a9b40\",\"sku\":\"CB-054\",\"item_name\":\"Soju\",\"item_category\":\"738b9b15-7d82-4f6e-81e0-036df7634221\",\"brand\":\"Seol Soju\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"300\",\"units_per_case\":\"30\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"135.00\",\"selling_price\":\"170.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"10\",\"description\":\"\",\"inventory_account_id\":\"acc-1220\",\"cogs_account_id\":\"acc-5120\",\"income_account_id\":\"acc-4120\"}', 'usr-admin-001', '2026-07-17 15:16:22'),
(87, 'items', 'eaa83d6b-f225-4f6f-b9ef-c63fd82cc061', 'update', '{\"id\":\"eaa83d6b-f225-4f6f-b9ef-c63fd82cc061\",\"sku\":\"CB-058\",\"item_name\":\"Sagun\",\"item_category\":\"584b1f1e-25b4-4d1a-83b9-b448d6a964f4\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"0.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"38.33\",\"selling_price\":\"50.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":5,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-15 19:30:53\"}', '{\"id\":\"eaa83d6b-f225-4f6f-b9ef-c63fd82cc061\",\"sku\":\"CB-058\",\"item_name\":\"Sagun\",\"item_category\":\"584b1f1e-25b4-4d1a-83b9-b448d6a964f4\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"200\",\"units_per_case\":\"30\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"38.33\",\"selling_price\":\"50.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"5\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-17 15:16:58'),
(88, 'items', '49225a12-9859-4acf-9e02-7c2f19ed4fda', 'update', '{\"id\":\"49225a12-9859-4acf-9e02-7c2f19ed4fda\",\"sku\":\"I-00014\",\"item_name\":\"8848 Vodka 750 ml\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":null,\"barcode\":null,\"bottle_size_ml\":null,\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":null,\"units_per_case\":null,\"cost_price\":\"2100.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"reorder_level\":null,\"reorder_qty\":null,\"current_stock\":\"3.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:24:16\"}', '{\"id\":\"49225a12-9859-4acf-9e02-7c2f19ed4fda\",\"sku\":\"I-00014\",\"item_name\":\"8848 Full\",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"8848 vodka\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"750\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"2100.00\",\"selling_price\":\"2250\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"\",\"inventory_account_id\":\"acc-1210\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\"}', 'usr-admin-001', '2026-07-17 15:18:08'),
(89, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 17:35:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:35:15'),
(90, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-17 17:35:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:35:38'),
(91, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-17 17:35:47\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:35:47'),
(92, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-17 17:36:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:36:09'),
(93, 'system_navigation', 'reports/vat/purchase_register', '', NULL, '{\"page\":\"reports\\/vat\\/purchase_register\",\"accessed_at\":\"2026-07-17 17:36:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:36:17'),
(94, 'system_navigation', 'reports/vat/sales_register', '', NULL, '{\"page\":\"reports\\/vat\\/sales_register\",\"accessed_at\":\"2026-07-17 17:36:20\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:36:20'),
(95, 'system_navigation', 'reports/customers/statement', '', NULL, '{\"page\":\"reports\\/customers\\/statement\",\"accessed_at\":\"2026-07-17 17:36:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:36:22'),
(96, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-17 17:36:31\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:36:31'),
(97, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-17 17:36:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:36:46'),
(98, 'items', '4bb831da-2e3d-4482-bd62-05df0c171742', 'update', '{\"id\":\"4bb831da-2e3d-4482-bd62-05df0c171742\",\"sku\":\"CB-033\",\"item_name\":\"Mustang 375 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"17.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"509.00\",\"selling_price\":\"550.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":2,\"current_stock\":\"5.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 21:20:11\"}', '{\"id\":\"4bb831da-2e3d-4482-bd62-05df0c171742\",\"sku\":\"CB-033\",\"item_name\":\"Mustang  Half\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"375\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"509.00\",\"selling_price\":\"550.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"2\",\"description\":\"\",\"inventory_account_id\":\"acc-1210\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\"}', 'usr-admin-001', '2026-07-17 15:38:20'),
(99, 'items', '58704d38-49ac-4535-a4c2-344aa9acf53b', 'update', '{\"id\":\"58704d38-49ac-4535-a4c2-344aa9acf53b\",\"sku\":\"I-00028\",\"item_name\":\"Mustang Black 750 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"Mustang\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"1200.00\",\"selling_price\":\"1400.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"3.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-07-16 17:53:09\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:24:16\"}', '{\"id\":\"58704d38-49ac-4535-a4c2-344aa9acf53b\",\"sku\":\"I-00028\",\"item_name\":\"Mustang Black Full\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"Mustang\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"1200.00\",\"selling_price\":\"1400.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-17 15:38:33'),
(100, 'items', 'b6533a14-377b-4d29-8d77-fa4d2fe883ee', 'update', '{\"id\":\"b6533a14-377b-4d29-8d77-fa4d2fe883ee\",\"sku\":\"CB-022\",\"item_name\":\"Mustang 750 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"1016.75\",\"selling_price\":\"1100.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":24,\"current_stock\":\"3.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1210\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:24:16\"}', '{\"id\":\"b6533a14-377b-4d29-8d77-fa4d2fe883ee\",\"sku\":\"CB-022\",\"item_name\":\"Mustang Full\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"1016.75\",\"selling_price\":\"1100.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"5\",\"description\":\"\",\"inventory_account_id\":\"acc-1210\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\"}', 'usr-admin-001', '2026-07-17 15:38:59'),
(101, 'items', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'update', '{\"id\":\"c3973817-9b13-4a7a-888c-ad920161c5ea\",\"sku\":\"CB-021\",\"item_name\":\"Mustang 180 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"180.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":48,\"cost_price\":\"254.19\",\"selling_price\":\"280.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":10,\"current_stock\":\"30.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1210\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:24:16\"}', '{\"id\":\"c3973817-9b13-4a7a-888c-ad920161c5ea\",\"sku\":\"CB-021\",\"item_name\":\"Mustang Qtr\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"180.00\",\"units_per_case\":\"48\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"254.19\",\"selling_price\":\"280.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"10\",\"description\":\"\",\"inventory_account_id\":\"acc-1210\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\"}', 'usr-admin-001', '2026-07-17 15:39:11'),
(102, 'items', 'cc197d32-be40-4c35-aee8-b553af156838', 'update', '{\"id\":\"cc197d32-be40-4c35-aee8-b553af156838\",\"sku\":\"I-00027\",\"item_name\":\"Mustang Black 180ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"180.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":48,\"cost_price\":\"300.00\",\"selling_price\":\"340.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"12.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1210\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-07-16 17:09:17\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:24:16\"}', '{\"id\":\"cc197d32-be40-4c35-aee8-b553af156838\",\"sku\":\"I-00027\",\"item_name\":\"Mustang Black Qtr\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"180.00\",\"units_per_case\":\"48\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"300.00\",\"selling_price\":\"340.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1210\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\"}', 'usr-admin-001', '2026-07-17 15:39:40'),
(103, 'items', '2149eeae-1056-4160-bf17-cb71cf454395', 'update', '{\"id\":\"2149eeae-1056-4160-bf17-cb71cf454395\",\"sku\":\"CB-011\",\"item_name\":\"JP chenet\",\"item_category\":\"01e6a28d-9b57-437e-84f9-94a75eeb19a6\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"1800.00\",\"selling_price\":\"2100.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":0,\"reorder_qty\":0,\"current_stock\":\"3.0000\",\"cogs_account_id\":\"acc-5120\",\"income_account_id\":\"acc-4120\",\"inventory_account_id\":\"acc-1220\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-16 17:51:59\"}', '{\"id\":\"2149eeae-1056-4160-bf17-cb71cf454395\",\"sku\":\"CB-011\",\"item_name\":\"JP chenet\",\"item_category\":\"01e6a28d-9b57-437e-84f9-94a75eeb19a6\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"1800.00\",\"selling_price\":\"2200\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"0\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1220\",\"cogs_account_id\":\"acc-5120\",\"income_account_id\":\"acc-4120\"}', 'usr-admin-001', '2026-07-17 15:40:24'),
(104, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-17 17:54:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-17 15:54:52'),
(105, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 10:49:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 08:49:29'),
(106, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 10:55:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 08:55:09'),
(107, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 10:55:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 08:55:58'),
(108, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:27:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:27:50'),
(109, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:33:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:33:22'),
(110, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:34:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:34:43'),
(111, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:36:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:36:46'),
(112, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:39:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:39:24'),
(113, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:39:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:39:56'),
(114, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:40:31\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:40:31'),
(115, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:45:26\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:45:26'),
(116, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 11:46:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:46:04'),
(117, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 11:46:26\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:46:26'),
(118, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 11:47:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:47:00'),
(119, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 11:47:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:47:49'),
(120, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 11:48:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:48:18'),
(121, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 11:48:26\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:48:26'),
(122, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 11:49:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:49:50'),
(123, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 11:50:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 09:50:42'),
(124, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:01:02\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:01:02'),
(125, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 12:04:51\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:04:51'),
(126, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 12:07:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:07:56'),
(127, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 12:14:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:14:37'),
(128, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 12:21:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:21:22'),
(129, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:22:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:22:07'),
(130, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:22:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:22:09'),
(131, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:23:08\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:23:08'),
(132, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:26:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:26:49'),
(133, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 12:27:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:27:52'),
(134, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:28:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:28:12'),
(135, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 12:29:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:29:10'),
(136, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 12:29:30\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:29:30'),
(137, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:30:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:30:29'),
(138, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:49:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:49:46'),
(139, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 12:59:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 10:59:59'),
(140, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:00:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:00:05'),
(141, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 13:00:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:00:13'),
(142, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:04:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:04:57'),
(143, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:05:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:05:00'),
(144, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:08:26\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:08:26'),
(145, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:08:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:08:34'),
(146, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:10:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:10:34'),
(147, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:11:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:11:39'),
(148, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:11:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:11:41'),
(149, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:12:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:12:46'),
(150, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:12:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:12:49'),
(151, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:12:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:12:53'),
(152, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:13:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:13:46'),
(153, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:13:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:13:48'),
(154, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:14:02\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:14:02'),
(155, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:14:08\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:14:08'),
(156, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:15:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:15:45'),
(157, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:15:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:15:58'),
(158, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:16:02\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:16:02'),
(159, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:16:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:16:10'),
(160, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:16:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:16:10'),
(161, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:19:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:19:06'),
(162, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:19:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:19:45'),
(163, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:24:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:24:07'),
(164, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:24:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:24:43'),
(165, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:25:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:25:17'),
(166, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:25:28\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:25:28'),
(167, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:25:30\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:25:30'),
(168, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:25:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:25:48'),
(169, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:25:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:25:55'),
(170, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:25:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:25:57'),
(171, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:26:14\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:26:14'),
(172, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:26:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:26:16'),
(173, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:26:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:26:18'),
(174, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:33:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:33:04'),
(175, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:33:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:33:37'),
(176, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:33:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:33:40'),
(177, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:34:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:34:59'),
(178, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:34:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:34:59'),
(179, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:35:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:09'),
(180, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 13:35:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:09'),
(181, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:35:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:13'),
(182, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:35:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:13'),
(183, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:35:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:16'),
(184, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:35:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:21'),
(185, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:35:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:21'),
(186, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:35:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:43'),
(187, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:35:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:35:45'),
(188, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:39:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:39:37'),
(189, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:41:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:41:27'),
(190, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:42:31\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:42:31'),
(191, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:51:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:51:13'),
(192, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:51:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:51:18'),
(193, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:51:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:51:19'),
(194, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:52:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:52:21'),
(195, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:52:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:52:24'),
(196, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:52:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:52:50');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_values`, `new_values`, `user_id`, `created_at`) VALUES
(197, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:52:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:52:52'),
(198, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:52:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:52:55'),
(199, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:52:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:52:57'),
(200, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:53:26\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:53:26'),
(201, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:54:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:54:22'),
(202, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 13:54:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:54:24'),
(203, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 13:55:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 11:55:16'),
(204, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:00:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:00:24'),
(205, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:01:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:01:04'),
(206, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:08:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:08:46'),
(207, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:08:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:08:49'),
(208, 'pos_entry', 'f00a6da2-98c1-467b-b50c-7935f7dfdb04', 'delete', '{\"id\":\"f00a6da2-98c1-467b-b50c-7935f7dfdb04\",\"invoice_no\":\"POS-20260718-5163\",\"date_time\":\"2026-07-18 14:21:40\",\"customer_id\":\"030e6c08-51b5-4a1e-b35a-a16514a36485\",\"gross_amount\":\"1000.00\",\"discount_type\":\"fixed\",\"discount_value\":\"100.00\",\"discount_amount\":\"100.00\",\"tax_amount\":\"117.00\",\"net_amount\":\"1017.00\",\"status\":\"completed\",\"created_by\":\"usr-admin-001\",\"created_at\":\"2026-07-18 18:06:40\",\"updated_at\":\"2026-07-18 18:06:40\",\"is_deleted\":0}', '[]', 'usr-admin-001', '2026-07-18 12:21:41'),
(209, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:29:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:29:13'),
(210, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:29:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:29:13'),
(211, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:29:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:29:15'),
(212, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:29:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:29:48'),
(213, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:29:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:29:50'),
(214, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:30:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:30:05'),
(215, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:30:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:30:05'),
(216, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:33:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:33:10'),
(217, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:34:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:34:41'),
(218, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:34:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:34:59'),
(219, 'transaction_headers', '564d9249-32bc-4548-8901-9bcee3420994', 'delete', '{\"id\":\"564d9249-32bc-4548-8901-9bcee3420994\",\"txn_number\":\"POS-PAY-20260718-3289\",\"txn_type\":\"customer_payment\",\"txn_date\":\"2026-07-18\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"posted\",\"reference_number\":\"\",\"memo\":\"\",\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-18 16:14:04\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":\"64e084cd-4fdd-409b-9137-56e30c685640\",\"party_type\":\"customer\",\"net_amount\":\"0.00\",\"updated_at\":\"2026-07-18 18:20:29\"}', '[]', 'usr-admin-001', '2026-07-18 12:35:33'),
(220, 'transaction_headers', '5485bd0d-9f39-4898-adb9-7b6e5a9f5a9e', 'delete', '{\"id\":\"5485bd0d-9f39-4898-adb9-7b6e5a9f5a9e\",\"txn_number\":\"POS-PAY-20260718\",\"txn_type\":\"customer_payment\",\"txn_date\":\"2026-07-18\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"posted\",\"reference_number\":\"\",\"memo\":\"\",\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-18 18:06:40\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":\"030e6c08-51b5-4a1e-b35a-a16514a36485\",\"party_type\":\"customer\",\"net_amount\":\"3390.00\",\"updated_at\":\"2026-07-18 18:21:11\"}', '[]', 'usr-admin-001', '2026-07-18 12:36:15'),
(221, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 14:36:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:36:40'),
(222, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:36:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:36:45'),
(223, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 14:36:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:36:50'),
(224, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 14:36:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:36:50'),
(225, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 14:36:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:36:52'),
(226, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 14:38:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:38:59'),
(227, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 14:40:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:40:17'),
(228, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:40:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:40:24'),
(229, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:40:26\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:40:26'),
(230, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:40:28\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:40:28'),
(231, 'transaction_headers', 'b4946e1c-3bb5-4340-b266-cc042370bb1f', 'delete', '{\"id\":\"b4946e1c-3bb5-4340-b266-cc042370bb1f\",\"txn_number\":\"POS-SUM-20260718\",\"txn_type\":\"customer_invoice\",\"txn_date\":\"2026-07-18\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"open\",\"reference_number\":null,\"memo\":\"Edited by test\",\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-18 18:06:41\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":\"030e6c08-51b5-4a1e-b35a-a16514a36485\",\"party_type\":\"customer\",\"net_amount\":\"3390.00\",\"updated_at\":\"2026-07-18 18:21:11\"}', '[]', 'usr-admin-001', '2026-07-18 12:40:38'),
(232, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:40:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:40:43'),
(233, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:40:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:40:45'),
(234, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:40:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:40:46'),
(235, 'transaction_headers', '1fcbec29-de11-4e6a-8bed-589b251a75b3', 'delete', '{\"id\":\"1fcbec29-de11-4e6a-8bed-589b251a75b3\",\"txn_number\":\"POS-20260718-3289\",\"txn_type\":\"customer_invoice\",\"txn_date\":\"2026-07-18\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"open\",\"reference_number\":null,\"memo\":null,\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-18 16:14:04\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":\"64e084cd-4fdd-409b-9137-56e30c685640\",\"party_type\":\"customer\",\"net_amount\":\"0.00\",\"updated_at\":\"2026-07-18 18:20:29\"}', '[]', 'usr-admin-001', '2026-07-18 12:40:53'),
(236, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:40:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:40:57'),
(237, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:41:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:41:00'),
(238, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:41:03\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:41:03'),
(239, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:42:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:42:54'),
(240, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:47:03\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:47:03'),
(241, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:47:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:47:22'),
(242, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:47:23\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:47:23'),
(243, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:48:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:48:01'),
(244, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:48:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:48:07'),
(245, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:49:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:49:07'),
(246, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:49:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:49:09'),
(247, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:50:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:50:00'),
(248, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 14:50:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:50:01'),
(249, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:50:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:50:06'),
(250, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:50:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:50:13'),
(251, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:50:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:50:54'),
(252, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 14:50:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:50:56'),
(253, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:51:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:51:01'),
(254, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:51:03\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:51:03'),
(255, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:51:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:51:50'),
(256, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:52:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:52:50'),
(257, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:53:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:53:38'),
(258, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:54:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:54:06'),
(259, 'system_navigation', 'reports/purchases/by_vendor', '', NULL, '{\"page\":\"reports\\/purchases\\/by_vendor\",\"accessed_at\":\"2026-07-18 14:54:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:54:46'),
(260, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 14:54:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:54:53'),
(261, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 14:54:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:54:58'),
(262, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 14:55:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:55:10'),
(263, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 14:55:30\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:55:30'),
(264, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 14:55:30\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 12:55:30'),
(265, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:13'),
(266, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:16'),
(267, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:16'),
(268, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:29'),
(269, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:29'),
(270, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:34'),
(271, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:34'),
(272, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:35'),
(273, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:35'),
(274, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:35'),
(275, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 15:01:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:01:35'),
(276, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-18 15:02:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:02:18'),
(277, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-18 15:02:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:02:22'),
(278, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-18 15:02:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:02:33'),
(279, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 15:03:25\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:03:25'),
(280, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 15:03:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:03:32'),
(281, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 15:03:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:03:45'),
(282, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 15:03:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:03:45'),
(283, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 15:04:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:04:13'),
(284, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-18 15:05:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:05:43'),
(285, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 15:07:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:07:24'),
(286, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 15:07:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:07:29'),
(287, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-18 15:07:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:07:34'),
(288, 'items', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'update', '{\"id\":\"29576fa7-2a38-44c0-8827-76cb1e5ce2b4\",\"sku\":\"CB-032\",\"item_name\":\"Mineral Water\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"0.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"11.00\",\"selling_price\":\"20.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":2,\"current_stock\":\"15.0000\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-18 18:51:11\"}', '{\"id\":\"29576fa7-2a38-44c0-8827-76cb1e5ce2b4\",\"sku\":\"CB-032\",\"item_name\":\"Mineral Water\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"0.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"11.00\",\"selling_price\":\"20.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"2\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5110\",\"income_account_id\":\"acc-4110\"}', 'usr-admin-001', '2026-07-18 13:07:54'),
(289, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 15:59:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 13:59:56'),
(290, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 16:00:28\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:00:28'),
(291, 'system_navigation', 'reports/customers/statement', '', NULL, '{\"page\":\"reports\\/customers\\/statement\",\"accessed_at\":\"2026-07-18 16:01:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:01:07'),
(292, 'system_navigation', 'reports/customers/statement', '', NULL, '{\"page\":\"reports\\/customers\\/statement\",\"accessed_at\":\"2026-07-18 16:01:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:01:13'),
(293, 'customers', '92017a9b-1dc6-43e1-909b-192ee73c0d68', 'update', '{\"id\":\"92017a9b-1dc6-43e1-909b-192ee73c0d68\",\"customer_code\":\"C-00019\",\"full_name\":\"Da junction jadibuti\",\"customer_type\":\"retail\",\"phone\":\"9843549228\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '{\"id\":\"92017a9b-1dc6-43e1-909b-192ee73c0d68\",\"customer_code\":\"C-00019\",\"full_name\":\"Da junction jadibuti\",\"customer_type\":\"retail\",\"pan_number\":\"\",\"phone\":\"9843549228\",\"email\":\"\",\"credit_limit\":\"0.00\",\"receivable_account_id\":\"acc-1100\",\"payment_terms_days\":\"\",\"is_active\":0}', 'usr-admin-001', '2026-07-18 14:01:49'),
(294, 'customers', '91858b7d-b4cd-46a0-b82c-2c9181ad7dc4', 'update', '{\"id\":\"91858b7d-b4cd-46a0-b82c-2c9181ad7dc4\",\"customer_code\":\"C-00001\",\"full_name\":\"Da junction\",\"customer_type\":\"bar\",\"phone\":\"\",\"email\":\"\",\"pan_number\":\"11220011\",\"receivable_account_id\":\"acc-1100\",\"credit_limit\":\"25000.00\",\"payment_terms_days\":7,\"is_active\":1,\"created_at\":\"2026-05-03 21:20:45\",\"is_deleted\":0,\"updated_at\":\"2026-05-03 21:20:45\"}', '{\"id\":\"91858b7d-b4cd-46a0-b82c-2c9181ad7dc4\",\"customer_code\":\"C-00001\",\"full_name\":\"Da junction\",\"customer_type\":\"bar\",\"pan_number\":\"11220011\",\"phone\":\"\",\"email\":\"\",\"credit_limit\":\"25000.00\",\"receivable_account_id\":\"acc-1100\",\"payment_terms_days\":\"7\",\"is_active\":0}', 'usr-admin-001', '2026-07-18 14:01:54'),
(295, 'customers', '621d4fb6-1fc5-4086-9848-15bc9514a165', 'update', '{\"id\":\"621d4fb6-1fc5-4086-9848-15bc9514a165\",\"customer_code\":\"C-00036\",\"full_name\":\"Mathi ko manche\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":\"\",\"pan_number\":\"\",\"receivable_account_id\":\"acc-1100\",\"credit_limit\":\"0.00\",\"payment_terms_days\":0,\"is_active\":1,\"created_at\":\"2026-06-11 20:43:54\",\"is_deleted\":0,\"updated_at\":\"2026-06-11 20:43:54\"}', '{\"id\":\"621d4fb6-1fc5-4086-9848-15bc9514a165\",\"customer_code\":\"C-00036\",\"full_name\":\"Mathi ko manche\",\"customer_type\":\"retail\",\"pan_number\":\"\",\"phone\":\"\",\"email\":\"\",\"credit_limit\":\"0.00\",\"receivable_account_id\":\"acc-1100\",\"payment_terms_days\":\"0\",\"is_active\":0}', 'usr-admin-001', '2026-07-18 14:02:30'),
(296, 'customers', '05d684a2-bc82-48c5-89b9-f4c54b646592', 'delete', '{\"id\":\"05d684a2-bc82-48c5-89b9-f4c54b646592\",\"customer_code\":\"C-00021\",\"full_name\":\"Arunima Guffadi\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":\"\",\"pan_number\":\"\",\"receivable_account_id\":\"acc-1100\",\"credit_limit\":\"0.00\",\"payment_terms_days\":0,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:02:38'),
(297, 'customers', '2fb5ae37-2432-41bb-943d-8fb7c5bc6928', 'update', '{\"id\":\"2fb5ae37-2432-41bb-943d-8fb7c5bc6928\",\"customer_code\":\"C-00025\",\"full_name\":\"Whole seller madhise\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '{\"id\":\"2fb5ae37-2432-41bb-943d-8fb7c5bc6928\",\"customer_code\":\"C-00025\",\"full_name\":\"Whole seller madhise\",\"customer_type\":\"retail\",\"pan_number\":\"\",\"phone\":\"\",\"email\":\"\",\"credit_limit\":\"0.00\",\"receivable_account_id\":\"acc-1100\",\"payment_terms_days\":\"\",\"is_active\":0}', 'usr-admin-001', '2026-07-18 14:02:49'),
(298, 'customers', '4454affb-caea-4126-a9a4-8f9230e3a624', 'delete', '{\"id\":\"4454affb-caea-4126-a9a4-8f9230e3a624\",\"customer_code\":\"C-00028\",\"full_name\":\"Bhena\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:02:58'),
(299, 'customers', '3f369150-78d9-4f44-87f4-3631ab6dcfd7', 'delete', '{\"id\":\"3f369150-78d9-4f44-87f4-3631ab6dcfd7\",\"customer_code\":\"C-00027\",\"full_name\":\"Pachadi Hotel\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:03:02'),
(300, 'customers', '38c6d432-e260-4b2b-bd7d-4ed5a6411572', 'delete', '{\"id\":\"38c6d432-e260-4b2b-bd7d-4ed5a6411572\",\"customer_code\":\"C-00026\",\"full_name\":\"Rum Lane dai\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:03:06'),
(301, 'customers', '7bc8fae8-ff58-4575-8f10-7ab923c364d3', 'delete', '{\"id\":\"7bc8fae8-ff58-4575-8f10-7ab923c364d3\",\"customer_code\":\"C-00020\",\"full_name\":\"Masu Pasal\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:03:11'),
(302, 'customers', '4cd924d9-6503-4acf-9c7b-25d56561705d', 'delete', '{\"id\":\"4cd924d9-6503-4acf-9c7b-25d56561705d\",\"customer_code\":\"C-00030\",\"full_name\":\"Furniture Choro\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:03:29'),
(303, 'customers', '66b4e291-c205-4921-bf28-4ac558203d7a', 'delete', '{\"id\":\"66b4e291-c205-4921-bf28-4ac558203d7a\",\"customer_code\":\"C-00033\",\"full_name\":\"Dhup Sahu\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:03:34'),
(304, 'customers', '670300dd-a714-4584-a7df-d63be456ec77', 'delete', '{\"id\":\"670300dd-a714-4584-a7df-d63be456ec77\",\"customer_code\":\"C-00034\",\"full_name\":\"Blue Bird new\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:03:41'),
(305, 'customers', 'd3d33a35-77d5-409e-9637-51f916f2a251', 'delete', '{\"id\":\"d3d33a35-77d5-409e-9637-51f916f2a251\",\"customer_code\":\"C-00017\",\"full_name\":\"Blue Bird\",\"customer_type\":\"retail\",\"phone\":\"9841137355\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '[]', 'usr-admin-001', '2026-07-18 14:03:49'),
(306, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 16:04:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:04:10'),
(307, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 16:30:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:30:55'),
(308, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 16:30:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:30:57'),
(309, 'system_navigation', 'reports/customers/statement', '', NULL, '{\"page\":\"reports\\/customers\\/statement\",\"accessed_at\":\"2026-07-18 16:32:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:32:42'),
(310, 'system_navigation', 'reports/customers/statement', '', NULL, '{\"page\":\"reports\\/customers\\/statement\",\"accessed_at\":\"2026-07-18 16:32:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:32:48'),
(311, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 16:32:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:32:57'),
(312, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 16:32:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 14:32:59'),
(313, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 17:03:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:03:52'),
(314, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:05:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:05:39'),
(315, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 17:06:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:06:13'),
(316, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:07:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:07:55'),
(317, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:09:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:09:39'),
(318, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:10:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:10:06'),
(319, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:10:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:10:48'),
(320, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:10:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:10:50'),
(321, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:10:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:10:59'),
(322, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:11:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:11:00'),
(323, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:11:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:11:00'),
(324, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:11:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:11:00'),
(325, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:11:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:11:01');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_values`, `new_values`, `user_id`, `created_at`) VALUES
(326, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:11:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:11:43'),
(327, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:11:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:11:45'),
(328, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:12:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:12:01'),
(329, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:12:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:12:32'),
(330, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:12:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:12:33'),
(331, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:13:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:13:22'),
(332, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:13:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:13:59'),
(333, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:14:28\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:14:28'),
(334, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:14:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:14:36'),
(335, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 17:14:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:14:44'),
(336, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:14:47\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:14:47'),
(337, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:14:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:14:52'),
(338, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:16:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:16:17'),
(339, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:16:23\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:16:23'),
(340, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:17:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:17:56'),
(341, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:19:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:19:04'),
(342, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:19:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:19:37'),
(343, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:19:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:19:37'),
(344, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:19:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:19:37'),
(345, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:19:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:19:38'),
(346, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:21:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:21:17'),
(347, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:21:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:21:18'),
(348, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:21:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:21:39'),
(349, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:22:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:22:16'),
(350, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:22:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:22:17'),
(351, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:22:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:22:44'),
(352, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:22:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:22:45'),
(353, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:22:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:22:45'),
(354, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:22:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:22:46'),
(355, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:22:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:22:55'),
(356, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:23:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:23:54'),
(357, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:24:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:24:09'),
(358, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:24:26\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:24:26'),
(359, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 17:24:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:24:48'),
(360, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-18 17:24:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:24:54'),
(361, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 17:25:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:25:06'),
(362, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 17:26:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:26:21'),
(363, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 17:26:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:26:33'),
(364, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 17:27:23\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:27:23'),
(365, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 17:27:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:27:38'),
(366, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-18 17:27:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:27:59'),
(367, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:28:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:28:33'),
(368, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 17:28:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:28:36'),
(369, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 17:29:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:29:06'),
(370, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-18 17:29:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:29:27'),
(371, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-18 17:29:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:29:57'),
(372, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-18 17:30:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:30:01'),
(373, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-18 17:30:51\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:30:51'),
(374, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 17:30:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:30:58'),
(375, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-18 17:31:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:31:06'),
(376, 'system_navigation', 'reports/sales/by_customer', '', NULL, '{\"page\":\"reports\\/sales\\/by_customer\",\"accessed_at\":\"2026-07-18 17:31:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:31:32'),
(377, 'system_navigation', 'reports/sales/by_customer', '', NULL, '{\"page\":\"reports\\/sales\\/by_customer\",\"accessed_at\":\"2026-07-18 17:31:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:31:40'),
(378, 'system_navigation', 'reports/sales/by_customer', '', NULL, '{\"page\":\"reports\\/sales\\/by_customer\",\"accessed_at\":\"2026-07-18 17:31:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:31:54'),
(379, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:32:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:32:04'),
(380, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:32:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:32:24'),
(381, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:32:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:32:54'),
(382, 'system_navigation', 'reports/inventory/less_stock', '', NULL, '{\"page\":\"reports\\/inventory\\/less_stock\",\"accessed_at\":\"2026-07-18 17:33:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:33:05'),
(383, 'system_navigation', 'reports/inventory/urgent_buy', '', NULL, '{\"page\":\"reports\\/inventory\\/urgent_buy\",\"accessed_at\":\"2026-07-18 17:33:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:33:15'),
(384, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:33:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:33:22'),
(385, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:33:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:33:37'),
(386, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:34:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:34:33'),
(387, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:35:11\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:35:11'),
(388, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:35:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:35:38'),
(389, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:37:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:37:01'),
(390, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:37:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:37:05'),
(391, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:38:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:38:59'),
(392, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:40:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:40:41'),
(393, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:40:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:40:57'),
(394, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:41:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:41:06'),
(395, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:41:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:41:13'),
(396, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:41:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:41:19'),
(397, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:41:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:41:33'),
(398, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:42:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:42:16'),
(399, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-18 17:43:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:43:33'),
(400, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-18 17:43:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:43:37'),
(401, 'system_navigation', 'reports/inventory/stock_ledger', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_ledger\",\"accessed_at\":\"2026-07-18 17:43:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:43:40'),
(402, 'system_navigation', 'reports/inventory/low_stock', '', NULL, '{\"page\":\"reports\\/inventory\\/low_stock\",\"accessed_at\":\"2026-07-18 17:43:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:43:55'),
(403, 'system_navigation', 'reports/inventory/less_stock', '', NULL, '{\"page\":\"reports\\/inventory\\/less_stock\",\"accessed_at\":\"2026-07-18 17:44:28\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:44:28'),
(404, 'system_navigation', 'reports/vat/sales_register', '', NULL, '{\"page\":\"reports\\/vat\\/sales_register\",\"accessed_at\":\"2026-07-18 17:44:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:44:49'),
(405, 'system_navigation', 'reports/vat/purchase_register', '', NULL, '{\"page\":\"reports\\/vat\\/purchase_register\",\"accessed_at\":\"2026-07-18 17:44:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:44:53'),
(406, 'system_navigation', 'reports/customers/statement', '', NULL, '{\"page\":\"reports\\/customers\\/statement\",\"accessed_at\":\"2026-07-18 17:44:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:44:56'),
(407, 'system_navigation', 'reports/customers/statement', '', NULL, '{\"page\":\"reports\\/customers\\/statement\",\"accessed_at\":\"2026-07-18 17:45:02\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:45:02'),
(408, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 17:45:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 15:45:15'),
(409, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-18 18:05:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-18 16:05:44'),
(410, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:26:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:26:12'),
(411, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:26:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:26:49'),
(412, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:27:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:27:12'),
(413, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:28:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:28:35'),
(414, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:37:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:37:35'),
(415, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:55:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:55:56'),
(416, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:56:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:56:36'),
(417, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:58:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:58:27'),
(418, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:58:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:58:29'),
(419, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-19 08:58:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:58:58'),
(420, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 08:59:25\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 06:59:25'),
(421, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:00:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:00:36'),
(422, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-19 09:04:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:04:15'),
(423, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:04:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:04:29'),
(424, 'system_navigation', 'reports/purchases/by_vendor', '', NULL, '{\"page\":\"reports\\/purchases\\/by_vendor\",\"accessed_at\":\"2026-07-19 09:04:47\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:04:47'),
(425, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:05:03\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:05:03'),
(426, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:05:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:05:04'),
(427, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:06:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:06:09'),
(428, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:06:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:06:22'),
(429, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:07:03\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:07:03'),
(430, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:07:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:07:15'),
(431, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:08:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:08:07'),
(432, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:10:47\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:10:47'),
(433, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:12:02\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:12:02'),
(434, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:13:37\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:13:37'),
(435, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:14:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:14:41'),
(436, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:15:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:15:04'),
(437, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:15:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:15:18'),
(438, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:15:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:15:40'),
(439, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:16:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:16:16'),
(440, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:16:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:16:22'),
(441, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-19 09:17:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:17:24'),
(442, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:17:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:17:42'),
(443, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 09:17:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:17:50'),
(444, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:20:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:20:00'),
(445, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 09:20:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:20:05'),
(446, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 09:22:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:22:06'),
(447, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 09:22:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:22:10'),
(448, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 09:22:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:22:53'),
(449, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 09:23:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:23:18'),
(450, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 09:23:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:23:36'),
(451, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:24:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:24:21'),
(452, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 09:26:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:26:50'),
(453, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 09:26:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:26:55'),
(454, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-19 09:27:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:27:27'),
(455, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-19 09:27:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:27:58'),
(456, 'system_navigation', 'reports/sales/by_customer', '', NULL, '{\"page\":\"reports\\/sales\\/by_customer\",\"accessed_at\":\"2026-07-19 09:28:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:28:45'),
(457, 'system_navigation', 'reports/sales/by_customer', '', NULL, '{\"page\":\"reports\\/sales\\/by_customer\",\"accessed_at\":\"2026-07-19 09:28:51\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:28:51'),
(458, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:29:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:29:49'),
(459, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:32:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:32:12'),
(460, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:34:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:34:27'),
(461, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:36:14\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:36:14'),
(462, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:36:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:36:43'),
(463, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:37:28\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:37:28'),
(464, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:39:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:39:43'),
(465, 'accounts', 'acc-6100', 'update', '{\"id\":\"acc-6100\",\"account_code\":\"6100\",\"account_name\":\"Rent Expense\",\"account_type\":\"expense\",\"account_subtype\":\"other\",\"normal_balance\":\"debit\",\"parent_account_id\":\"acc-6000\",\"currency\":\"NPR\",\"is_active\":1,\"created_at\":\"2026-04-29 16:24:44\",\"is_deleted\":0,\"updated_at\":\"2026-04-29 16:24:44\",\"opening_balance\":\"0.00\",\"deleted_at\":null}', '{\"id\":\"acc-6100\",\"account_code\":\"6100\",\"account_name\":\"Rent Expense\",\"account_type\":\"expense\",\"normal_balance\":\"debit\",\"account_subtype\":\"other\",\"is_active\":1}', 'usr-admin-001', '2026-07-19 07:40:08'),
(466, 'accounts', 'acc-1010', 'update', '{\"id\":\"acc-1010\",\"account_code\":\"1010\",\"account_name\":\"Cash on Hand\",\"account_type\":\"asset\",\"account_subtype\":\"cash\",\"normal_balance\":\"debit\",\"parent_account_id\":\"acc-1000\",\"currency\":\"NPR\",\"is_active\":1,\"created_at\":\"2026-04-29 16:24:44\",\"is_deleted\":0,\"updated_at\":\"2026-07-18 16:52:52\",\"opening_balance\":\"0.00\",\"deleted_at\":null}', '{\"id\":\"acc-1010\",\"account_code\":\"1010\",\"account_name\":\"Cash on Hand\",\"account_type\":\"asset\",\"normal_balance\":\"debit\",\"account_subtype\":\"bank\",\"is_active\":1}', 'usr-admin-001', '2026-07-19 07:40:22'),
(467, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:40:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:40:24'),
(468, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:40:31\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:40:31'),
(469, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:40:47\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:40:47');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_values`, `new_values`, `user_id`, `created_at`) VALUES
(470, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:45:31\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:45:31'),
(471, 'transaction_headers', 'd94529b1-a60a-424c-b22a-2c2a7c073fad', 'delete', '{\"id\":\"d94529b1-a60a-424c-b22a-2c2a7c073fad\",\"txn_number\":\"JV-00001\",\"txn_type\":\"Journal\",\"txn_date\":\"2026-07-19\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"posted\",\"reference_number\":\"\",\"memo\":\"\",\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-19 13:24:18\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":null,\"party_type\":null,\"net_amount\":\"11000.00\",\"updated_at\":\"2026-07-19 13:24:18\"}', '[]', 'usr-admin-001', '2026-07-19 07:45:41'),
(472, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:45:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:45:53'),
(473, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:47:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:47:18'),
(474, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 09:47:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:47:41'),
(475, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 09:47:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:47:56'),
(476, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 09:48:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:48:12'),
(477, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 09:48:31\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:48:31'),
(478, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:53:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:53:00'),
(479, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:54:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:54:42'),
(480, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-19 09:54:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:54:55'),
(481, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-19 09:55:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:55:00'),
(482, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 09:55:08\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:55:08'),
(483, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 09:55:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:55:18'),
(484, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 09:55:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:55:24'),
(485, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 09:55:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 07:55:32'),
(486, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 10:26:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 08:26:10'),
(487, 'transaction_headers', 'f3a6aaf2-f3fd-420c-b622-7eb0d6433fc4', 'delete', '{\"id\":\"f3a6aaf2-f3fd-420c-b622-7eb0d6433fc4\",\"txn_number\":\"VI-00007\",\"txn_type\":\"vendor_bill\",\"txn_date\":\"2026-07-13\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"open\",\"reference_number\":\"VI-00007\",\"memo\":\"old amount adjustment\",\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-19 12:45:33\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":null,\"party_type\":null,\"net_amount\":\"0.00\",\"updated_at\":\"2026-07-19 13:04:57\",\"source\":null,\"is_readonly\":0,\"is_locked\":0}', '[]', 'usr-admin-001', '2026-07-19 08:29:05'),
(488, 'transaction_headers', 'a2648690-e770-43e7-9acb-9c6654dad464', 'delete', '{\"id\":\"a2648690-e770-43e7-9acb-9c6654dad464\",\"txn_number\":\"SI-00004\",\"txn_type\":\"customer_invoice\",\"txn_date\":\"2026-07-15\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"open\",\"reference_number\":null,\"memo\":\"old payment not received\",\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-19 12:53:04\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":\"48e56ded-e263-41de-968d-f134b7c22deb\",\"party_type\":\"customer\",\"net_amount\":\"1550.00\",\"updated_at\":\"2026-07-19 13:04:43\",\"source\":null,\"is_readonly\":0,\"is_locked\":0}', '[]', 'usr-admin-001', '2026-07-19 08:29:14'),
(489, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 10:45:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 08:45:54'),
(490, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 11:15:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:15:29'),
(491, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 11:15:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:15:38'),
(492, 'items', 'e88436f2-0460-408d-9494-190246334d27', 'update', '{\"id\":\"e88436f2-0460-408d-9494-190246334d27\",\"sku\":\"I-00008\",\"item_name\":\"Big Master\",\"item_category\":\"01e6a28d-9b57-437e-84f9-94a75eeb19a6\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"641.67\",\"selling_price\":\"0.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"17.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 15:05:39\"}', '{\"id\":\"e88436f2-0460-408d-9494-190246334d27\",\"sku\":\"I-00008\",\"item_name\":\"Big Master\",\"item_category\":\"01e6a28d-9b57-437e-84f9-94a75eeb19a6\",\"brand\":\"\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"641.67\",\"selling_price\":\"750\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 09:36:52'),
(493, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 11:37:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:37:15'),
(494, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-19 11:37:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:37:17'),
(495, 'customers', '030e6c08-51b5-4a1e-b35a-a16514a36485', 'update', '{\"id\":\"030e6c08-51b5-4a1e-b35a-a16514a36485\",\"customer_code\":\"C-00016\",\"full_name\":\"Bhada\",\"customer_type\":\"retail\",\"phone\":\"\",\"email\":null,\"pan_number\":null,\"receivable_account_id\":\"acc-1100\",\"credit_limit\":null,\"payment_terms_days\":null,\"is_active\":1,\"created_at\":\"2026-05-10 19:16:35\",\"is_deleted\":0,\"updated_at\":\"2026-05-23 19:46:12\"}', '{\"id\":\"030e6c08-51b5-4a1e-b35a-a16514a36485\",\"customer_code\":\"C-00016\",\"full_name\":\"Bhada\",\"customer_type\":\"retail\",\"pan_number\":\"\",\"phone\":\"\",\"email\":\"\",\"credit_limit\":\"0.00\",\"is_active\":1,\"receivable_account_id\":\"acc-1100\",\"payment_terms_days\":\"5\"}', 'usr-admin-001', '2026-07-19 09:37:46'),
(496, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 11:40:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:40:22'),
(497, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 11:45:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:45:22'),
(498, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 11:46:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:46:57'),
(499, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-19 11:47:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:47:41'),
(500, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 11:48:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:48:38'),
(501, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 11:51:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:51:44'),
(502, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 11:51:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:51:53'),
(503, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 11:52:20\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:52:20'),
(504, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 11:54:30\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:54:30'),
(505, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 11:55:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:55:39'),
(506, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 11:55:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:55:54'),
(507, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 11:56:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:56:07'),
(508, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 11:56:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:56:17'),
(509, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 11:56:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:56:36'),
(510, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 11:58:30\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 09:58:30'),
(511, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:00:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:00:27'),
(512, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:00:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:00:40'),
(513, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:00:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:00:42'),
(514, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:00:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:00:53'),
(515, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:02:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:02:27'),
(516, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:02:55\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:02:55'),
(517, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:03:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:03:04'),
(518, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:03:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:03:05'),
(519, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:03:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:03:05'),
(520, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:03:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:03:07'),
(521, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 12:04:08\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:04:08'),
(522, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:04:20\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:04:20'),
(523, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:04:25\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:04:25'),
(524, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:11\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:11'),
(525, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:12'),
(526, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:12'),
(527, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:12'),
(528, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:18'),
(529, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:18'),
(530, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:19'),
(531, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:19'),
(532, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:19'),
(533, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:19'),
(534, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:19'),
(535, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:20\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:20'),
(536, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:20\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:20'),
(537, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:21'),
(538, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:21'),
(539, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:21'),
(540, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:21'),
(541, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:22'),
(542, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:22\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:22'),
(543, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:32'),
(544, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:40'),
(545, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 12:06:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:06:49'),
(546, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 12:08:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 10:08:16'),
(547, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 16:42:28\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 14:42:28'),
(548, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 16:49:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 14:49:34'),
(549, 'items', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'update', '{\"id\":\"2f9197f7-228d-481c-8ae8-0bbbfc7f998d\",\"sku\":\"I-00013\",\"item_name\":\"misc Exp\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"0.00\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":\"\",\"units_per_case\":0,\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"1.5000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 13:31:48\"}', '{\"id\":\"2f9197f7-228d-481c-8ae8-0bbbfc7f998d\",\"sku\":\"I-00013\",\"item_name\":\"Misc Exp\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"0.00\",\"units_per_case\":\"0\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"tax_rate\":\"13.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:50:00'),
(550, 'items', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'update', '{\"id\":\"2f9197f7-228d-481c-8ae8-0bbbfc7f998d\",\"sku\":\"I-00013\",\"item_name\":\"Misc Exp\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"0.00\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":\"\",\"units_per_case\":0,\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"1.5000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 20:35:00\"}', '{\"id\":\"2f9197f7-228d-481c-8ae8-0bbbfc7f998d\",\"sku\":\"I-00013\",\"item_name\":\"Misc Exp\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"0.00\",\"units_per_case\":\"0\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"0.00\",\"selling_price\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:50:11'),
(551, 'items', '66e8bb29-94f3-4cd7-ac67-14ada9da1c4c', 'update', '{\"id\":\"66e8bb29-94f3-4cd7-ac67-14ada9da1c4c\",\"sku\":\"CB-040\",\"item_name\":\"8848 Vodka 180 ml\",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"180.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"525.00\",\"selling_price\":\"550.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":10,\"current_stock\":\"12.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 13:29:59\"}', '{\"id\":\"66e8bb29-94f3-4cd7-ac67-14ada9da1c4c\",\"sku\":\"CB-040\",\"item_name\":\"8848 Qtr\",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"180.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"525.00\",\"selling_price\":\"570\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"10\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:51:57'),
(552, 'items', '3f737cab-beef-4873-8d28-30f48bb20818', 'update', '{\"id\":\"3f737cab-beef-4873-8d28-30f48bb20818\",\"sku\":\"CB-042\",\"item_name\":\"Highlander 180 ml\",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"40.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"275.00\",\"selling_price\":\"290.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":15,\"current_stock\":\"9.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 14:53:55\"}', '{\"id\":\"3f737cab-beef-4873-8d28-30f48bb20818\",\"sku\":\"CB-042\",\"item_name\":\"Highlander Qtr\",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"180\",\"units_per_case\":\"48\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"275.00\",\"selling_price\":\"290.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"15\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:52:46'),
(553, 'items', '5018c912-57b9-49d5-9618-e9156f5150e5', 'update', '{\"id\":\"5018c912-57b9-49d5-9618-e9156f5150e5\",\"sku\":\"CB-035\",\"item_name\":\"Highlander 375 ml\",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"14.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"516.67\",\"selling_price\":\"580.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":10,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 13:29:59\"}', '{\"id\":\"5018c912-57b9-49d5-9618-e9156f5150e5\",\"sku\":\"CB-035\",\"item_name\":\"Highlander Half\",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"375\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"516.67\",\"selling_price\":\"580.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"10\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:53:15'),
(554, 'items', 'd20f5087-48f0-4ec3-950c-d7393884aed4', 'update', '{\"id\":\"d20f5087-48f0-4ec3-950c-d7393884aed4\",\"sku\":\"I-00029\",\"item_name\":\"Highlander 750 ml \",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"d14f742a-cde3-4419-abf2-f229b5893983\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"1034.00\",\"selling_price\":\"1150.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"3.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":null,\"created_at\":\"2026-07-16 17:58:55\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:24:16\"}', '{\"id\":\"d20f5087-48f0-4ec3-950c-d7393884aed4\",\"sku\":\"I-00029\",\"item_name\":\"Highlander Full\",\"item_category\":\"71acc735-19e5-4a9b-9f59-7a7e54289789\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"1034.00\",\"selling_price\":\"1140\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:54:10'),
(555, 'items', '5e21fcfb-5077-4a22-97f3-574bda1923f6', 'update', '{\"id\":\"5e21fcfb-5077-4a22-97f3-574bda1923f6\",\"sku\":\"CB-029\",\"item_name\":\"Golden oak 180 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"20.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"275.00\",\"selling_price\":\"300.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":24,\"current_stock\":\"29.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 14:53:55\"}', '{\"id\":\"5e21fcfb-5077-4a22-97f3-574bda1923f6\",\"sku\":\"GO-029\",\"item_name\":\"Golden Oak Qtr\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"180\",\"units_per_case\":\"48\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"275.00\",\"selling_price\":\"300.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"24\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:54:56'),
(556, 'items', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'update', '{\"id\":\"65a60e75-a453-4c11-bb40-99ef492b3dcb\",\"sku\":\"CB-005\",\"item_name\":\"OD 180 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"180.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"670.88\",\"selling_price\":\"750.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":24,\"current_stock\":\"15.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 14:53:55\"}', '{\"id\":\"65a60e75-a453-4c11-bb40-99ef492b3dcb\",\"sku\":\"OD-005\",\"item_name\":\"OD Qtr\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"bottle_size_ml\":\"180.00\",\"units_per_case\":\"48\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"670.88\",\"selling_price\":\"725\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"5\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:55:42'),
(557, 'items', '5784078a-3fad-484a-a81c-32a60784be4e', 'update', '{\"id\":\"5784078a-3fad-484a-a81c-32a60784be4e\",\"sku\":\"I-00004\",\"item_name\":\"Khukri Rum 375 ml\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":null,\"barcode\":null,\"bottle_size_ml\":null,\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":null,\"units_per_case\":null,\"cost_price\":\"995.83\",\"selling_price\":\"0.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"reorder_level\":null,\"reorder_qty\":null,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:55:59\",\"is_deleted\":0,\"updated_at\":\"2026-07-15 19:30:53\"}', '{\"id\":\"5784078a-3fad-484a-a81c-32a60784be4e\",\"sku\":\"KR-00004\",\"item_name\":\"Khukri Rum Half\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"375\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"995.83\",\"selling_price\":\"1100\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:56:40'),
(558, 'items', '76fb4973-11fe-4476-8e02-3545531d4bf9', 'update', '{\"id\":\"76fb4973-11fe-4476-8e02-3545531d4bf9\",\"sku\":\"I-00021\",\"item_name\":\"Golden oak 375 ml\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":null,\"barcode\":null,\"bottle_size_ml\":null,\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":null,\"units_per_case\":null,\"cost_price\":\"548.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"reorder_level\":null,\"reorder_qty\":null,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-15 19:30:53\"}', '{\"id\":\"76fb4973-11fe-4476-8e02-3545531d4bf9\",\"sku\":\"GO-00021\",\"item_name\":\"Golden Oak Half\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"375\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"548.00\",\"selling_price\":\"600\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"2\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:57:20'),
(559, 'items', '76fb4973-11fe-4476-8e02-3545531d4bf9', 'update', '{\"id\":\"76fb4973-11fe-4476-8e02-3545531d4bf9\",\"sku\":\"GO-00021\",\"item_name\":\"Golden Oak Half\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"375.00\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":\"\",\"units_per_case\":24,\"cost_price\":\"548.00\",\"selling_price\":\"600.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":2,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 20:42:20\"}', '{\"id\":\"76fb4973-11fe-4476-8e02-3545531d4bf9\",\"sku\":\"GO-00021\",\"item_name\":\"Golden Oak Half\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"375.00\",\"units_per_case\":\"24\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"548.00\",\"selling_price\":\"600.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"2\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:57:26'),
(560, 'items', 'f51bffdf-4860-461e-ab42-cdfc4c941cb7', 'update', '{\"id\":\"f51bffdf-4860-461e-ab42-cdfc4c941cb7\",\"sku\":\"CB-013\",\"item_name\":\"Khukri Rum 750 ml\",\"item_category\":\"5b907b99-9627-420c-bda6-70853db398bb\",\"brand\":\"\",\"barcode\":null,\"bottle_size_ml\":\"0.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":null,\"units_per_case\":12,\"cost_price\":\"2000.00\",\"selling_price\":\"2200.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":24,\"current_stock\":\"4.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 14:53:55\"}', '{\"id\":\"f51bffdf-4860-461e-ab42-cdfc4c941cb7\",\"sku\":\"KR-013\",\"item_name\":\"Khukri Rum Full\",\"item_category\":\"5b907b99-9627-420c-bda6-70853db398bb\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"750\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"2000.00\",\"selling_price\":\"2200.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"24\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:58:25');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_values`, `new_values`, `user_id`, `created_at`) VALUES
(561, 'items', 'c9d5cd17-fb9a-47dc-a938-17e8fba12e9c', 'update', '{\"id\":\"c9d5cd17-fb9a-47dc-a938-17e8fba12e9c\",\"sku\":\"CB-010\",\"item_name\":\"Golden oak 750 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"e11d2b56-f508-49de-ad55-925461cf0900\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"1100.00\",\"selling_price\":\"1200.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":24,\"current_stock\":\"2.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 15:36:02\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 13:29:59\"}', '{\"id\":\"c9d5cd17-fb9a-47dc-a938-17e8fba12e9c\",\"sku\":\"GO-010\",\"item_name\":\"Golden Oak Full\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"1100.00\",\"selling_price\":\"1200.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"24\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:58:44'),
(562, 'items', '9e2df543-8630-4d81-b273-1cd77a32ae65', 'update', '{\"id\":\"9e2df543-8630-4d81-b273-1cd77a32ae65\",\"sku\":\"I-00012\",\"item_name\":\"OD 750 ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"barcode\":\"\",\"bottle_size_ml\":\"750.00\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":\"\",\"units_per_case\":12,\"cost_price\":\"2683.50\",\"selling_price\":\"2850.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":10,\"reorder_qty\":0,\"current_stock\":\"4.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-07-17 20:24:16\"}', '{\"id\":\"9e2df543-8630-4d81-b273-1cd77a32ae65\",\"sku\":\"OD-00012\",\"item_name\":\"OD Full\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"750.00\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"2683.50\",\"selling_price\":\"2850.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"0\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:59:03'),
(563, 'items', 'c0fd81ec-d2b1-4123-bfc2-13b0fc6ab663', 'update', '{\"id\":\"c0fd81ec-d2b1-4123-bfc2-13b0fc6ab663\",\"sku\":\"I-00007\",\"item_name\":\"Gurkhas & Guns 750ml\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":null,\"barcode\":null,\"bottle_size_ml\":null,\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"description\":null,\"units_per_case\":null,\"cost_price\":\"2625.00\",\"selling_price\":\"0.00\",\"tax_rate\":\"13.00\",\"tax_id\":\"9b1656e9-ec64-40ab-b7a8-da784752d6a3\",\"reorder_level\":null,\"reorder_qty\":null,\"current_stock\":\"0.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-10 19:56:00\",\"is_deleted\":0,\"updated_at\":\"2026-05-10 19:56:00\"}', '{\"id\":\"c0fd81ec-d2b1-4123-bfc2-13b0fc6ab663\",\"sku\":\"GNG-00007\",\"item_name\":\"Gurkhas & Guns 750ml\",\"item_category\":\"f4015fda-14e6-405c-8d23-9228975eb6e8\",\"brand\":\"\",\"unit_type\":\"e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0\",\"bottle_size_ml\":\"750\",\"units_per_case\":\"12\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"2625.00\",\"selling_price\":\"2800\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"1\",\"description\":\"\",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 14:59:46'),
(564, 'items', '0481f181-7d31-4f6b-95d7-a8c3956acd0f', 'update', '{\"id\":\"0481f181-7d31-4f6b-95d7-a8c3956acd0f\",\"sku\":\"IMP-8E1B2EC4\",\"item_name\":\"Plastic Bags\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":null,\"barcode\":null,\"bottle_size_ml\":null,\"unit_type\":\"2795353a-e3db-436a-adaa-8f4b60cddb50\",\"description\":null,\"units_per_case\":null,\"cost_price\":\"266.67\",\"selling_price\":\"0.00\",\"tax_rate\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"reorder_level\":null,\"reorder_qty\":null,\"current_stock\":\"3.0000\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\",\"inventory_account_id\":\"acc-1200\",\"is_active\":1,\"status_id\":\"d09f7e54-9661-4a8d-8a06-30545e0e0106\",\"created_at\":\"2026-05-13 17:09:32\",\"is_deleted\":0,\"updated_at\":\"2026-07-19 20:46:25\"}', '{\"id\":\"0481f181-7d31-4f6b-95d7-a8c3956acd0f\",\"sku\":\"IMP-8E1B2EC4\",\"item_name\":\"Plastic Bags\",\"item_category\":\"2ae5110e-1887-4079-8d5b-b7355d406691\",\"brand\":\"\",\"unit_type\":\"2795353a-e3db-436a-adaa-8f4b60cddb50\",\"bottle_size_ml\":\"\",\"units_per_case\":\"\",\"barcode\":\"\",\"is_active\":\"1\",\"cost_price\":\"266.67\",\"selling_price\":\"0.00\",\"tax_id\":\"0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa\",\"tax_rate\":\"0.0000\",\"reorder_level\":\"10\",\"reorder_qty\":\"\",\"description\":\"This item is free items \",\"inventory_account_id\":\"acc-1200\",\"cogs_account_id\":\"acc-5100\",\"income_account_id\":\"acc-4100\"}', 'usr-admin-001', '2026-07-19 15:02:13'),
(565, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:03:21\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:03:21'),
(566, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:03:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:03:40'),
(567, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:03:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:03:40'),
(568, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:03:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:03:42'),
(569, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:04:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:04:19'),
(570, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:04:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:04:35'),
(571, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:04:51\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:04:51'),
(572, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:04:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:04:56'),
(573, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 17:05:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:05:00'),
(574, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 17:05:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:05:48'),
(575, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 17:06:25\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:06:25'),
(576, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:07:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:07:06'),
(577, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:07:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:07:32'),
(578, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:07:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:07:49'),
(579, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:08:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:08:01'),
(580, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:08:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:08:10'),
(581, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 17:08:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:08:53'),
(582, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 17:09:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:09:05'),
(583, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 17:09:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:09:13'),
(584, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-19 17:09:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:09:16'),
(585, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-19 17:10:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:10:05'),
(586, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-19 17:10:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:10:17'),
(587, 'system_navigation', 'reports/pos_summary', '', NULL, '{\"page\":\"reports\\/pos_summary\",\"accessed_at\":\"2026-07-19 17:14:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:14:38'),
(588, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:26:02\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:26:02'),
(589, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:30:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:30:38'),
(590, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:30:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:30:41'),
(591, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:37:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:37:54'),
(592, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 17:38:25\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:38:25'),
(593, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 17:38:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:38:36'),
(594, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 17:38:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:38:59'),
(595, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 17:39:08\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:39:08'),
(596, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 17:39:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:39:24'),
(597, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-19 17:39:30\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:39:30'),
(598, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-19 17:39:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:39:33'),
(599, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-19 17:39:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:39:39'),
(600, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:40:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:40:10'),
(601, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:40:25\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:40:25'),
(602, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:41:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:41:12'),
(603, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:42:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:42:34'),
(604, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:43:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:43:39'),
(605, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:43:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:43:42'),
(606, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:43:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:43:56'),
(607, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:44:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:44:07'),
(608, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 17:44:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:44:24'),
(609, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:47:20\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:47:20'),
(610, 'system_navigation', 'reports/sales/register', '', NULL, '{\"page\":\"reports\\/sales\\/register\",\"accessed_at\":\"2026-07-19 17:47:26\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:47:26'),
(611, 'transaction_headers', '19e8e0a8-4074-4ae2-8e97-8c409b52f162', 'delete', '{\"id\":\"19e8e0a8-4074-4ae2-8e97-8c409b52f162\",\"txn_number\":\"POS-PAY-20260719\",\"txn_type\":\"customer_payment\",\"txn_date\":\"2026-07-19\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"posted\",\"reference_number\":\"\",\"memo\":\"\",\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-19 12:11:46\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":\"64e084cd-4fdd-409b-9137-56e30c685640\",\"party_type\":\"customer\",\"net_amount\":\"3425.02\",\"updated_at\":\"2026-07-19 21:14:55\",\"source\":null,\"is_readonly\":0,\"is_locked\":0}', '[]', 'usr-admin-001', '2026-07-19 15:48:41'),
(612, 'transaction_headers', 'f558e762-5097-4d32-a731-9b615dd98e0a', 'delete', '{\"id\":\"f558e762-5097-4d32-a731-9b615dd98e0a\",\"txn_number\":\"POS-SUM-20260719\",\"txn_type\":\"customer_invoice\",\"txn_date\":\"2026-07-19\",\"fiscal_year\":2026,\"fiscal_month\":7,\"fiscal_period\":\"2026-07\",\"status\":\"open\",\"reference_number\":null,\"memo\":\"\",\"created_by\":\"usr-admin-001\",\"approved_by\":null,\"created_at\":\"2026-07-19 12:11:46\",\"posted_at\":null,\"is_deleted\":0,\"party_id\":\"64e084cd-4fdd-409b-9137-56e30c685640\",\"party_type\":\"customer\",\"net_amount\":\"3425.02\",\"updated_at\":\"2026-07-19 21:33:37\",\"source\":null,\"is_readonly\":0,\"is_locked\":0}', '[]', 'usr-admin-001', '2026-07-19 15:49:01'),
(613, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 17:49:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:49:05'),
(614, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:49:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:49:48'),
(615, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:50:23\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:50:23'),
(616, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:50:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:50:35'),
(617, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:50:46\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:50:46'),
(618, 'system_navigation', 'reports/financial/equity_statement', '', NULL, '{\"page\":\"reports\\/financial\\/equity_statement\",\"accessed_at\":\"2026-07-19 17:52:23\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:23'),
(619, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:52:43\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:43'),
(620, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:52:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:44'),
(621, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:52:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:57'),
(622, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:52:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:57'),
(623, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:52:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:58'),
(624, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:52:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:58'),
(625, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:52:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:58'),
(626, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:52:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:52:58'),
(627, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:53:03\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:53:03'),
(628, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:53:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:53:09'),
(629, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:53:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:53:38'),
(630, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:53:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:53:39'),
(631, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:56:24\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:56:24'),
(632, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:56:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:56:29'),
(633, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-19 17:57:11\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:57:11'),
(634, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 17:57:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 15:57:19'),
(635, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-19 18:02:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:02:56'),
(636, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-19 18:02:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:02:58'),
(637, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-19 18:03:03\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:03:03'),
(638, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-19 18:03:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:03:07'),
(639, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-19 18:03:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:03:15'),
(640, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-19 18:03:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:03:29'),
(641, 'system_navigation', 'reports/financial/equity_statement', '', NULL, '{\"page\":\"reports\\/financial\\/equity_statement\",\"accessed_at\":\"2026-07-19 18:03:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:03:38'),
(642, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-19 18:03:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:03:44'),
(643, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 18:03:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:03:56'),
(644, 'system_navigation', 'reports/sales/by_item', '', NULL, '{\"page\":\"reports\\/sales\\/by_item\",\"accessed_at\":\"2026-07-19 18:05:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:05:42'),
(645, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 18:05:51\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:05:51'),
(646, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 18:07:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:07:27'),
(647, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 18:10:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:10:27'),
(648, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-19 18:10:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:10:32'),
(649, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 18:11:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:11:05'),
(650, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 18:12:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:12:06'),
(651, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 18:14:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:14:09'),
(652, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 18:17:07\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:17:07'),
(653, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-19 18:17:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-19 16:17:17'),
(654, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 09:22:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:22:48'),
(655, 'system_navigation', 'reports/sales/top_profit_items', '', NULL, '{\"page\":\"reports\\/sales\\/top_profit_items\",\"accessed_at\":\"2026-07-20 09:23:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:23:39'),
(656, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-20 09:24:36\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:24:36'),
(657, 'system_navigation', 'reports/financial/balance_sheet', '', NULL, '{\"page\":\"reports\\/financial\\/balance_sheet\",\"accessed_at\":\"2026-07-20 09:24:42\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:24:42'),
(658, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-20 09:24:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:24:54'),
(659, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-20 09:24:58\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:24:58'),
(660, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-20 09:25:09\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:25:09'),
(661, 'system_navigation', 'reports/financial/daily_profit', '', NULL, '{\"page\":\"reports\\/financial\\/daily_profit\",\"accessed_at\":\"2026-07-20 09:25:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:25:13'),
(662, 'system_navigation', 'reports/financial/trial_balance', '', NULL, '{\"page\":\"reports\\/financial\\/trial_balance\",\"accessed_at\":\"2026-07-20 09:25:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:25:19'),
(663, 'system_navigation', 'reports/inventory/stock_summary', '', NULL, '{\"page\":\"reports\\/inventory\\/stock_summary\",\"accessed_at\":\"2026-07-20 09:25:33\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:25:33'),
(664, 'system_navigation', 'reports/purchases/by_vendor', '', NULL, '{\"page\":\"reports\\/purchases\\/by_vendor\",\"accessed_at\":\"2026-07-20 09:25:44\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:25:44'),
(665, 'system_navigation', 'reports/sales/by_customer', '', NULL, '{\"page\":\"reports\\/sales\\/by_customer\",\"accessed_at\":\"2026-07-20 09:25:56\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:25:56'),
(666, 'system_navigation', 'reports/customers/receivable_aging', '', NULL, '{\"page\":\"reports\\/customers\\/receivable_aging\",\"accessed_at\":\"2026-07-20 09:26:02\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:26:02'),
(667, 'system_navigation', 'reports/vat/sales_register', '', NULL, '{\"page\":\"reports\\/vat\\/sales_register\",\"accessed_at\":\"2026-07-20 09:26:10\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:26:10'),
(668, 'system_navigation', 'reports/vat/purchase_register', '', NULL, '{\"page\":\"reports\\/vat\\/purchase_register\",\"accessed_at\":\"2026-07-20 09:26:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:26:12'),
(669, 'system_navigation', 'reports/vendors/payable_aging', '', NULL, '{\"page\":\"reports\\/vendors\\/payable_aging\",\"accessed_at\":\"2026-07-20 09:26:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:26:17'),
(670, 'system_navigation', 'reports/purchases/by_vendor', '', NULL, '{\"page\":\"reports\\/purchases\\/by_vendor\",\"accessed_at\":\"2026-07-20 09:26:23\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:26:23'),
(671, 'system_navigation', 'reports/purchases/by_item', '', NULL, '{\"page\":\"reports\\/purchases\\/by_item\",\"accessed_at\":\"2026-07-20 09:26:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:26:27'),
(672, 'system_navigation', 'reports/inventory/urgent_buy', '', NULL, '{\"page\":\"reports\\/inventory\\/urgent_buy\",\"accessed_at\":\"2026-07-20 09:26:51\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:26:51'),
(673, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 09:27:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:27:54'),
(674, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 09:34:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:34:15'),
(675, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:34:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:34:35'),
(676, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:34:41\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:34:41'),
(677, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:38:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:38:04'),
(678, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:39:35\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:39:35'),
(679, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:39:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:39:49'),
(680, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:42:52\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:42:52'),
(681, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:44:48\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:44:48'),
(682, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:45:25\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:45:25'),
(683, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:46:16\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:46:16'),
(684, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:51:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:51:01'),
(685, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 09:51:40\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 07:51:40'),
(686, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 10:03:53\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 08:03:53'),
(687, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 10:04:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 08:04:04');
INSERT INTO `audit_logs` (`id`, `table_name`, `record_id`, `action`, `old_values`, `new_values`, `user_id`, `created_at`) VALUES
(688, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 10:04:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 08:04:49'),
(689, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 10:04:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 08:04:54'),
(690, 'system_navigation', 'reports/financial/general_ledger', '', NULL, '{\"page\":\"reports\\/financial\\/general_ledger\",\"accessed_at\":\"2026-07-20 10:09:30\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 08:09:30'),
(691, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:47:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:47:29'),
(692, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:48:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:48:01'),
(693, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:48:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:48:01'),
(694, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:49:49\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:49:49'),
(695, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:50:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:50:17'),
(696, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:50:29\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:50:29'),
(697, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:51:34\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:51:34'),
(698, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:57:05\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:57:05'),
(699, 'system_navigation', 'reports/financial/equity_statement', '', NULL, '{\"page\":\"reports\\/financial\\/equity_statement\",\"accessed_at\":\"2026-07-20 12:57:13\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:57:13'),
(700, 'system_navigation', 'reports/financial/equity_statement', '', NULL, '{\"page\":\"reports\\/financial\\/equity_statement\",\"accessed_at\":\"2026-07-20 12:57:50\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:57:50'),
(701, 'system_navigation', 'reports/financial/equity_statement', '', NULL, '{\"page\":\"reports\\/financial\\/equity_statement\",\"accessed_at\":\"2026-07-20 12:58:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:58:57'),
(702, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-20 12:59:03\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:59:03'),
(703, 'system_navigation', 'reports/financial/income_statement', '', NULL, '{\"page\":\"reports\\/financial\\/income_statement\",\"accessed_at\":\"2026-07-20 12:59:19\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:59:19'),
(704, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 12:59:38\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 10:59:38'),
(705, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 13:00:04\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:00:04'),
(706, 'system_navigation', 'reports/financial/comparative_income', '', NULL, '{\"page\":\"reports\\/financial\\/comparative_income\",\"accessed_at\":\"2026-07-20 13:00:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:00:06'),
(707, 'system_navigation', 'reports/financial/comparative_income', '', NULL, '{\"page\":\"reports\\/financial\\/comparative_income\",\"accessed_at\":\"2026-07-20 13:01:12\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:01:12'),
(708, 'system_navigation', 'reports/financial/cash_book', '', NULL, '{\"page\":\"reports\\/financial\\/cash_book\",\"accessed_at\":\"2026-07-20 13:01:39\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:01:39'),
(709, 'system_navigation', 'reports/financial/cash_book', '', NULL, '{\"page\":\"reports\\/financial\\/cash_book\",\"accessed_at\":\"2026-07-20 13:02:00\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:02:00'),
(710, 'system_navigation', 'reports/financial/cash_book', '', NULL, '{\"page\":\"reports\\/financial\\/cash_book\",\"accessed_at\":\"2026-07-20 13:02:01\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:02:01'),
(711, 'system_navigation', 'reports/financial/cash_book', '', NULL, '{\"page\":\"reports\\/financial\\/cash_book\",\"accessed_at\":\"2026-07-20 13:02:27\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:02:27'),
(712, 'system_navigation', 'reports/financial/cash_book', '', NULL, '{\"page\":\"reports\\/financial\\/cash_book\",\"accessed_at\":\"2026-07-20 13:02:45\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:02:45'),
(713, 'system_navigation', 'reports/financial/cash_book', '', NULL, '{\"page\":\"reports\\/financial\\/cash_book\",\"accessed_at\":\"2026-07-20 13:02:59\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:02:59'),
(714, 'system_navigation', 'reports/financial/cash_book', '', NULL, '{\"page\":\"reports\\/financial\\/cash_book\",\"accessed_at\":\"2026-07-20 13:03:06\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:03:06'),
(715, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 13:03:15\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:03:15'),
(716, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 13:06:18\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:06:18'),
(717, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 13:08:17\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:08:17'),
(718, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 13:09:32\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:09:32'),
(719, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 13:13:54\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:13:54'),
(720, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 13:13:57\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:13:57'),
(721, 'system_navigation', 'home', '', NULL, '{\"page\":\"home\",\"accessed_at\":\"2026-07-20 13:14:02\",\"ip\":\"::1\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/150.0.0.0 Safari\\/537.36\"}', 'usr-admin-001', '2026-07-20 11:14:02');

-- --------------------------------------------------------

--
-- Table structure for table `cash_denominations`
--

CREATE TABLE `cash_denominations` (
  `id` varchar(36) NOT NULL,
  `header_id` varchar(36) NOT NULL,
  `denomination_date` date NOT NULL,
  `denomination_type` enum('opening','closing','mid_day') NOT NULL,
  `note_1000` int(11) NOT NULL,
  `note_500` int(11) NOT NULL,
  `note_100` int(11) NOT NULL,
  `note_50` int(11) NOT NULL,
  `note_20` int(11) NOT NULL,
  `note_10` int(11) NOT NULL,
  `coin_5` int(11) NOT NULL,
  `coin_2` int(11) NOT NULL,
  `coin_1` int(11) NOT NULL,
  `total_cash` decimal(14,2) NOT NULL,
  `system_cash_balance` decimal(14,2) NOT NULL,
  `difference` decimal(14,2) NOT NULL,
  `counted_by` varchar(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cash_denominations`
--

INSERT INTO `cash_denominations` (`id`, `header_id`, `denomination_date`, `denomination_type`, `note_1000`, `note_500`, `note_100`, `note_50`, `note_20`, `note_10`, `coin_5`, `coin_2`, `coin_1`, `total_cash`, `system_cash_balance`, `difference`, `counted_by`) VALUES
('4fa231f1-db08-48d1-b210-b8d989589f7a', '42397e38-06a7-41c4-b1ee-9470f1c137e8', '2026-07-18', 'opening', 3, 4, 25, 79, 60, 55, 79, 0, 0, 13595.00, 0.00, 0.00, 'usr-admin-001'),
('b5f19a5f-d597-4e2a-9525-4ba160bcc2e7', 'd7efebb8-20c4-47e6-a841-aff97ea5ecd7', '2026-07-20', 'opening', 5, 5, 11, 72, 63, 58, 80, 0, 0, 14440.00, 0.00, 0.00, 'usr-admin-001');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` varchar(36) NOT NULL,
  `customer_code` varchar(20) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `customer_type` enum('retail','wholesale','bar','hotel') NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `receivable_account_id` varchar(36) NOT NULL,
  `credit_limit` decimal(14,2) DEFAULT NULL,
  `payment_terms_days` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `customer_code`, `full_name`, `customer_type`, `phone`, `email`, `pan_number`, `receivable_account_id`, `credit_limit`, `payment_terms_days`, `is_active`, `created_at`, `is_deleted`, `updated_at`) VALUES
('030e6c08-51b5-4a1e-b35a-a16514a36485', 'C-00016', 'Bhada', 'retail', '', '', '', 'acc-1100', 0.00, 5, 1, '2026-05-10 13:31:35', 0, '2026-07-19 09:37:46'),
('05d684a2-bc82-48c5-89b9-f4c54b646592', 'C-00021', 'Arunima Guffadi', 'retail', '', '', '', 'acc-1100', 0.00, 0, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:02:38'),
('0a2ccbe3-0c9a-4f95-b0b2-fbb77ab2bb4c', 'C-00022', 'sanjay', 'retail', '9841487653', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('264de247-d6e6-4e02-b85f-29055e2d233a', 'C-00023', 'Ganesh subba', 'retail', '9841263832', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('29d99478-35df-4765-ad64-7699a5ed2d16', 'C-00024', 'Organic', 'retail', '9849230754', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('2fb5ae37-2432-41bb-943d-8fb7c5bc6928', 'C-00025', 'Whole seller madhise', 'retail', '', '', '', 'acc-1100', 0.00, 0, 0, '2026-05-10 13:31:35', 0, '2026-07-18 14:02:49'),
('38c6d432-e260-4b2b-bd7d-4ed5a6411572', 'C-00026', 'Rum Lane dai', 'retail', '', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:03:06'),
('3f369150-78d9-4f44-87f4-3631ab6dcfd7', 'C-00027', 'Pachadi Hotel', 'retail', '', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:03:02'),
('4454affb-caea-4126-a9a4-8f9230e3a624', 'C-00028', 'Bhena', 'retail', '', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:02:58'),
('48e56ded-e263-41de-968d-f134b7c22deb', 'C-00029', 'Sri sana store', 'retail', '9818083683', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('4cd924d9-6503-4acf-9c7b-25d56561705d', 'C-00030', 'Furniture Choro', 'retail', '', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:03:29'),
('5871704a-7f30-473c-8933-c8877c122298', 'C-00031', 'jeet bahadur karki', 'retail', '9849779808', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('599abbd6-6f76-4d74-8618-14feed600342', 'C-00032', 'Gurkha Cafe', 'retail', '9768367510', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('621d4fb6-1fc5-4086-9848-15bc9514a165', 'C-00036', 'Mathi ko manche', 'retail', '', '', '', 'acc-1100', 0.00, 0, 0, '2026-06-11 14:58:54', 0, '2026-07-18 14:02:30'),
('64e084cd-4fdd-409b-9137-56e30c685640', 'C-00002', 'Walk IN', 'retail', '', '', '', 'acc-1100', 0.00, 0, 1, '2026-05-04 10:35:37', 0, '2026-05-04 10:35:37'),
('66b4e291-c205-4921-bf28-4ac558203d7a', 'C-00033', 'Dhup Sahu', 'retail', '', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:03:34'),
('670300dd-a714-4584-a7df-d63be456ec77', 'C-00034', 'Blue Bird new', 'retail', '', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:03:41'),
('7bc8fae8-ff58-4575-8f10-7ab923c364d3', 'C-00020', 'Masu Pasal', 'retail', '', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:03:11'),
('91858b7d-b4cd-46a0-b82c-2c9181ad7dc4', 'C-00001', 'Da junction', 'bar', '', '', '11220011', 'acc-1100', 25000.00, 7, 0, '2026-05-03 15:35:45', 0, '2026-07-18 14:01:54'),
('92017a9b-1dc6-43e1-909b-192ee73c0d68', 'C-00019', 'Da junction jadibuti', 'retail', '9843549228', '', '', 'acc-1100', 0.00, 0, 0, '2026-05-10 13:31:35', 0, '2026-07-18 14:01:49'),
('9963d282-c5f6-4381-a526-058794ff941c', 'C-00011', 'Simran Hotel', 'retail', '9766548150', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('999a3e77-589d-41d0-9a5d-cb7633fdc647', 'C-00010', 'Pravin Giri (Golden Oak)', 'retail', '9828163515', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('a918b35b-cdc4-4ae6-99d1-c8096b3eb151', 'C-00012', 'ChiyaPul', 'retail', '9865407700', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('adeb9aa7-c0d0-4007-b1a9-5ff44a6f1c07', 'C-00013', 'Maila', 'retail', '9808073793', '', '', 'acc-1100', 0.00, 0, 0, '2026-05-10 13:31:35', 0, '2026-05-23 14:07:56'),
('b589663e-0f63-48e1-a348-20fdc93c139d', 'C-00014', 'sunil bhai', 'retail', '9767991897', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('c666d6db-baa6-4692-810d-e23509f4e1c5', 'C-00015', 'Upendra saha', 'retail', '9851177615', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('d3d33a35-77d5-409e-9637-51f916f2a251', 'C-00017', 'Blue Bird', 'retail', '9841137355', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 1, '2026-07-18 14:03:49'),
('d51e8eea-3499-4638-90e1-17063a84ade8', 'C-00018', 'prabesh khanal', 'retail', '9767419708', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('e4098426-0a3c-4fca-a553-cb44d78f6cb9', 'C-00035', 'krishna highlinder', 'retail', '9823236246', NULL, NULL, 'acc-1100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12');

-- --------------------------------------------------------

--
-- Table structure for table `customer_invoices`
--

CREATE TABLE `customer_invoices` (
  `id` varchar(36) NOT NULL,
  `header_id` varchar(36) NOT NULL,
  `customer_id` varchar(36) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL,
  `discount_amount` decimal(14,2) NOT NULL,
  `tax_amount` decimal(14,2) NOT NULL,
  `total_amount` decimal(14,2) NOT NULL,
  `amount_paid` decimal(14,2) NOT NULL,
  `balance_due` decimal(14,2) NOT NULL,
  `payment_status` enum('unpaid','partial','paid') NOT NULL,
  `sale_type` enum('cash','credit') NOT NULL,
  `created_by` varchar(36) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_invoices`
--

INSERT INTO `customer_invoices` (`id`, `header_id`, `customer_id`, `invoice_date`, `due_date`, `invoice_number`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `amount_paid`, `balance_due`, `payment_status`, `sale_type`, `created_by`) VALUES
('05b48964-be4b-45d8-b771-e02f2ecb8012', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', '64e084cd-4fdd-409b-9137-56e30c685640', '2026-07-17', '2026-08-01', 'SI-00001', 5170.00, 0.00, 0.00, 5170.00, 5170.00, 0.00, 'paid', 'credit', NULL),
('2466a00e-b14e-48fa-8601-cd20970d37a9', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', '64e084cd-4fdd-409b-9137-56e30c685640', '2026-07-20', '2026-07-20', 'INV-POS-20260720', 350.00, 30.00, 0.00, 320.00, 320.00, 0.00, 'paid', 'cash', NULL),
('4e8bbe92-57c8-4843-b200-bbb782a108fc', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', '64e084cd-4fdd-409b-9137-56e30c685640', '2026-07-17', '2026-07-17', 'POS-SUM-20260717', 7890.00, 45.00, 0.00, 7845.00, 7845.00, 0.00, 'paid', 'cash', NULL),
('50a9a93b-8fa3-4884-8c85-814d603691b1', 'f558e762-5097-4d32-a731-9b615dd98e0a', '64e084cd-4fdd-409b-9137-56e30c685640', '2026-07-19', '2026-07-19', 'POS-SUM-20260719-DEL-acf062a7', 3460.02, 35.00, 0.00, 3425.02, 0.00, 3425.02, 'unpaid', 'cash', NULL),
('67d2599b-f50d-4680-94e1-95ce850fc725', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'c666d6db-baa6-4692-810d-e23509f4e1c5', '2026-07-18', '2026-08-02', 'SI-00002', 940.00, 0.00, 0.00, 940.00, 0.00, 940.00, 'unpaid', 'credit', NULL),
('8c571a01-89b1-4598-a7d0-e2e60dffae65', 'b4946e1c-3bb5-4340-b266-cc042370bb1f', '030e6c08-51b5-4a1e-b35a-a16514a36485', '2026-07-18', '2026-07-18', 'POS-SUM-20260718-DEL-66d9d116', 3000.00, 0.00, 390.00, 3390.00, -1840.00, 5230.00, 'unpaid', 'cash', NULL),
('960eef2c-d27d-4887-ade0-5c1b6be449ee', 'a2648690-e770-43e7-9acb-9c6654dad464', '48e56ded-e263-41de-968d-f134b7c22deb', '2026-07-15', '2026-08-03', 'SI-00004-DEL-d17b5f9a', 1550.00, 0.00, 0.00, 1550.00, 0.00, 1550.00, 'unpaid', 'credit', NULL),
('a8db8688-168a-4791-b864-e2f142a0e24a', 'c88a9f13-93bd-4be6-9669-ae18cd279955', '64e084cd-4fdd-409b-9137-56e30c685640', '2026-07-16', '2026-07-16', 'POS-SUM-20260716', 9115.00, 195.00, 0.00, 8920.00, 8920.00, 0.00, 'paid', 'cash', NULL),
('ab28f23e-7941-45dd-9698-1fe91056224f', 'f3a78934-2237-4c1d-b763-49b3aa300be5', '64e084cd-4fdd-409b-9137-56e30c685640', '2026-07-19', '2026-07-19', 'INV-POS-20260719', 810.00, 5.00, 0.00, 805.00, 805.00, 0.00, 'paid', 'cash', NULL),
('b6da474c-c3ef-41c9-897d-a4a7c7b72305', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', '030e6c08-51b5-4a1e-b35a-a16514a36485', '2026-07-18', '2026-08-02', 'SI-00003', 715.00, 0.00, 0.00, 715.00, 0.00, 715.00, 'unpaid', 'credit', NULL),
('d3baba01-4d1e-4586-bfb4-06955c823027', '99ef144b-131a-4762-8f41-2547d67a71b0', '64e084cd-4fdd-409b-9137-56e30c685640', '2026-07-18', '2026-07-18', 'POS-SUM-20260718', 4475.00, 0.00, 0.00, 4475.00, 4475.00, 0.00, 'paid', 'cash', NULL),
('d58c645b-d852-4591-8f2b-baf1f3ec6dbc', '1fcbec29-de11-4e6a-8bed-589b251a75b3', '64e084cd-4fdd-409b-9137-56e30c685640', '2026-07-18', '2026-07-18', 'POS-20260718-3289-DEL-e46db66d', 20.00, 0.00, 0.00, 20.00, 0.00, 20.00, 'unpaid', 'cash', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` varchar(36) NOT NULL,
  `header_id` varchar(36) NOT NULL,
  `expense_account_id` varchar(36) NOT NULL,
  `paid_from_account_id` varchar(36) NOT NULL,
  `vendor_id` varchar(36) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `tax_amount` decimal(14,2) NOT NULL,
  `expense_category` enum('utilities','rent','salaries','transport','maintenance','marketing','other') NOT NULL,
  `expense_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `header_id`, `expense_account_id`, `paid_from_account_id`, `vendor_id`, `description`, `amount`, `tax_amount`, `expense_category`, `expense_date`) VALUES
('9cc7e674-5942-47cd-98a1-9ae6b0ff997e', '2e0536d3-1fc0-496e-8ab1-62010c9d0a39', 'acc-6170', 'acc-1010', NULL, '', 130.00, 0.00, 'other', '2026-07-19');

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `id` varchar(36) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `item_category` varchar(50) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `bottle_size_ml` decimal(8,2) DEFAULT NULL,
  `unit_type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `units_per_case` int(11) DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL,
  `selling_price` decimal(12,2) NOT NULL,
  `tax_rate` decimal(5,2) NOT NULL,
  `tax_id` varchar(36) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT NULL,
  `reorder_qty` int(11) DEFAULT NULL,
  `current_stock` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `cogs_account_id` varchar(36) NOT NULL,
  `income_account_id` varchar(36) NOT NULL,
  `inventory_account_id` varchar(36) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `status_id` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`id`, `sku`, `item_name`, `item_category`, `brand`, `barcode`, `bottle_size_ml`, `unit_type`, `description`, `units_per_case`, `cost_price`, `selling_price`, `tax_rate`, `tax_id`, `reorder_level`, `reorder_qty`, `current_stock`, `cogs_account_id`, `income_account_id`, `inventory_account_id`, `is_active`, `status_id`, `created_at`, `is_deleted`, `updated_at`) VALUES
('004897c6-8a0f-446c-acc1-81a8cc6ce89a', 'CB-045', 'Sprite 250 ml', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 43.00, 50.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 2, -1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('0481f181-7d31-4f6b-95d7-a8c3956acd0f', 'IMP-8E1B2EC4', 'Plastic Bags', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 0.00, '2795353a-e3db-436a-adaa-8f4b60cddb50', 'This item is free items ', 0, 266.67, 0.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 3.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-13 11:24:32', 0, '2026-07-19 15:02:13'),
('06a7cded-5f88-4574-9f02-e310f54f568f', 'I-00005', 'Coke 1 ltr', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 135.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:10:59', 0, '2026-07-15 13:45:53'),
('0a804177-2745-429a-b251-81d13c9215b5', 'CB-038', 'Shikher beer', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 12.85, 290.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('0c31b154-be18-4c14-a0c1-c88db30db2d1', 'CB-039', 'Dew 250 ml', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', NULL, -33.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 45.83, 50.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 2, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('11966424-be83-4fb5-b7e1-57992e60e22c', 'I-00025', 'Current chips', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 38.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:01', 0, '2026-07-15 13:45:53'),
('12791f5d-6176-48a3-88d5-1a059307244c', 'CB-053', 'Arna Eight 330 ', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', '', 330.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 24, 153.13, 170.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 12, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('14d7df51-8595-4243-93bb-a9ce2c212940', 'CB-062', 'Hukka paper', 'da0ce599-2ee4-4614-886a-fea3f3bf8234', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 2.50, 5.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 10, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('173f47be-7b2c-43a7-9ba1-ea9bd4bb12a1', 'CB-056', 'Xtreme', '99e11903-0216-45e7-a233-40067e22da37', '', '', 150.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 24, 101.04, 140.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 10, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-15 13:45:53'),
('17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'CB-048', 'Gorkha can', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', '', 500.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 243.75, 275.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 12, 20.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 07:23:23'),
('194d391f-08f9-452f-bbe3-0e91bd14860f', 'I-00010', 'Lighter', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 0, 16.25, 20.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 0, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-15 13:45:53'),
('1a8166b8-3107-444c-9634-2f27df10e913', 'CB-057', 'Nepal Ice 650', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', '', 650.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 304.17, 340.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 23.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 16:16:53'),
('206202d3-8d4b-4821-9f9a-f7a8570b9957', 'I-00006', 'Coke 1.5 ltr', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 185.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:10:59', 0, '2026-07-15 13:45:53'),
('2149eeae-1056-4160-bf17-cb71cf454395', 'CB-011', 'JP chenet', '01e6a28d-9b57-437e-84f9-94a75eeb19a6', '', '', 750.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 1800.00, 2200.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 0, 0, 3.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('21ffbde0-c10c-4d8d-8286-c6c60410c547', 'I-00022', 'Double Black Label 1L', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 9246.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-15 13:45:53'),
('29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'CB-032', 'Mineral Water', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 11.00, 20.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 2, 15.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('2ecdd9cf-3a70-4f85-9562-c3c9d9b1cbc5', 'CB-041', 'OCB', '5581720a-90fe-4f2c-8cbb-56d1c7a3da56', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 69.00, 130.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('2f50885c-3771-49c0-b760-0a19c8f264d1', 'I-00011', 'Happydent', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 0, 1.00, 2.50, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-15 13:45:53'),
('2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'I-00013', 'Misc Exp', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 0.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 0, 0.00, 0.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, -1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-19 15:01:25'),
('305ddcc7-fe37-4d60-9f6d-ae92481d343c', 'CB-012', 'Manang Valley Small', '01e6a28d-9b57-437e-84f9-94a75eeb19a6', '', NULL, -2.00, 'e11d2b56-f508-49de-ad55-925461cf0900', NULL, 12, 425.00, 500.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 2.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('398e3b21-7b6d-42d6-93a7-3bde51197f8b', 'CB-061', 'Handyplast', '2ae5110e-1887-4079-8d5b-b7355d406691', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 4.00, 5.00, 13.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('39a1355a-1284-4700-854e-4ca2d796aef2', 'I-00009', 'Sprite 1 ltr', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 0.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-15 13:45:53'),
('3d0e5228-5462-4ae9-b843-51b352361479', 'I-00030', 'OD 350 ml', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 350.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 24, 1341.75, 1450.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 5, 5, 9.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, NULL, '2026-07-17 14:26:29', 0, '2026-07-20 10:51:08'),
('3f737cab-beef-4873-8d28-30f48bb20818', 'CB-042', 'Highlander Qtr', '71acc735-19e5-4a9b-9f59-7a7e54289789', '', '', 180.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 48, 258.33, 290.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 15, 9.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('40fbbd89-07a1-47e1-a405-0895aaa937cb', 'CB-060', 'Hukka Flavour', 'da0ce599-2ee4-4614-886a-fea3f3bf8234', '', '', 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 197.50, 260.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('43005fe5-c19b-4630-bb41-62436e7e25c7', 'I-00016', 'Barahsinghe Pilsner 650 ml', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', '', 650.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 356.25, 390.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 0, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-19 07:44:59'),
('4376ce7f-e0ae-498d-849d-0f0b494d84a7', 'CB-037', 'Hukka Coil', 'da0ce599-2ee4-4614-886a-fea3f3bf8234', '', NULL, -14.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 50.00, 100.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 38.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:51:30'),
('4643f5fb-f902-41df-a78d-6678ce29f009', 'I-00019', 'Somersby', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 0.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 206.25, 230.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-19 07:44:59'),
('481d462a-260b-4980-894c-8830203991bf', 'CB-036', 'Purna Sano', '2ae5110e-1887-4079-8d5b-b7355d406691', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 16.00, 20.00, 13.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('49225a12-9859-4acf-9e02-7c2f19ed4fda', 'I-00014', '8848 Full', '71acc735-19e5-4a9b-9f59-7a7e54289789', '8848 vodka', '', 750.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 2100.00, 2250.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-19 16:16:53'),
('49963ff8-5d2d-4c0d-a531-f5048e644817', 'CB-023', 'Carlsberg 650', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', 'Carlsberg ', '', 650.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 440.00, 480.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 10, 9.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('4bb831da-2e3d-4482-bd62-05df0c171742', 'CB-033', 'Mustang  Half', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 375.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 24, 509.00, 550.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 2, 5.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 07:23:29'),
('4bcc2264-5ffd-451c-b2f5-d82052704d40', 'CB-028', 'Dew 1.5 ltr', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', '', 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 175.00, 200.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('5018c912-57b9-49d5-9618-e9156f5150e5', 'CB-035', 'Highlander Half', '71acc735-19e5-4a9b-9f59-7a7e54289789', '', '', 375.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 24, 516.67, 580.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 10, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 14:53:15'),
('5599eb46-8e58-4bbc-957c-7bee386693b6', 'CB-065', 'Captain', '5581720a-90fe-4f2c-8cbb-56d1c7a3da56', '', NULL, -51.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 8.00, 10.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 119.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('5784078a-3fad-484a-a81c-32a60784be4e', 'KR-00004', 'Khukri Rum Half', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 375.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 24, 995.83, 1100.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:10:59', 0, '2026-07-19 14:56:40'),
('581f69c5-b0a8-4e0f-9d7d-43e9225a9b40', 'CB-054', 'Soju', '738b9b15-7d82-4f6e-81e0-036df7634221', 'Seol Soju', '', 300.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 30, 135.00, 170.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 10, 5.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('58704d38-49ac-4535-a4c2-344aa9acf53b', 'I-00028', 'Mustang Black Full', 'f4015fda-14e6-405c-8d23-9228975eb6e8', 'Mustang', '', 750.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 1200.00, 1400.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 3.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, NULL, '2026-07-16 12:08:09', 0, '2026-07-19 16:16:53'),
('5e21fcfb-5077-4a22-97f3-574bda1923f6', 'GO-029', 'Golden Oak Qtr', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 180.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 48, 275.00, 300.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 28.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('6495db84-108d-455f-841d-06fe0a239a12', 'I-00020', 'Frooti', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 17.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-15 13:45:53'),
('65a60e75-a453-4c11-bb40-99ef492b3dcb', 'OD-005', 'OD Qtr', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 180.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 48, 670.88, 725.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 15.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:51:08'),
('66e8bb29-94f3-4cd7-ac67-14ada9da1c4c', 'CB-040', '8848 Qtr', '71acc735-19e5-4a9b-9f59-7a7e54289789', '', '', 180.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 525.00, 570.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 10, 12.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 16:16:53'),
('6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 'CB-030', 'Divine', '01e6a28d-9b57-437e-84f9-94a75eeb19a6', '', '', 750.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 650.00, 750.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 7.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('6e362a0a-a8f2-4e9c-a490-50d52c583b12', 'I-00015', 'Kings Hill Premium White', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 756.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-05-10 14:11:00'),
('6e666f14-9cce-40f2-9ffc-d80bf985dec9', 'CB-049', 'TRP - 3', '5581720a-90fe-4f2c-8cbb-56d1c7a3da56', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 10.00, 20.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('726740ce-e0a4-4eb3-a79e-906d2db0fbee', 'I-00018', 'Sprite 1.5 ltr', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 0.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-15 13:45:53'),
('742c00bc-0e41-4714-a2ae-c873fa9a5ff9', 'CB-007', 'Chief Guest Red', '01e6a28d-9b57-437e-84f9-94a75eeb19a6', '', NULL, -2.00, 'e11d2b56-f508-49de-ad55-925461cf0900', NULL, 12, 671.00, 700.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('7515afaf-1b16-46c6-8b2b-c5e7bfcb9f11', 'CB-008', 'Chief Guest White', '01e6a28d-9b57-437e-84f9-94a75eeb19a6', '', NULL, -1.00, 'e11d2b56-f508-49de-ad55-925461cf0900', NULL, 12, 671.00, 750.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('76fb4973-11fe-4476-8e02-3545531d4bf9', 'GO-00021', 'Golden Oak Half', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 375.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 24, 548.00, 600.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 2, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-19 14:57:26'),
('7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 'CB-020', 'Sprite 2.25 ltr', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', NULL, 7.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 240.00, 250.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 2.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 15:20:41'),
('7cdc00cf-d814-4bf4-995b-f61180f604fd', 'CB-044', 'Baron Select', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', NULL, 0.00, 'e11d2b56-f508-49de-ad55-925461cf0900', NULL, 12, 263.00, 290.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('84f1e8aa-26c2-4228-844c-ff33746ad52b', 'AV-002', 'Absolute Vodka 1 ltr', '71acc735-19e5-4a9b-9f59-7a7e54289789', 'Absolute', '', 1000.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 5983.00, 6600.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('85dabd87-2e17-4fc1-8822-5837ae66021f', 'CB-018', 'Jar Water', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 400.00, '2795353a-e3db-436a-adaa-8f4b60cddb50', '', 0, 25.00, 35.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'CB-063', 'Surya', '5581720a-90fe-4f2c-8cbb-56d1c7a3da56', '', NULL, -90.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 16.10, 20.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 60, 116.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('919b9e31-52a2-48f9-8795-533b0a081663', 'CB-055', 'Arna Eight Can', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', '', 500.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 231.25, 270.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 12, 26.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('92eaee6a-2aad-47ec-949f-78cd44e8074b', 'CB-046', 'Redbull', '99e11903-0216-45e7-a233-40067e22da37', '', '', 150.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 24, 83.33, 110.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 10, 122.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 07:23:29'),
('96a4afa3-9243-4f63-acd5-107aa08d6039', 'CB-025', 'Coke 2.25 ltr', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 240.00, 250.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 15:01:25'),
('996248e3-1b15-4b68-ab39-eac57f3a71ea', 'CB-003', 'Skyy Vokda 750 ML', '71acc735-19e5-4a9b-9f59-7a7e54289789', '', '', 750.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 1800.00, 2050.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('9e2df543-8630-4d81-b273-1cd77a32ae65', 'OD-00012', 'OD Full', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 750.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 2683.50, 2850.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 4.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-19 16:16:53'),
('9e894c3b-f0ab-40bb-8d13-17dc033754d6', 'CB-059', 'Tuborg 650', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', 'Tuborg', '', 650.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 385.42, 440.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 12, 16.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('9f1a16fd-fda9-47ee-987a-b5771e6c7161', 'CB-034', 'Playing Cards', '2ae5110e-1887-4079-8d5b-b7355d406691', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 80.00, 100.00, 13.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 1, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('a48cf127-40e9-404e-a9ea-2814657da992', 'CB-064', 'Shikher Ice', '5581720a-90fe-4f2c-8cbb-56d1c7a3da56', '', '', 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 20, 13.05, 15.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 60, 320.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 07:23:29'),
('a9a48dd1-f4d8-4a88-a48d-5fc4a94faeb1', 'CB-016', 'Lays Sano', '2ae5110e-1887-4079-8d5b-b7355d406691', '', NULL, -30.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 44.00, 50.00, 13.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('ad89a514-5135-47f2-8631-9cf2e56ff9a9', 'CB-014', 'Purna thulo', 'f682d657-b67f-4b5f-9d12-8baf2b2d1647', '', '', 1000.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 24.00, 40.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-15 13:45:53'),
('b1e6e633-3a08-42d9-8da2-fe6d7acdf463', 'CB-026', 'Dew 2.25 ltr', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', '', 22500.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 240.00, 270.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 2.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 07:23:23'),
('b62807df-8203-41ba-b8a2-76960555ea39', 'I-00024', 'Canvas box', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 2750.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-05-10 14:11:00'),
('b6533a14-377b-4d29-8d77-fa4d2fe883ee', 'CB-022', 'Mustang Full', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 750.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 1016.75, 1100.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 3.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 07:23:29'),
('c0fd81ec-d2b1-4123-bfc2-13b0fc6ab663', 'GNG-00007', 'Gurkhas & Guns 750ml', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 750.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 2625.00, 2800.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 1, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-19 14:59:46'),
('c101a46e-b532-482f-aa71-cb6ce07431d3', 'CB-006', 'Red Label 1000 ml', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 1000.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 5675.00, 6400.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'I-00026', 'Nepal Ice 330', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', 'Nepal Ice', '', 330.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 24, 156.25, 180.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 20, 16.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, NULL, '2026-06-09 09:41:37', 0, '2026-07-20 07:23:29'),
('c3973817-9b13-4a7a-888c-ad920161c5ea', 'CB-021', 'Mustang Qtr', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 180.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 48, 254.19, 280.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 10, 28.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('c98dbedd-9066-4662-8d06-bad8654cf438', 'CB-051', 'Dew 500 ml', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 81.25, 90.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 2, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('c9d5cd17-fb9a-47dc-a938-17e8fba12e9c', 'GO-010', 'Golden Oak Full', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 750.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 1100.00, 1200.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 2.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 16:16:53'),
('c9feac44-4f86-4e7b-89dd-b197c6c165ef', 'CB-050', 'TRP Cone', '5581720a-90fe-4f2c-8cbb-56d1c7a3da56', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 13.00, 25.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('cab6a4ae-e151-4ce1-9f7c-f342f3d77c01', 'CB-017', 'Lays Thulo', '2ae5110e-1887-4079-8d5b-b7355d406691', '', NULL, -18.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 70.00, 80.00, 13.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('cb30eae7-fa42-4100-89f3-33da222fa8c8', 'I-00023', 'Jack Daniel\'s 1L', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 6750.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-05-10 14:11:00'),
('cc197d32-be40-4c35-aee8-b553af156838', 'I-00027', 'Mustang Black Qtr', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 180.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 48, 300.00, 340.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 12.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, NULL, '2026-07-16 11:24:17', 0, '2026-07-19 16:16:53'),
('d16048f0-158c-40ee-9f4f-e52a209d7ee2', 'CB-024', 'Gorkha 330 ', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', '', 330.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 24, 166.67, 200.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('d20f5087-48f0-4ec3-950c-d7393884aed4', 'I-00029', 'Highlander Full', '71acc735-19e5-4a9b-9f59-7a7e54289789', '', '', 750.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 1034.00, 1140.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 3.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, NULL, '2026-07-16 12:13:55', 0, '2026-07-19 16:16:53'),
('d552287e-c7e2-4b9f-83eb-223971845f00', 'I-00003', 'Glass', '2ae5110e-1887-4079-8d5b-b7355d406691', '', '', 0.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 0, 1.50, 5.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', 10, 0, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 13:31:38', 0, '2026-07-15 13:45:53'),
('d6a81088-0b74-4c44-938d-b14d5fbe5f7d', 'CB-052', 'Tuborg Can', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', NULL, 0.00, 'e11d2b56-f508-49de-ad55-925461cf0900', NULL, 12, 293.75, 330.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 12, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('da007f07-7cfa-4c99-b90a-1afab623bb63', 'CB-015', 'Manang Valley', '01e6a28d-9b57-437e-84f9-94a75eeb19a6', '', NULL, 0.00, 'e11d2b56-f508-49de-ad55-925461cf0900', NULL, 12, 808.33, 950.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 12.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 16:16:53'),
('dd1ac436-ebca-4fa2-9407-44f158256b13', 'CB-031', 'Gorkha 650', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', '', 650.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 329.17, 365.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 20, 26.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:51:08'),
('e6c62fab-60b4-411e-9c23-2a71e8c5c4a8', 'I-00017', 'Kings Hill Premium Red', '2ae5110e-1887-4079-8d5b-b7355d406691', NULL, NULL, NULL, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', NULL, NULL, 756.00, 0.00, 13.00, '9b1656e9-ec64-40ab-b7a8-da784752d6a3', NULL, NULL, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-05-10 14:11:00'),
('e88436f2-0460-408d-9494-190246334d27', 'I-00008', 'Big Master', '01e6a28d-9b57-437e-84f9-94a75eeb19a6', '', '', 750.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 625.00, 750.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 0, 17.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 14:11:00', 0, '2026-07-19 16:16:53'),
('ea56a0a1-a21a-4661-89d1-c9fbe5e21884', 'CB-019', 'Khukri Rum 180 ml', '5b907b99-9627-420c-bda6-70853db398bb', '', NULL, -1.00, 'e11d2b56-f508-49de-ad55-925461cf0900', NULL, 12, 497.92, 550.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 0, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('eaa83d6b-f225-4f6f-b9ef-c63fd82cc061', 'CB-058', 'Sagun', '584b1f1e-25b4-4d1a-83b9-b448d6a964f4', '', '', 200.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 30, 38.33, 50.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 5, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-17 15:16:58'),
('f2efc96d-487c-48b2-b00d-2b5dea038a76', 'CB-043', 'Coke 250 ml', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 43.00, 50.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 2, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59'),
('f497b9d6-6552-4a41-9b27-4c2fc43fece3', 'CB-004', 'Chivas Regal', 'f4015fda-14e6-405c-8d23-9228975eb6e8', '', '', 1000.00, 'd14f742a-cde3-4419-abf2-f229b5893983', '', 12, 6500.00, 8000.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 1.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('f51bffdf-4860-461e-ab42-cdfc4c941cb7', 'KR-013', 'Khukri Rum Full', '5b907b99-9627-420c-bda6-70853db398bb', '', '', 750.00, 'e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', '', 12, 2000.00, 2200.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 4.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('f78c3fcb-7c77-4c35-a371-72075d6f61e5', 'CB-047', 'Arna Eight 650', 'ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', '', '', 650.00, 'e11d2b56-f508-49de-ad55-925461cf0900', '', 12, 300.00, 330.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 12, 25.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-20 10:50:44'),
('f92a8d34-f7a5-4349-94a5-f886ec590c39', 'CB-027', 'Dew 1 ltr', 'a7873e69-1f4e-48d4-8183-2d88642cade0', '', NULL, 0.00, 'd14f742a-cde3-4419-abf2-f229b5893983', NULL, 12, 129.17, 140.00, 0.00, '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 10, 24, 0.0000, 'acc-5100', 'acc-4100', 'acc-1200', 1, 'd09f7e54-9661-4a8d-8a06-30545e0e0106', '2026-05-10 09:51:02', 0, '2026-07-19 07:44:59');

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` varchar(36) NOT NULL,
  `header_id` varchar(36) NOT NULL,
  `account_id` varchar(36) NOT NULL,
  `item_id` varchar(36) DEFAULT NULL,
  `entry_type` enum('debit','credit') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `memo` varchar(255) DEFAULT NULL,
  `created_by` varchar(36) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `fiscal_period` char(7) NOT NULL,
  `fiscal_year` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `party_id` varchar(36) DEFAULT NULL,
  `party_type` enum('customer','vendor','user') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `journal_entries`
--

INSERT INTO `journal_entries` (`id`, `header_id`, `account_id`, `item_id`, `entry_type`, `amount`, `memo`, `created_by`, `entry_date`, `fiscal_period`, `fiscal_year`, `created_at`, `party_id`, `party_type`) VALUES
('013605e7-ea85-418e-9287-1380ff63dc91', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'debit', 487.50, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('02587066-7a7a-419e-be52-ed771c48dec3', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', '4bb831da-2e3d-4482-bd62-05df0c171742', 'credit', 1018.00, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('02588970-11e6-4ed0-9ad6-03e4aa14993c', '2e0536d3-1fc0-496e-8ab1-62010c9d0a39', 'acc-1010', NULL, 'credit', 130.00, 'Expense EXP-00001: ', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 15:09:58', 'tea', 'user'),
('02aea2f6-ee25-4da5-879c-703a9762337b', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'debit', 2012.64, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('058bc53d-2642-4b5c-a53a-3bab89dcb5ca', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', 'b1e6e633-3a08-42d9-8da2-fe6d7acdf463', 'debit', 240.00, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('05b3879a-ca65-41e6-8381-9e9ccf2d56a8', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '1a8166b8-3107-444c-9634-2f27df10e913', 'debit', 3650.04, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('08705280-2a49-45f8-b01e-f58142ecab3a', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'bbe5c26b-091b-4b2c-939c-8a18220bcc5a', NULL, 'credit', 80506.89, 'Inventory Adj Offset CR - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('08cc8d45-c524-4620-bc11-8a1394da32ae', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', 'a48cf127-40e9-404e-a9ea-2814657da992', 'credit', 300.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('094465f5-1460-4a3a-8b4a-f177b824fead', 'bd96863a-ad0f-4bc7-8d87-174f5e20dbe8', 'acc-1020', NULL, 'debit', 710.00, 'POS Daily Payment POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 16:06:06', NULL, NULL),
('0b491217-27ab-4e6b-9a59-90303331d214', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'credit', 83.33, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('0cab7a8a-8a83-4b16-b59d-9a8d0782e969', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-1100', NULL, 'debit', 5170.00, 'Invoice SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('0ccf0580-3bcc-43c3-ba76-37e6ac724e1d', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', 'b6533a14-377b-4d29-8d77-fa4d2fe883ee', 'debit', 1016.75, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('0e078728-c5b7-4cae-80ee-0ecd9268995c', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'debit', 4067.04, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('0e4e62a0-89d3-433c-8ec0-96a62a133b2b', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1100', NULL, 'debit', 7845.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('0ee4397b-92ce-4626-af7d-ae548746a0f0', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '58704d38-49ac-4535-a4c2-344aa9acf53b', 'debit', 3600.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('0f82986e-3038-4fbb-9704-886233a5b02b', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 161.00, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('1160e8ce-5725-4caf-9509-0c68ef1bde58', '40caee36-c141-41ff-b7cd-db1d58271191', 'acc-1100', NULL, 'credit', 805.00, 'POS Daily Payment INV-POS-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 16:11:00', NULL, NULL),
('11698461-c92a-4cf0-aef2-a792dc7098f1', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'credit', 508.38, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('123031fd-bf6d-4780-aecf-94afc0085992', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '9e2df543-8630-4d81-b273-1cd77a32ae65', 'debit', 10734.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('14887c5f-5b3a-4f6f-9bca-dc75b9614f68', '5d27e5fa-2465-463b-bbd4-388435dc2a16', 'acc-2100', NULL, 'debit', 2960.01, 'Payment VPAY-00005', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 08:54:00', NULL, NULL),
('152b6ccf-fd74-448e-a69a-3bdb9577c1ab', '74b88ca2-7b1f-45d2-af55-090607b85296', 'acc-5200', NULL, 'debit', 2341.81, 'Inventory Adj Offset DR - ADJ-0002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 10:51:08', NULL, NULL),
('159ed14d-038e-42b7-a7fd-463b629bd3f8', 'opening-balances-txn-uuid', 'acc-1010', NULL, 'debit', 19118.00, 'Opening Balance for Cash on Hand', 'usr-admin-001', '2026-06-15', '2026-06', 2026, '2026-07-20 08:09:14', NULL, NULL),
('162507c6-b752-40d5-aecc-833db9da61f0', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', 'acc-5100', NULL, 'debit', 250.00, 'Daily POS Invoice COGS INV-POS-20260720', 'usr-admin-001', '2026-07-20', '2026-07', 2026, '2026-07-20 10:50:14', NULL, NULL),
('17039952-ebd8-493a-97ff-9f3bc8ffa348', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'debit', 166.66, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('1715ab82-b19f-4322-ab9b-6a29b61e9dd1', '4065ba21-c172-4bd8-8f13-e9d0867be8f4', 'acc-1100', NULL, 'credit', 2600.00, 'Payment CPAY-00002', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 06:37:29', NULL, NULL),
('1792c611-a8a5-4dbe-a163-a1bcf0fc0eab', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', '3f737cab-beef-4873-8d28-30f48bb20818', 'debit', 516.66, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('18d05ecd-1f6f-4b32-aa43-07f9d4e4665f', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'debit', 4067.04, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('19f0c092-55ff-4fbe-a555-f98c0330b96c', 'f3a6aaf2-f3fd-420c-b622-7eb0d6433fc4', 'acc-2100', NULL, 'credit', 22825.00, 'Bill VI-00007', 'usr-admin-001', '2026-07-13', '2026-07', 2026, '2026-07-19 07:46:48', NULL, NULL),
('1aa5f482-1ef9-4ea9-affb-128024453116', '161e3d0c-e842-4082-ae2c-729d5dc7e4bd', 'acc-2100', NULL, 'debit', 5220.00, 'Payment VPAY-00002', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-17 14:19:55', NULL, NULL),
('1c7162f4-5ae5-4323-b2de-cb4c859c3101', 'dc85e963-3b8d-47f3-a40d-0052832989a4', 'acc-1020', NULL, 'credit', 5500.00, 'Payment VPAY-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-17 14:40:24', NULL, NULL),
('1cffa064-5052-418c-a4b2-1e8db7f448f2', 'f4cc4cd1-1d4f-46e6-802d-ed824f6d4571', 'acc-1010', NULL, 'debit', 550.00, 'Payment CPAY-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:20:20', NULL, NULL),
('1d3aa6d9-ed9f-4959-8c0f-48762c70abc5', '6a76457e-1018-4649-930c-bf5c82e39ac4', 'acc-1020', NULL, 'credit', 115500.00, 'Payment VPAY-00003', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-17 14:39:35', NULL, NULL),
('21ba1645-db49-4ca8-b8af-8e5890d44e4f', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-1200', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 16.10, 'Inventory Out SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('21de1d2f-6e3d-4762-a2b5-a134628de50f', '40caee36-c141-41ff-b7cd-db1d58271191', 'acc-1020', NULL, 'debit', 300.00, 'Daily POS Invoice Payment INV-POS-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 16:11:00', NULL, NULL),
('221831e5-dc5e-4fc9-a381-9f3b4a927101', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', 'f51bffdf-4860-461e-ab42-cdfc4c941cb7', 'debit', 8000.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('225be33e-b896-4598-a3ef-634b70ed8989', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-5100', '1a8166b8-3107-444c-9634-2f27df10e913', 'debit', 3650.04, 'COGS SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('227f89a9-7f1e-4586-b4fe-6beaf645b40b', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'credit', 280.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('25d76187-a2b6-46d2-8449-d434e88d966b', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', '4bb831da-2e3d-4482-bd62-05df0c171742', 'debit', 509.00, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('267186e1-e336-4140-ae9f-6e2ef6dea1b9', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', 'a48cf127-40e9-404e-a9ea-2814657da992', 'credit', 450.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('2674d716-e5e3-41cc-9ba2-c37e9c5a50cf', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '2149eeae-1056-4160-bf17-cb71cf454395', 'debit', 5400.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('275b52fa-e127-494e-bdb3-7f23c3701afe', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', 'acc-4100', NULL, 'credit', 350.00, 'Daily POS Invoice Sales INV-POS-20260720', 'usr-admin-001', '2026-07-20', '2026-07', 2026, '2026-07-20 10:50:14', NULL, NULL),
('29837842-98ac-4bb2-ae66-6b310fdebf85', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '3f737cab-beef-4873-8d28-30f48bb20818', 'debit', 774.99, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('2a96aec7-42b6-4e24-9be3-9991d6dfbb89', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'debit', 3291.70, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('2af6933e-9faf-4840-ba4e-116782841b58', '40caee36-c141-41ff-b7cd-db1d58271191', 'acc-1010', NULL, 'debit', 505.00, 'Daily POS Invoice Payment INV-POS-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 16:11:00', NULL, NULL),
('2bbb627f-3488-4ca9-b3f7-02513e2bd0e0', '74b88ca2-7b1f-45d2-af55-090607b85296', 'acc-1200', '3d0e5228-5462-4ae9-b843-51b352361479', 'debit', 1341.75, 'Inventory Adj IN - ADJ-0002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 10:51:08', NULL, NULL),
('2dddf792-d0b0-4d48-bf82-109a08e2d847', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-5100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'debit', 161.00, 'COGS SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('2eb7f105-3150-4204-92a2-eb01c1e24aed', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', 'f78c3fcb-7c77-4c35-a371-72075d6f61e5', 'debit', 7500.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('2f6f63a3-5de8-451f-a11c-5a2ce743b045', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'credit', 208.00, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('2fe40acb-2bfe-4bf2-b1d3-0270b37ccf73', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-4100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 170.00, 'Invoice SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('30bed6cf-e227-4b63-8734-d933dfd7cbc1', 'f4cc4cd1-1d4f-46e6-802d-ed824f6d4571', 'acc-1100', NULL, 'credit', 2570.00, 'Payment CPAY-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:20:20', NULL, NULL),
('31498db1-9623-45ff-8af9-7388bd08123f', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 1065.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('31e3c0b0-181e-48bc-8fc3-fed2f537cc30', 'f9143322-9f7a-4bd2-9b04-c5d0a4074eb3', 'acc-1200', 'e88436f2-0460-408d-9494-190246334d27', 'debit', 7700.04, 'Bill VI-00006', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 09:20:39', NULL, NULL),
('31f2ad03-6110-4f5a-a109-f8c2781045a7', 'dc85e963-3b8d-47f3-a40d-0052832989a4', 'acc-1010', NULL, 'credit', 12200.00, 'Payment VPAY-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-17 14:40:24', NULL, NULL),
('3224a4d8-4c6d-4ccf-ad19-fb63b6dcefb5', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 3291.70, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('33916df9-d9ca-4741-90e2-d2c3737e8889', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'debit', 177.10, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('339dabab-8dca-4ef0-8d6a-918718bd41c1', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', 'a48cf127-40e9-404e-a9ea-2814657da992', 'debit', 261.00, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('33b9d083-206d-40cc-b04f-1fa801018702', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'debit', 1600.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('33c4af90-eac5-42f5-b158-70753f73e9af', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'credit', 11.00, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('34e9e66e-2fd0-4803-85c6-874bf75c891b', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-1200', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 329.17, 'Inventory Out SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('357e4e43-e3a1-47cd-a779-ecf33953ef15', '13ef5b2a-743e-43e0-b4ce-886ff8c09e86', 'acc-1010', NULL, 'debit', 3905.00, 'Payment POS-PAY-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 10:07:48', NULL, NULL),
('360971b8-7d4a-4bbe-afa0-2faa7793fac7', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', '3f737cab-beef-4873-8d28-30f48bb20818', 'credit', 516.66, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('36cec400-d861-4a5a-9732-d45c05fc94f6', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', '4bb831da-2e3d-4482-bd62-05df0c171742', 'credit', 509.00, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('371ae627-cf53-4f7b-9611-43d88ac4a601', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '9e894c3b-f0ab-40bb-8d13-17dc033754d6', 'debit', 6166.72, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('37e9fd68-09a9-4ffd-8a83-8d917b95da8e', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '66e8bb29-94f3-4cd7-ac67-14ada9da1c4c', 'debit', 6300.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('38430933-ced6-45c2-bc99-40af471fbd0c', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'credit', 540.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('390f7bec-b8fd-4f0d-bcd1-ed429274f3f3', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '3d0e5228-5462-4ae9-b843-51b352361479', 'debit', 10734.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('39f9a768-3486-45f7-b7e6-817c6fe141a8', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'credit', 80.00, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('3c4e62b2-1694-4f58-b633-bf63e6ccd0db', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '7515afaf-1b16-46c6-8b2b-c5e7bfcb9f11', 'debit', 671.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('3cbcbb42-001c-4f7c-b05d-d2db833051eb', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 'acc-4100', NULL, 'credit', 810.00, 'Daily POS Invoice Sales INV-POS-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 16:11:00', NULL, NULL),
('3ea0a400-fbf4-4a06-9dbb-7d259777a170', '161e3d0c-e842-4082-ae2c-729d5dc7e4bd', 'acc-1020', NULL, 'credit', 5220.00, 'Payment VPAY-00002', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-17 14:19:55', NULL, NULL),
('3f460b93-867e-4bb7-923d-748770e2a1da', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'debit', 254.19, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('3ff0135b-51aa-4575-b936-f21e8a50ade2', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-4100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 360.00, 'Invoice SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('45f24b0e-87e5-4e5d-b114-3d1932112a41', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'credit', 20.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('45f862b5-40c9-4b16-a4d2-9eeb8c56fdf6', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1100', NULL, 'debit', 4475.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('47729606-b128-4f32-893d-8977d1c90494', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', 'b1e6e633-3a08-42d9-8da2-fe6d7acdf463', 'credit', 240.00, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('47d89d7d-b37b-4d2a-9718-da52f555951e', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'acc-1200', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 329.17, 'Inventory Out SI-00003', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 14:32:36', NULL, NULL),
('4c7ce77d-ce33-4f8a-9dab-721157e31057', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', '4bb831da-2e3d-4482-bd62-05df0c171742', 'credit', 1100.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('4c9416a5-9cf9-4a3e-adfc-a1bbb4739653', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'debit', 11.00, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('4d937332-69a4-4bcd-b92b-bce7b47bf010', 'eee12567-6915-4618-9c57-db11d63d30c2', 'acc-2100', NULL, 'credit', 7300.00, 'Bill VI-00004', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 08:50:03', NULL, NULL),
('4e3dbe1a-3794-44f3-b2ac-e1494051b88c', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-5100', 'e88436f2-0460-408d-9494-190246334d27', 'debit', 625.00, 'COGS SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('4e6d2ecd-8cc3-4cc0-b7cf-55182ace9cac', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'acc-5100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'debit', 329.17, 'COGS SI-00003', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 14:32:36', NULL, NULL),
('4f3bd6a8-2dfa-447e-bed2-88ecf41ad30f', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-4100', '1a8166b8-3107-444c-9634-2f27df10e913', 'credit', 3750.00, 'Invoice SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('505e6fdc-8733-4fa1-9393-8de58f6d059e', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', 'b6533a14-377b-4d29-8d77-fa4d2fe883ee', 'credit', 1100.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('5097fc53-2e99-484a-859a-2c10e76c04a4', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', 'acc-1200', NULL, 'credit', 250.00, 'Daily POS Invoice Inventory Out INV-POS-20260720', 'usr-admin-001', '2026-07-20', '2026-07', 2026, '2026-07-20 10:50:14', NULL, NULL),
('51452832-6ffb-4f28-99f3-1cbfe520f09b', '1fcbec29-de11-4e6a-8bed-589b251a75b3', 'acc-1200', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'credit', 11.00, 'POS Inventory Out POS-20260718-3289', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 10:29:04', NULL, NULL),
('52bca09b-649a-4129-89ef-6e9f457d6577', 'f558e762-5097-4d32-a731-9b615dd98e0a', 'acc-1200', NULL, 'credit', 3175.24, 'POS Daily Inventory Out POS-SUM-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 15:29:55', NULL, NULL),
('5324a1f0-b663-478f-bfd3-d5761f0c40cc', 'a2648690-e770-43e7-9acb-9c6654dad464', 'acc-1100', NULL, 'debit', 1550.00, 'Invoice SI-00004', 'usr-admin-001', '2026-07-15', '2026-07', 2026, '2026-07-19 07:27:54', NULL, NULL),
('53b40a52-37fa-4d90-ad6c-6c478c0cdb32', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'acc-1200', '0481f181-7d31-4f6b-95d7-a8c3956acd0f', 'debit', 800.01, 'Bill VI-00005', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-19 15:01:25', NULL, NULL),
('53e570f4-a4c9-40c3-9ab9-204b1692522e', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-4100', '3f737cab-beef-4873-8d28-30f48bb20818', 'credit', 560.00, 'Invoice SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('547e593c-9a12-4498-b933-c34ecb72857e', '2ed95978-897d-4a89-98b9-4c20b14e26a2', 'acc-1010', NULL, 'debit', 320.00, 'Daily POS Invoice Payment INV-POS-20260720', 'usr-admin-001', '2026-07-20', '2026-07', 2026, '2026-07-20 10:50:14', NULL, NULL),
('559b4480-cfef-4e75-bec7-666fd3571646', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'credit', 312.50, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('55a40bdd-d79f-4f4f-927c-891d4fe0bc2b', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '5e21fcfb-5077-4a22-97f3-574bda1923f6', 'debit', 4400.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('5652239a-eb55-472d-9110-9c24168b7554', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'debit', 987.51, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('57394e0b-1d37-473e-9395-cd621027cd85', '74b88ca2-7b1f-45d2-af55-090607b85296', 'acc-1200', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'credit', 2012.64, 'Inventory Adj OUT - ADJ-0002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 10:51:08', NULL, NULL),
('5826bff9-a6ef-4a70-b17a-35ac9e26422d', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-1200', 'e88436f2-0460-408d-9494-190246334d27', 'credit', 625.00, 'Inventory Out SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('596fbfd5-1a4d-4936-92f7-49bc773e9673', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'debit', 83.33, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('5ad3a77f-6849-4916-b21f-236a22dedb7b', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'acc-4100', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'credit', 360.00, 'Invoice SI-00003', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 14:32:36', NULL, NULL),
('5c3543e3-71ff-43cb-9721-8495e71ea1d3', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'debit', 10734.08, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('5c464ccb-d645-44ed-b44e-6e7ceeffc08c', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '49963ff8-5d2d-4c0d-a531-f5048e644817', 'debit', 3960.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('5d22e32f-bd6b-4ae9-a750-bbf521cfd247', '8661472c-e952-464c-ab75-f89a20b45c45', 'acc-3100', NULL, 'credit', 1950.00, 'Inventory Adj Offset CR - ADJ-0003', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:51:30', NULL, NULL),
('5f5e7b77-9422-4261-9398-6d36f370ef88', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'acc-1200', '7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 'debit', 960.00, 'Bill VI-00005', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-19 15:01:25', NULL, NULL),
('60ab202f-3068-4587-aa49-820c91d5d1ef', '2e0536d3-1fc0-496e-8ab1-62010c9d0a39', 'acc-6170', NULL, 'debit', 130.00, 'Expense EXP-00001: ', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 15:09:58', 'tea', 'user'),
('613f3406-8584-4355-bb5b-1526437eddf9', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'debit', 312.50, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('61996838-74b3-4a0c-866d-745207eee403', '74b88ca2-7b1f-45d2-af55-090607b85296', 'acc-5200', NULL, 'credit', 1341.75, 'Inventory Adj Offset CR - ADJ-0002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 10:51:08', NULL, NULL),
('625c74b3-c688-4038-9cf0-b11748d1c625', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', '6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 'debit', 1300.00, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('62c8b99f-30cd-4930-96c8-ddab0404abd2', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-2100', NULL, 'credit', 115500.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('62da31ee-df62-4808-925d-15d3f80e5e51', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 987.51, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('63cef3c7-4200-4613-81b2-e08e129bd6ce', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'acc-1100', NULL, 'debit', 715.00, 'Invoice SI-00003', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 14:32:36', NULL, NULL),
('63fc14e5-76e7-46cd-b0e5-1787421ead92', '6a76457e-1018-4649-930c-bf5c82e39ac4', 'acc-2100', NULL, 'debit', 115500.00, 'Payment VPAY-00003', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-17 14:39:35', NULL, NULL),
('64425dd8-fbed-48c2-9e83-10b19160c581', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', 'c101a46e-b532-482f-aa71-cb6ce07431d3', 'debit', 5675.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('64fb9b52-5142-4a75-a193-18c405e6fb62', 'b5a93114-9471-4b73-9f90-6e54d85faf7a', 'acc-2100', NULL, 'credit', 5220.00, 'Bill VI-00003', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-17 14:19:37', NULL, NULL),
('65d708e2-e8a6-4c53-bc30-b87a01627f16', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'debit', 208.00, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('66e024d9-0c70-49cb-8f81-6fd562319aac', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', '3f737cab-beef-4873-8d28-30f48bb20818', 'debit', 516.66, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('67414d53-19c9-4e6e-b4de-2abf3ea0b410', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'debit', 9999.60, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('6a48eadf-e06f-4126-a222-504656580dc3', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1100', NULL, 'debit', 8920.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('6a7d3dd7-4eb5-43ef-8775-731654c04808', 'f9143322-9f7a-4bd2-9b04-c5d0a4074eb3', 'acc-2100', NULL, 'credit', 7700.04, 'Bill VI-00006', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 09:20:39', NULL, NULL),
('6aa8bb88-7a7f-4b30-a24d-be4731c239f2', 'f558e762-5097-4d32-a731-9b615dd98e0a', 'acc-4100', NULL, 'credit', 3460.02, 'POS Daily Sales POS-SUM-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 15:29:55', NULL, NULL),
('6bc580ad-38a3-4403-88a6-d5a196152551', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 220.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('6d395d59-2224-46d2-8218-98fd3e7728fb', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', '4bb831da-2e3d-4482-bd62-05df0c171742', 'credit', 1100.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('6ddc3969-a0d3-4abe-81a0-f9e54ac31aef', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 4015.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('6e53a938-cf56-497b-ad07-5456d6eb387b', '2ed95978-897d-4a89-98b9-4c20b14e26a2', 'acc-1100', NULL, 'credit', 320.00, 'POS Daily Payment INV-POS-20260720', 'usr-admin-001', '2026-07-20', '2026-07', 2026, '2026-07-20 10:50:14', NULL, NULL),
('6f071713-7a9b-4b61-a0dc-ff2cab0dbfd5', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '3f737cab-beef-4873-8d28-30f48bb20818', 'debit', 3100.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('6faf7022-06fc-4000-854d-91a03ea979a5', 'f4cc4cd1-1d4f-46e6-802d-ed824f6d4571', 'acc-1010', NULL, 'debit', 2020.00, 'Payment CPAY-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:20:20', NULL, NULL),
('6ff89683-67d0-4f45-90c9-43065e126198', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 'debit', 5850.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('703c0bce-275b-4edc-8704-27f5016f8b85', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 40.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('70b1e1f5-e36c-4feb-ab15-5acbe1deb2e5', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'acc-4100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 355.00, 'Invoice SI-00003', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 14:32:36', NULL, NULL),
('70bcdc7f-127b-4f83-bbc0-555a6d5961fa', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', 'b6533a14-377b-4d29-8d77-fa4d2fe883ee', 'credit', 1016.75, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('75084da1-60e5-42fb-a308-c1b35ebc96c2', 'eee12567-6915-4618-9c57-db11d63d30c2', 'acc-1200', '1a8166b8-3107-444c-9634-2f27df10e913', 'debit', 7300.00, 'Bill VI-00004', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 08:50:03', NULL, NULL),
('76acc87a-de81-4a2b-a439-1e59272a61d5', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', 'a48cf127-40e9-404e-a9ea-2814657da992', 'credit', 261.00, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('76e53fe3-d4b9-4dc8-9113-4ae650305187', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'debit', 2576.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('78f7ddbb-f0da-457e-8642-34fb0acd7626', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '305ddcc7-fe37-4d60-9f6d-ae92481d343c', 'debit', 850.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('796cb663-9676-44ff-8f2a-8715178e79e1', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 'acc-6160', NULL, 'debit', 5.00, 'Daily POS Invoice Discount INV-POS-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 16:11:00', NULL, NULL),
('7987c5aa-7d2a-4d8e-a5fd-8c3e632a8fa8', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-6160', NULL, 'credit', 259.80, 'Discount VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('7be63fcb-36d4-4d19-93ed-e10a43e32e9c', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '84f1e8aa-26c2-4228-844c-ff33746ad52b', 'debit', 5983.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('7cc1987f-e86d-4665-b643-c03ca327322e', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1100', NULL, 'debit', 195.00, 'Discount POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('7cd7fc1a-38aa-4a3c-8bf1-0877e7d44d2a', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 32.20, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('7d9be89f-d539-40bb-872a-f9a020dc4967', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-4100', '7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 'credit', 250.00, 'Invoice SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('7fcd261b-a42a-4c2e-b96c-f62c98bc8abf', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'credit', 670.88, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('8001b28c-b2d1-43c4-89c6-3d67e5b50f99', 'f558e762-5097-4d32-a731-9b615dd98e0a', 'acc-5100', NULL, 'debit', 3175.24, 'POS Daily COGS POS-SUM-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 15:29:55', NULL, NULL),
('81237448-17f1-4e2e-a448-d89db6afdf26', '1fcbec29-de11-4e6a-8bed-589b251a75b3', 'acc-4100', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'credit', 20.00, 'POS Sales POS-20260718-3289', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 10:29:04', NULL, NULL),
('8144fa0f-5f4a-46c0-9061-c267dac2ba17', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-5100', '3f737cab-beef-4873-8d28-30f48bb20818', 'debit', 516.66, 'COGS SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('8166c56b-b30a-4ecd-a450-d8a2a365e809', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'debit', 161.00, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('835c472c-d232-4a90-88be-72c5af126d36', 'opening-balances-txn-uuid', 'acc-1020', NULL, 'debit', 345094.00, 'Opening Balance for Prabhu Bank', 'usr-admin-001', '2026-06-15', '2026-06', 2026, '2026-07-20 08:09:14', NULL, NULL),
('8434365f-f33b-4326-92cb-dc5692211d08', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', '1a8166b8-3107-444c-9634-2f27df10e913', 'debit', 304.17, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('8524f63c-cc2d-40d8-8aad-cc245d02d02c', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', '3f737cab-beef-4873-8d28-30f48bb20818', 'credit', 516.66, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('8539d62b-8ffb-48c8-9c0d-c68add6d9554', 'd062aea6-6bef-4a34-9ebc-79fe4aa1add9', 'acc-1020', NULL, 'debit', 4465.00, 'Transfer IN - XFER-0001 automatically transfered to bank ', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-17 14:51:44', NULL, NULL),
('85ffd0c2-cfdd-485c-b387-3c26b3048627', '13ef5b2a-743e-43e0-b4ce-886ff8c09e86', 'acc-1100', NULL, 'credit', 7845.00, 'Payment POS-PAY-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 10:07:48', NULL, NULL),
('860be05a-7b88-4751-8757-cced2a8f4fba', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'e88436f2-0460-408d-9494-190246334d27', 'debit', 3750.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('8872a90a-46d0-4364-910b-c26a4a2be0b2', 'ad0b83cc-3d86-4a6c-be55-c4953a6098ba', 'acc-1200', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'debit', 11850.00, 'Bill VI-00002', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-17 13:43:08', NULL, NULL),
('89156862-b4ac-4d84-bfe1-5e16e52b9bc3', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'credit', 260.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('8d3443ae-20fd-4e72-b4c1-abb76ffefe45', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '581f69c5-b0a8-4e0f-9d7d-43e9225a9b40', 'debit', 675.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('8d9cae88-b3c0-4b71-8be1-24722be02cb0', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'd20f5087-48f0-4ec3-950c-d7393884aed4', 'debit', 3102.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('8e48a16c-45fb-40c4-8d5f-95eec3979e1d', 'bd96863a-ad0f-4bc7-8d87-174f5e20dbe8', 'acc-1100', NULL, 'credit', 4475.00, 'POS Daily Payment POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 16:06:06', NULL, NULL),
('8e88637a-8c11-44eb-acff-b922c6333e23', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'credit', 254.19, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('8ef1eed7-1bf2-49ff-9a65-8398e502042e', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', '6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 'credit', 1500.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('90bbc7b1-fdaa-4cd3-9292-56ef987e88d8', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-5100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'debit', 329.17, 'COGS SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('91f9d0bf-5d69-4dc9-aaa7-236b34c8df36', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '919b9e31-52a2-48f9-8795-533b0a081663', 'debit', 6012.50, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('92542460-d072-4ab4-8cbc-3db7adcc9b1c', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'debit', 3620.87, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('9291547c-28a2-4086-a667-1c29609e3f45', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 177.10, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('92adafae-550d-4750-a142-c6bd5d86ad86', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'credit', 166.66, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('939827f3-46f4-4f03-8884-6ba06c6a0c38', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'credit', 1080.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('94858d9f-67b2-41fd-9584-c82abb876353', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'b6533a14-377b-4d29-8d77-fa4d2fe883ee', 'debit', 4067.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('94afd09f-fa7c-40b4-871a-8c21c86d99c5', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-4100', 'e88436f2-0460-408d-9494-190246334d27', 'credit', 750.00, 'Invoice SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('95b3862e-44ea-439b-ba34-10be4983f8a9', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', '4bb831da-2e3d-4482-bd62-05df0c171742', 'debit', 1018.00, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('95c9ccd5-b380-40f3-83f2-657a450a28b3', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 'acc-1100', NULL, 'debit', 805.00, 'Daily POS Sales Invoice INV-POS-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 16:11:00', NULL, NULL),
('9658654f-e8a6-4eb1-a479-c720d708bb11', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', '6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 'credit', 1300.00, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('98c04e47-0904-4472-8248-ba61f9b1cf76', 'f558e762-5097-4d32-a731-9b615dd98e0a', 'acc-6160', NULL, 'debit', 35.00, 'POS Daily Discount POS-SUM-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 15:29:55', NULL, NULL),
('991c44f8-b7b0-4e24-971c-ead792e2057e', 'bd96863a-ad0f-4bc7-8d87-174f5e20dbe8', 'acc-1010', NULL, 'debit', 3677.60, 'POS Daily Payment POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 16:06:06', NULL, NULL),
('9954fb9e-281b-4164-94df-7a5555385652', '9493494b-e8fe-482d-9c22-638b6e31492b', 'acc-1020', NULL, 'credit', 7300.00, 'Payment VPAY-00004', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 08:50:16', NULL, NULL),
('99e74222-17ed-4602-b524-7ff9523d8542', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', 'a48cf127-40e9-404e-a9ea-2814657da992', 'credit', 156.60, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('9a32814b-23a9-4b21-bb2a-cec96260c2d9', 'eb86ca66-4db1-4447-807d-8891c8ba4cd3', 'acc-1010', NULL, 'credit', 7100.00, 'Transfer OUT - XFER-0002 ', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 06:27:07', NULL, NULL),
('9b0d8acf-98e0-4723-af34-bae92184e9f4', 'a2648690-e770-43e7-9acb-9c6654dad464', 'acc-4100', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'credit', 1550.00, 'Invoice SI-00004', 'usr-admin-001', '2026-07-15', '2026-07', 2026, '2026-07-19 07:27:54', NULL, NULL),
('9f00d3d4-bc6d-48d0-8856-039afb4f050d', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', '1a8166b8-3107-444c-9634-2f27df10e913', 'credit', 340.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('9f8c7d30-a5f0-4373-b8e4-eed8e1560925', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '5e21fcfb-5077-4a22-97f3-574bda1923f6', 'debit', 3575.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('a5701cc1-2b4e-43e0-bade-c079c260d05d', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-1200', '7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 'credit', 240.00, 'Inventory Out SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('a64c8d56-f619-41eb-a68d-9495043b1904', 'dc85e963-3b8d-47f3-a40d-0052832989a4', 'acc-2100', NULL, 'debit', 17700.00, 'Payment VPAY-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-17 14:40:24', NULL, NULL),
('a7453548-5888-423f-a0a2-efe23367f938', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', 'acc-1100', NULL, 'debit', 320.00, 'Daily POS Sales Invoice INV-POS-20260720', 'usr-admin-001', '2026-07-20', '2026-07', 2026, '2026-07-20 10:50:14', NULL, NULL),
('a8ed21b5-9fe2-4576-be13-d2fe62e55e7a', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', 'acc-6160', NULL, 'debit', 30.00, 'Daily POS Invoice Discount INV-POS-20260720', 'usr-admin-001', '2026-07-20', '2026-07', 2026, '2026-07-20 10:50:14', NULL, NULL),
('aa1e195c-1aca-4538-b6fa-2527024b71d4', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 'acc-1200', NULL, 'credit', 716.92, 'Daily POS Invoice Inventory Out INV-POS-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 16:11:00', NULL, NULL),
('aa6e33eb-ebae-4ed6-9c48-ed7ac2106304', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', '3f737cab-beef-4873-8d28-30f48bb20818', 'credit', 560.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('abca1315-5dd6-410b-b1c2-eb62942c6653', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', 'b1e6e633-3a08-42d9-8da2-fe6d7acdf463', 'credit', 250.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('abeb02db-5a1f-4408-a2ad-58ffd5c2778a', 'f558e762-5097-4d32-a731-9b615dd98e0a', 'acc-1100', NULL, 'debit', 3425.02, 'POS Daily Invoice POS-SUM-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 15:29:55', NULL, NULL),
('ac1f3c25-9da6-4327-9b03-9a17b6a2f726', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'credit', 220.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('ad5aab0a-7f73-43b3-a37f-4be064d1cf71', '84b42a30-42f7-429b-9041-20ba1aef7642', 'acc-1020', NULL, 'credit', 7700.04, 'Payment VPAY-00006', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 06:28:32', NULL, NULL),
('adc26546-b128-4527-9f31-0b3be542f3c3', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'debit', 508.38, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('ae1dfcfa-ffeb-4786-8ff5-dd837b7f0a77', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'acc-1200', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'credit', 320.00, 'Inventory Out SI-00003', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 14:32:36', NULL, NULL),
('af83b0f6-0006-4c98-a057-57d3cc6d0499', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'debit', 670.88, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('b22eaad0-21ab-4072-8843-6fc50e63b84a', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'credit', 110.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('b4a95a01-43b2-43a6-a645-020392823306', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-4100', '96a4afa3-9243-4f63-acd5-107aa08d6039', 'credit', 250.00, 'Invoice SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('b56e636b-2e45-4fac-94ff-ed667c11519b', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', 'a48cf127-40e9-404e-a9ea-2814657da992', 'debit', 156.60, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('b67a242d-3b2d-4836-a777-369f3a4cefce', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-5100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'debit', 32.20, 'COGS POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('b6ebb990-9afb-494a-a838-30663fb369aa', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 3650.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('b6f71b9c-970f-40af-9db5-7193c53c5142', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-1200', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'credit', 487.50, 'Inventory Out POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('b7d40751-a402-4e86-970f-26a5863cc0ac', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-1200', '3f737cab-beef-4873-8d28-30f48bb20818', 'credit', 516.66, 'Inventory Out SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('b9886ab5-9277-4841-a3a5-f312912c1720', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', '3f737cab-beef-4873-8d28-30f48bb20818', 'credit', 580.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('bccb13ad-a761-4263-b09b-2ff5b65d400d', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', '1a8166b8-3107-444c-9634-2f27df10e913', 'credit', 304.17, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('be5b533a-fb44-485a-8474-b91609d04879', 'f3a6aaf2-f3fd-420c-b622-7eb0d6433fc4', 'acc-1200', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'debit', 22825.00, 'Bill VI-00007', 'usr-admin-001', '2026-07-13', '2026-07', 2026, '2026-07-19 07:46:48', NULL, NULL),
('c4d9e2c6-1b3b-4afb-ba1c-f6564b94adf6', '84b42a30-42f7-429b-9041-20ba1aef7642', 'acc-2100', NULL, 'debit', 7700.04, 'Payment VPAY-00006', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 06:28:32', NULL, NULL);
INSERT INTO `journal_entries` (`id`, `header_id`, `account_id`, `item_id`, `entry_type`, `amount`, `memo`, `created_by`, `entry_date`, `fiscal_period`, `fiscal_year`, `created_at`, `party_id`, `party_type`) VALUES
('c649df73-9b57-4a27-abd9-34fff45df380', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'credit', 750.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('c780516e-653f-46d6-b6cd-712a60d67476', '8661472c-e952-464c-ab75-f89a20b45c45', 'acc-1200', '4376ce7f-e0ae-498d-849d-0f0b494d84a7', 'debit', 1950.00, 'Inventory Adj IN - ADJ-0003', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:51:30', NULL, NULL),
('c7b2780d-c216-48cc-9c54-77b292cd7e7d', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 200.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('ca2d9f2c-3ee6-45f2-bd99-a469e629ff97', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-5100', '4bb831da-2e3d-4482-bd62-05df0c171742', 'debit', 1018.00, 'COGS POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('ccdb9c1b-a005-480e-8c11-73ba436ec0b8', 'b5a93114-9471-4b73-9f90-6e54d85faf7a', 'acc-1200', 'a48cf127-40e9-404e-a9ea-2814657da992', 'debit', 5220.00, 'Bill VI-00003', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-17 14:19:37', NULL, NULL),
('cd950e37-4d13-42d8-8744-c0a213af4167', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-1100', NULL, 'debit', 940.00, 'Invoice SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('ce6b847a-bb5d-43a3-b04b-5e484df80a1d', '74b88ca2-7b1f-45d2-af55-090607b85296', 'acc-1200', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 329.17, 'Inventory Adj OUT - ADJ-0002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 10:51:08', NULL, NULL),
('cf021713-c911-4ae7-b831-1e4582dec6a9', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 'acc-5100', NULL, 'debit', 716.92, 'Daily POS Invoice COGS INV-POS-20260719', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 16:11:00', NULL, NULL),
('cff95607-7dd7-4bbe-9818-c01dd2a15375', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-1200', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 161.00, 'Inventory Out SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('d04c1ece-3e8d-46d3-af3a-bd5fe9e909b9', 'd062aea6-6bef-4a34-9ebc-79fe4aa1add9', 'acc-1030', NULL, 'credit', 4465.00, 'Transfer OUT - XFER-0001 automatically transfered to bank ', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-17 14:51:44', NULL, NULL),
('d11d0a18-bc25-40f5-b0c4-116a233d6632', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', 'f497b9d6-6552-4a41-9b27-4c2fc43fece3', 'debit', 6500.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('d2799b5a-1395-43f5-b9b7-52005bf17799', 'd94529b1-a60a-424c-b22a-2c2a7c073fad', 'acc-6100', NULL, 'debit', 11000.00, '', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 07:39:18', '', ''),
('d37fb465-f82b-400f-b12f-6d57370797be', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-1200', '4bb831da-2e3d-4482-bd62-05df0c171742', 'credit', 1018.00, 'Inventory Out POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('d54ab599-5538-42b0-af4c-ba9c21bda719', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'credit', 360.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('d6068c69-3413-4098-8894-0467a2144ff1', '9493494b-e8fe-482d-9c22-638b6e31492b', 'acc-2100', NULL, 'debit', 7300.00, 'Payment VPAY-00004', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 08:50:16', NULL, NULL),
('d95cd7e7-68de-4bef-9f70-681934e8f4bd', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', '4bb831da-2e3d-4482-bd62-05df0c171742', 'credit', 550.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('d96a2bf5-cb31-428b-8ea5-b1a17a7afa7f', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '4bb831da-2e3d-4482-bd62-05df0c171742', 'debit', 4072.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('d99ca8d2-4afc-47c4-8136-cf233b851842', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'acc-1200', '96a4afa3-9243-4f63-acd5-107aa08d6039', 'debit', 480.00, 'Bill VI-00005', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-19 15:01:25', NULL, NULL),
('db3adefb-5cfc-4297-88be-70653e6e72c2', 'c562ad2f-f088-4e49-81e2-bd7267eb7c95', 'acc-1030', NULL, 'debit', 4465.00, 'Payment POS-PAY-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:01:36', NULL, NULL),
('ddf68322-94b8-47e7-9e64-74d77c797c76', 'ad0b83cc-3d86-4a6c-be55-c4953a6098ba', 'acc-1200', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'debit', 5850.00, 'Bill VI-00002', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-17 13:43:08', NULL, NULL),
('e3ec9850-0ddb-4f0f-9d64-fad4a4936408', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'acc-2100', NULL, 'credit', 2960.01, 'Bill VI-00005', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-19 15:01:25', NULL, NULL),
('e496a882-641d-4edc-8d30-6154b3abdd6f', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'debit', 7900.08, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('e6ba474a-63f4-42b2-a203-091c5a182d77', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', 'a48cf127-40e9-404e-a9ea-2814657da992', 'credit', 391.50, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('e75967ee-1a2f-4a2b-aae8-d35fdbfd9ee2', 'ad0b83cc-3d86-4a6c-be55-c4953a6098ba', 'acc-2100', NULL, 'credit', 17700.00, 'Bill VI-00002', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-17 13:43:08', NULL, NULL),
('e8ca52b4-f294-4711-acfa-13dc22985420', 'd94529b1-a60a-424c-b22a-2c2a7c073fad', 'acc-1010', NULL, 'credit', 11000.00, '', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 07:39:18', '', ''),
('ea3f53a3-85ed-482b-b253-8a953ba9df9d', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'acc-1200', 'b1e6e633-3a08-42d9-8da2-fe6d7acdf463', 'debit', 720.00, 'Bill VI-00005', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-19 15:01:25', NULL, NULL),
('ea799ff6-ef85-4b7f-8f61-230828c5232c', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'debit', 80.00, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('eabfeaf1-38ba-4334-8f0b-7ac6d6810c4a', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-5100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'debit', 16.10, 'COGS SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('eb6bf9f9-971b-4947-b4ae-38e1f6c7b342', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'acc-5100', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'debit', 320.00, 'COGS SI-00003', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 14:32:36', NULL, NULL),
('eefde2de-bb13-4f9b-8d3f-a7f7674f2ca5', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', 'a48cf127-40e9-404e-a9ea-2814657da992', 'debit', 391.50, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('efc14635-82df-459d-82db-fe916098ffdd', '5d27e5fa-2465-463b-bbd4-388435dc2a16', 'acc-1010', NULL, 'credit', 2960.01, 'Payment VPAY-00005', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 08:54:00', NULL, NULL),
('f05f9b78-9a5d-4149-8f56-62dfb8c465fa', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'credit', 937.50, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('f1cc2f8a-c97b-4837-99c6-947e39573031', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-5100', '96a4afa3-9243-4f63-acd5-107aa08d6039', 'debit', 240.00, 'COGS SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('f27df8f9-4d75-44a0-b1ee-9e4fec4aac80', 'c562ad2f-f088-4e49-81e2-bd7267eb7c95', 'acc-1100', NULL, 'credit', 8920.00, 'Payment POS-PAY-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:01:36', NULL, NULL),
('f3572aa2-870c-4a29-90ab-ddcd5dff3123', '13ef5b2a-743e-43e0-b4ce-886ff8c09e86', 'acc-1020', NULL, 'debit', 3940.00, 'Payment POS-PAY-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 10:07:48', NULL, NULL),
('f35f3162-dc4b-4f86-8139-c2847f2c7bdf', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'cc197d32-be40-4c35-aee8-b553af156838', 'debit', 3600.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('f38c6f29-2d2b-4f6a-936c-6b259f4670ab', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '996248e3-1b15-4b68-ab39-eac57f3a71ea', 'debit', 1800.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('f6608b7d-ad08-4142-a9de-a7e9ce0b1c8d', '99ef144b-131a-4762-8f41-2547d67a71b0', 'acc-4100', 'a48cf127-40e9-404e-a9ea-2814657da992', 'credit', 180.00, 'Invoice POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:23', NULL, NULL),
('f6b2024f-e375-4915-a863-56da60e71c75', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '742c00bc-0e41-4714-a2ae-c873fa9a5ff9', 'debit', 671.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('f6d4dc17-f971-4589-bfbf-2cdb6242abf0', 'opening-balances-txn-uuid', 'bbe5c26b-091b-4b2c-939c-8a18220bcc5a', NULL, 'credit', 364212.00, 'Opening Balance Equity Offset', 'usr-admin-001', '2026-06-15', '2026-06', 2026, '2026-07-20 08:09:14', NULL, NULL),
('f6e31a05-0585-4a4d-b0ca-a399895a6b3d', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-1200', '1a8166b8-3107-444c-9634-2f27df10e913', 'credit', 3650.04, 'Inventory Out SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('f73204bf-d4e8-49fa-a612-a99036aa5709', 'eb86ca66-4db1-4447-807d-8891c8ba4cd3', 'acc-1020', NULL, 'debit', 7100.00, 'Transfer IN - XFER-0002 ', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 06:27:07', NULL, NULL),
('f831ec2f-449d-4d6c-b8e2-5362c405352c', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'da007f07-7cfa-4c99-b90a-1afab623bb63', 'debit', 9699.96, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('f8f4d563-b0e5-48d6-8f4d-e1232e3ac74d', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-4100', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'credit', 100.00, 'Invoice POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('f9272a40-f969-4633-ae74-7c4193abcfef', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-4100', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'credit', 560.00, 'Invoice POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('f92d5bf7-b21a-4de5-a31f-9a74a6eb9fdc', '4065ba21-c172-4bd8-8f13-e9d0867be8f4', 'acc-1010', NULL, 'debit', 2600.00, 'Payment CPAY-00002', 'usr-admin-001', '2026-07-19', '2026-07', 2026, '2026-07-19 06:37:29', NULL, NULL),
('f9716a21-4b17-408d-9e7f-2af44f9da78d', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'acc-6160', NULL, 'debit', 45.00, 'Discount POS-SUM-20260717', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 07:23:29', NULL, NULL),
('f98e0f63-63e7-40c3-9f6d-0d7c09c8e875', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'debit', 3750.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('f98eba3b-caf5-4109-a662-4ab07b7c9936', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'acc-4100', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'credit', 20.00, 'Invoice SI-00002', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-20 07:23:16', NULL, NULL),
('fadff61e-42a6-4f20-8046-8c0ed019e261', 'c562ad2f-f088-4e49-81e2-bd7267eb7c95', 'acc-1010', NULL, 'debit', 4455.00, 'Payment POS-PAY-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:01:36', NULL, NULL),
('fb109141-93b9-40e5-907c-cff2ef2b5cec', '1fcbec29-de11-4e6a-8bed-589b251a75b3', 'acc-1100', NULL, 'debit', 20.00, 'POS Invoice POS-20260718-3289', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 10:29:04', NULL, NULL),
('fb716d48-2fa0-467b-969b-230da1b7c4ee', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'acc-1200', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'debit', 187.00, 'Inventory Adj IN - ADJ-0001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-20 10:50:44', NULL, NULL),
('fc4385ba-a0a2-40b6-bfcc-637aa3569f1a', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', 'c9d5cd17-fb9a-47dc-a938-17e8fba12e9c', 'debit', 2200.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('fc80b43c-e0d7-4593-9d90-4eccd0ed89b8', 'bd96863a-ad0f-4bc7-8d87-174f5e20dbe8', 'acc-6170', NULL, 'debit', 87.40, 'POS Daily Cash Discrepancy POS-SUM-20260718', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 16:06:06', NULL, NULL),
('fceb011b-5078-4bc9-a598-a776eb029530', '1fcbec29-de11-4e6a-8bed-589b251a75b3', 'acc-5100', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'debit', 11.00, 'POS COGS POS-20260718-3289', 'usr-admin-001', '2026-07-18', '2026-07', 2026, '2026-07-18 10:29:04', NULL, NULL),
('fe727e04-1590-4304-b021-9d743aa0fe35', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'acc-1200', '49225a12-9859-4acf-9e02-7c2f19ed4fda', 'debit', 6300.00, 'Bill VI-00001', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-19 16:16:53', NULL, NULL),
('ff321176-4a64-440a-8bea-0357754ddd16', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-1200', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'credit', 3620.87, 'Inventory Out POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('ffaef221-dc04-41d3-8c69-f6339ec6362e', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-1200', '96a4afa3-9243-4f63-acd5-107aa08d6039', 'credit', 240.00, 'Inventory Out SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL),
('ffcd541b-fde3-4fbd-808e-5459405c4eb6', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'acc-5100', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'debit', 937.50, 'COGS POS-SUM-20260716', 'usr-admin-001', '2026-07-16', '2026-07', 2026, '2026-07-18 10:02:46', NULL, NULL),
('ffea5dc7-266c-4a4c-a481-ef2558a04b7a', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'acc-5100', '7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 'debit', 240.00, 'COGS SI-00001', 'usr-admin-001', '2026-07-17', '2026-07', 2026, '2026-07-18 12:19:57', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` varchar(36) NOT NULL,
  `header_id` varchar(36) NOT NULL,
  `payment_type` enum('customer_payment','vendor_payment') NOT NULL,
  `vendor_id` varchar(36) DEFAULT NULL,
  `customer_id` varchar(36) DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer','cheque','card','esewa','khalti') NOT NULL,
  `bank_account_id` varchar(36) NOT NULL,
  `applied_to_txn_id` varchar(36) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `transaction_reference` varchar(100) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `created_by` varchar(36) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `header_id`, `payment_type`, `vendor_id`, `customer_id`, `payment_method`, `bank_account_id`, `applied_to_txn_id`, `amount`, `cheque_number`, `transaction_reference`, `payment_date`, `created_by`) VALUES
('001f396b-4e8e-425c-8a8e-0b9e6ce4626a', '13ef5b2a-743e-43e0-b4ce-886ff8c09e86', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'cash', 'acc-1010', NULL, 3905.00, NULL, '', '2026-07-17', NULL),
('09beb2a5-73e2-4811-9a84-8a8c0b51264f', '4065ba21-c172-4bd8-8f13-e9d0867be8f4', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'cash', 'acc-1010', NULL, 2600.00, NULL, '', '2026-07-19', NULL),
('1166490a-6786-4164-9d0c-5200543738a5', '40caee36-c141-41ff-b7cd-db1d58271191', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'bank_transfer', 'acc-1020', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 300.00, NULL, NULL, '2026-07-19', 'usr-admin-001'),
('21f56e94-6b9a-4156-a8c5-fb88c8026e79', 'dc85e963-3b8d-47f3-a40d-0052832989a4', 'vendor_payment', '9664a5f1-727b-4b5e-92ee-cfa9fce76b01', NULL, 'bank_transfer', 'acc-1020', NULL, 5500.00, NULL, '', '2026-07-17', NULL),
('38ccdb3e-8aa4-48db-8f93-0d7e97653c6d', '161e3d0c-e842-4082-ae2c-729d5dc7e4bd', 'vendor_payment', 'c233e230-1660-446c-a134-e4cd7f75354a', NULL, 'bank_transfer', 'acc-1020', NULL, 5220.00, NULL, '', '2026-07-16', NULL),
('40eaddb1-5dda-47b4-a765-a150af4dfd34', '40caee36-c141-41ff-b7cd-db1d58271191', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'cash', 'acc-1010', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 505.00, NULL, NULL, '2026-07-19', 'usr-admin-001'),
('46589e5e-52ae-470d-8928-2fa54875ba11', '9493494b-e8fe-482d-9c22-638b6e31492b', 'vendor_payment', '9664a5f1-727b-4b5e-92ee-cfa9fce76b01', NULL, 'bank_transfer', 'acc-1020', NULL, 7300.00, NULL, '', '2026-07-18', NULL),
('47f3fba1-4916-4bcb-bc9a-05b63b6c0c83', 'f4cc4cd1-1d4f-46e6-802d-ed824f6d4571', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'cash', 'acc-1010', NULL, 550.00, NULL, '', '2026-07-17', NULL),
('531bd6d3-811c-4714-a72b-d41c4f2f4831', 'bd96863a-ad0f-4bc7-8d87-174f5e20dbe8', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'cash', 'acc-1010', '99ef144b-131a-4762-8f41-2547d67a71b0', 3677.60, NULL, NULL, '2026-07-18', 'usr-admin-001'),
('59dd7fd7-c517-4ae1-ba81-2090384c4b08', '13ef5b2a-743e-43e0-b4ce-886ff8c09e86', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'bank_transfer', 'acc-1020', NULL, 3940.00, NULL, '', '2026-07-17', NULL),
('5fd6948b-d14d-48e9-bfc5-9bc4b7bcffcf', '84b42a30-42f7-429b-9041-20ba1aef7642', 'vendor_payment', 'c233e230-1660-446c-a134-e4cd7f75354a', NULL, 'bank_transfer', 'acc-1020', NULL, 7700.04, NULL, '', '2026-07-19', NULL),
('601493f5-ce6d-4112-8920-f2b33920bda6', '5d27e5fa-2465-463b-bbd4-388435dc2a16', 'vendor_payment', 'c233e230-1660-446c-a134-e4cd7f75354a', NULL, 'cash', 'acc-1010', NULL, 2960.01, NULL, '', '2026-07-18', NULL),
('6de55e83-84b9-433b-b727-ea3f07503a62', 'bd96863a-ad0f-4bc7-8d87-174f5e20dbe8', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'bank_transfer', 'acc-1020', '99ef144b-131a-4762-8f41-2547d67a71b0', 710.00, NULL, NULL, '2026-07-18', 'usr-admin-001'),
('844b7f51-9baf-4830-a7e3-757338eb5738', 'c562ad2f-f088-4e49-81e2-bd7267eb7c95', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'cash', 'acc-1010', NULL, 4455.00, NULL, '', '2026-07-16', NULL),
('8aa25bb0-7d6e-4101-b611-aa834b2567fb', '2ed95978-897d-4a89-98b9-4c20b14e26a2', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'cash', 'acc-1010', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', 320.00, NULL, NULL, '2026-07-20', 'usr-admin-001'),
('9c474a86-60e2-41dd-b007-89678b9d3b34', 'dc85e963-3b8d-47f3-a40d-0052832989a4', 'vendor_payment', '9664a5f1-727b-4b5e-92ee-cfa9fce76b01', NULL, 'cash', 'acc-1010', NULL, 12200.00, NULL, '', '2026-07-17', NULL),
('a1614bb4-3e2b-4d46-af31-f549eeb00d5d', '6a76457e-1018-4649-930c-bf5c82e39ac4', 'vendor_payment', '9664a5f1-727b-4b5e-92ee-cfa9fce76b01', NULL, 'bank_transfer', 'acc-1020', NULL, 115500.00, NULL, '', '2026-07-16', NULL),
('c747a38d-86df-40f8-a571-61fe0cd4f857', 'f4cc4cd1-1d4f-46e6-802d-ed824f6d4571', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'cash', 'acc-1010', NULL, 2020.00, NULL, '', '2026-07-17', NULL),
('c774da2c-bc18-46f0-bf6d-b19c3e91a91d', 'c562ad2f-f088-4e49-81e2-bd7267eb7c95', 'customer_payment', NULL, '64e084cd-4fdd-409b-9137-56e30c685640', 'esewa', 'acc-1030', NULL, 4465.00, NULL, '', '2026-07-16', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pos_entry`
--

CREATE TABLE `pos_entry` (
  `id` varchar(36) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `date_time` datetime NOT NULL,
  `customer_id` varchar(36) DEFAULT NULL,
  `gross_amount` decimal(14,2) NOT NULL,
  `discount_type` enum('percentage','fixed') DEFAULT 'fixed',
  `discount_value` decimal(14,2) DEFAULT 0.00,
  `discount_amount` decimal(14,2) DEFAULT 0.00,
  `tax_amount` decimal(14,2) NOT NULL,
  `net_amount` decimal(14,2) NOT NULL,
  `status` enum('draft','completed','returned','voided') DEFAULT 'completed',
  `created_by` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pos_entry`
--

INSERT INTO `pos_entry` (`id`, `invoice_no`, `date_time`, `customer_id`, `gross_amount`, `discount_type`, `discount_value`, `discount_amount`, `tax_amount`, `net_amount`, `status`, `created_by`, `created_at`, `updated_at`, `is_deleted`) VALUES
('04eb4b86-5a3c-4bb0-a863-534bdc5d772c', 'POS-20260718-7163', '2026-07-18 14:19:13', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 50.00, 50.00, 123.50, 1073.50, 'completed', 'usr-admin-001', '2026-07-18 12:19:12', '2026-07-18 12:20:57', 1),
('04f62b7a-a63d-40e9-b19c-cd2bb8ce11cc', 'POS-20260716-3256', '2026-07-16 15:41:48', '64e084cd-4fdd-409b-9137-56e30c685640', 1070.00, 'fixed', 10.00, 10.00, 0.00, 1060.00, 'completed', 'usr-admin-001', '2026-07-16 13:41:48', '2026-07-16 13:41:48', 0),
('065ab38d-f7bc-4332-bbab-e346935971cb', 'POS-20260716-7937', '2026-07-16 14:35:30', '64e084cd-4fdd-409b-9137-56e30c685640', 20.00, 'fixed', 0.00, 0.00, 0.00, 20.00, 'completed', 'usr-admin-001', '2026-07-16 12:35:30', '2026-07-16 12:35:30', 0),
('191dc0b8-e254-4ba2-956b-960fba7080e5', 'POS-20260718-1327', '2026-07-18 13:33:00', '64e084cd-4fdd-409b-9137-56e30c685640', 200.00, 'fixed', 30.00, 30.00, 0.00, 170.00, 'completed', 'usr-admin-001', '2026-07-18 11:33:00', '2026-07-18 12:19:10', 1),
('1bcd5ffb-694e-46f3-9aaa-f886f37dfc97', 'POS-20260718-5957', '2026-07-18 12:56:55', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 100.00, 100.00, 117.00, 1017.00, 'completed', 'usr-admin-001', '2026-07-18 10:56:55', '2026-07-18 10:57:24', 1),
('1d87aa7c-be78-4825-9132-3e205f88039a', 'POS-20260718-1494', '2026-07-18 12:56:22', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 50.00, 50.00, 123.50, 1073.50, 'completed', 'usr-admin-001', '2026-07-18 10:56:22', '2026-07-18 10:56:55', 1),
('28ac7168-6571-4dca-b74f-1ac0bf34c313', 'POS-20260718-5741', '2026-07-18 12:56:21', '030e6c08-51b5-4a1e-b35a-a16514a36485', 500.00, 'fixed', 0.00, 0.00, 65.00, 565.00, 'completed', 'usr-admin-001', '2026-07-18 10:56:21', '2026-07-18 10:56:55', 1),
('2a086fdc-9ace-4aee-8eb1-35efc69ff30a', 'POS-20260720-7907', '2026-07-20 12:47:52', '64e084cd-4fdd-409b-9137-56e30c685640', 250.00, 'fixed', 30.00, 30.00, 0.00, 220.00, 'completed', 'usr-admin-001', '2026-07-20 10:47:52', '2026-07-20 10:47:52', 0),
('2fcb02d4-7308-41b4-8275-bfb740ae9ee5', 'POS-20260718-2851', '2026-07-18 12:55:03', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 100.00, 100.00, 117.00, 1017.00, 'completed', 'usr-admin-001', '2026-07-18 10:55:03', '2026-07-18 10:55:29', 1),
('353b67a1-a463-4098-b44c-05cd80b15f63', 'POS-20260716-8087', '2026-07-16 15:36:57', '64e084cd-4fdd-409b-9137-56e30c685640', 750.00, 'fixed', 0.00, 0.00, 0.00, 750.00, 'completed', 'usr-admin-001', '2026-07-16 13:36:57', '2026-07-16 13:36:57', 0),
('3af2f3a0-edf5-4300-8d16-bb318df25a9e', 'POS-20260718-6400', '2026-07-18 12:57:24', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 100.00, 100.00, 117.00, 1017.00, 'completed', 'usr-admin-001', '2026-07-18 10:57:24', '2026-07-18 12:19:10', 1),
('3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', 'POS-SUM-20260718', '2026-07-18 09:23:23', '64e084cd-4fdd-409b-9137-56e30c685640', 4475.00, 'fixed', 0.00, 0.00, 0.00, 4475.00, 'completed', 'usr-admin-001', '2026-07-20 07:23:23', '2026-07-20 07:23:23', 0),
('3cc99a37-a51f-46bc-ba77-243f16b8108e', 'POS-20260716-1145', '2026-07-16 14:35:41', '64e084cd-4fdd-409b-9137-56e30c685640', 540.00, 'fixed', 0.00, 0.00, 0.00, 540.00, 'completed', 'usr-admin-001', '2026-07-16 12:35:41', '2026-07-16 12:35:41', 0),
('3faae224-113d-40d1-903b-53545f84ed9c', 'POS-20260716-9556', '2026-07-16 15:24:13', '64e084cd-4fdd-409b-9137-56e30c685640', 300.00, 'fixed', 20.00, 20.00, 0.00, 280.00, 'completed', 'usr-admin-001', '2026-07-16 13:24:13', '2026-07-16 13:24:13', 0),
('44618e71-aa8e-4074-a881-82fd47b1d050', 'POS-20260716-8869', '2026-07-16 17:02:10', '64e084cd-4fdd-409b-9137-56e30c685640', 100.00, 'fixed', 10.00, 10.00, 0.00, 90.00, 'completed', 'usr-admin-001', '2026-07-16 15:02:10', '2026-07-16 15:02:10', 0),
('45ad33ce-256f-4194-92a9-0fb17ca8fcfa', 'POS-20260719-8610', '2026-07-19 18:11:00', '64e084cd-4fdd-409b-9137-56e30c685640', 390.00, 'fixed', 0.00, 0.00, 0.00, 390.00, 'completed', 'usr-admin-001', '2026-07-19 16:11:00', '2026-07-19 16:11:00', 0),
('498439be-4d2b-4df7-9403-bf6006c8ed2c', 'POS-20260718-8698', '2026-07-18 12:55:29', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 50.00, 50.00, 123.50, 1073.50, 'completed', 'usr-admin-001', '2026-07-18 10:55:29', '2026-07-18 10:56:21', 1),
('4cc49e10-031e-4b50-98ce-59f4c523e27e', 'POS-20260718-2804', '2026-07-18 12:57:24', '030e6c08-51b5-4a1e-b35a-a16514a36485', 500.00, 'fixed', 0.00, 0.00, 65.00, 565.00, 'completed', 'usr-admin-001', '2026-07-18 10:57:24', '2026-07-18 12:19:10', 1),
('4fb7f04e-fb5a-440f-8925-a0c9f3f71757', 'POS-20260716-9199', '2026-07-16 14:35:22', '64e084cd-4fdd-409b-9137-56e30c685640', 730.00, 'fixed', 20.00, 20.00, 0.00, 710.00, 'completed', 'usr-admin-001', '2026-07-16 12:35:22', '2026-07-16 12:35:22', 0),
('52248e67-46f0-4563-a732-1076fddc188d', 'POS-20260718-9892', '2026-07-18 14:20:58', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 50.00, 50.00, 123.50, 1073.50, 'completed', 'usr-admin-001', '2026-07-18 12:20:57', '2026-07-18 12:21:39', 1),
('5262b0a7-22f4-4187-8005-c2463b09673c', 'POS-20260718-2612', '2026-07-18 12:55:03', '030e6c08-51b5-4a1e-b35a-a16514a36485', 500.00, 'fixed', 0.00, 0.00, 65.00, 565.00, 'completed', 'usr-admin-001', '2026-07-18 10:55:03', '2026-07-18 10:55:29', 1),
('54082647-54aa-4e48-b2da-5659d64b8a1b', 'POS-20260718-5150', '2026-07-18 13:01:01', '64e084cd-4fdd-409b-9137-56e30c685640', 895.00, 'fixed', 0.00, 0.00, 0.00, 895.00, 'completed', 'usr-admin-001', '2026-07-18 11:01:01', '2026-07-18 12:19:10', 1),
('5d2f6e0b-a0a5-4f70-bcdc-91358c905522', 'POS-20260719-7278', '2026-07-19 17:26:25', '64e084cd-4fdd-409b-9137-56e30c685640', 105.00, 'fixed', 5.00, 5.00, 0.00, 100.00, 'completed', 'usr-admin-001', '2026-07-19 15:26:25', '2026-07-19 15:26:25', 0),
('6c9e2c19-9d01-43eb-8f22-108771c23706', 'POS-20260718-1609', '2026-07-18 13:00:49', '64e084cd-4fdd-409b-9137-56e30c685640', 895.00, 'fixed', 0.00, 0.00, 0.00, 895.00, 'completed', 'usr-admin-001', '2026-07-18 11:00:49', '2026-07-18 12:19:10', 1),
('6eb4ca27-4d3b-4c21-99f7-c0e34a57d03f', 'POS-20260718-5290', '2026-07-18 14:00:18', '64e084cd-4fdd-409b-9137-56e30c685640', 900.00, 'fixed', 40.00, 40.00, 0.00, 860.00, 'completed', 'usr-admin-001', '2026-07-18 12:00:18', '2026-07-18 12:19:10', 1),
('81edbda9-388b-4c17-83ae-313c2193fb03', 'POS-20260718-2039', '2026-07-18 12:55:29', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 100.00, 100.00, 117.00, 1017.00, 'completed', 'usr-admin-001', '2026-07-18 10:55:29', '2026-07-18 10:56:21', 1),
('8bcd5dbb-010c-4cd8-90b6-ffec0003f266', 'POS-20260718-5185', '2026-07-18 14:20:57', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 100.00, 100.00, 117.00, 1017.00, 'completed', 'usr-admin-001', '2026-07-18 12:20:57', '2026-07-18 12:21:39', 1),
('8d1ae08d-dbb8-447f-beb2-5b5d965f92e4', 'POS-20260718-3215', '2026-07-18 12:56:21', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 100.00, 100.00, 117.00, 1017.00, 'completed', 'usr-admin-001', '2026-07-18 10:56:21', '2026-07-18 10:56:55', 1),
('90589e45-2814-4770-b182-fabeb4262015', 'POS-20260718-4278', '2026-07-18 12:55:29', '030e6c08-51b5-4a1e-b35a-a16514a36485', 500.00, 'fixed', 0.00, 0.00, 65.00, 565.00, 'completed', 'usr-admin-001', '2026-07-18 10:55:29', '2026-07-18 10:56:21', 1),
('91f4905e-a237-45ac-9cc5-32f6745efd67', 'POS-20260716-5998', '2026-07-16 16:52:31', '64e084cd-4fdd-409b-9137-56e30c685640', 1825.00, 'fixed', 50.00, 50.00, 0.00, 1775.00, 'completed', 'usr-admin-001', '2026-07-16 14:52:31', '2026-07-16 14:52:31', 0),
('98a7f933-6da6-4094-8250-048069f90d32', 'POS-20260716-1635', '2026-07-16 17:45:27', '64e084cd-4fdd-409b-9137-56e30c685640', 290.00, 'fixed', 0.00, 0.00, 0.00, 290.00, 'completed', 'usr-admin-001', '2026-07-16 15:45:27', '2026-07-16 15:45:27', 0),
('9ab55263-ae90-43ba-a6e2-f8130fbd03ea', 'POS-20260718-7478', '2026-07-18 14:20:58', '030e6c08-51b5-4a1e-b35a-a16514a36485', 500.00, 'fixed', 0.00, 0.00, 65.00, 565.00, 'completed', 'usr-admin-001', '2026-07-18 12:20:58', '2026-07-18 12:21:39', 1),
('9b1b162d-a279-422d-a5d3-0cdb0b0d0487', 'POS-20260719-4989', '2026-07-19 17:29:55', '64e084cd-4fdd-409b-9137-56e30c685640', 15.00, 'fixed', 0.00, 0.00, 0.00, 15.00, 'completed', 'usr-admin-001', '2026-07-19 15:29:55', '2026-07-19 15:29:55', 0),
('a35e1aa2-b6b9-4ba3-b91e-4ffffd4ac50d', 'POS-20260718-3289', '2026-07-18 12:29:04', '64e084cd-4fdd-409b-9137-56e30c685640', 20.00, 'fixed', 0.00, 0.00, 0.00, 20.00, 'completed', 'usr-admin-001', '2026-07-18 10:29:04', '2026-07-18 10:55:02', 1),
('a367c0c5-b986-4fbe-b81e-4831c62767dc', 'POS-SUM-20260718-DEL-66d9d116', '2026-07-18 14:32:08', '030e6c08-51b5-4a1e-b35a-a16514a36485', 3000.00, 'fixed', 0.00, 0.00, 390.00, 3390.00, 'completed', 'usr-admin-001', '2026-07-18 12:32:08', '2026-07-18 12:47:56', 1),
('a4d4e223-563d-4ce9-8e24-df44f83b8929', 'POS-20260718-1379', '2026-07-18 12:55:03', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 50.00, 50.00, 123.50, 1073.50, 'completed', 'usr-admin-001', '2026-07-18 10:55:03', '2026-07-18 10:55:29', 1),
('a6ee8862-822e-466f-94c6-1ac261422922', 'POS-20260718-6545', '2026-07-18 14:19:11', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 100.00, 100.00, 117.00, 1017.00, 'completed', 'usr-admin-001', '2026-07-18 12:19:11', '2026-07-18 12:20:57', 1),
('a9a5ed7e-bb2c-45a9-a79f-c4b989ec15a3', 'POS-20260716-8130', '2026-07-16 15:22:41', '64e084cd-4fdd-409b-9137-56e30c685640', 180.00, 'fixed', 0.00, 0.00, 0.00, 180.00, 'completed', 'usr-admin-001', '2026-07-16 13:22:41', '2026-07-16 13:22:41', 0),
('ac5b152b-4ec0-40ea-a1e5-4b800aabd8f5', 'POS-20260718-7472', '2026-07-18 13:00:55', '64e084cd-4fdd-409b-9137-56e30c685640', 895.00, 'fixed', 0.00, 0.00, 0.00, 895.00, 'completed', 'usr-admin-001', '2026-07-18 11:00:55', '2026-07-18 12:19:10', 1),
('bbe498f0-e7e4-4f35-b267-3888d06b3e4c', 'POS-20260718-1841', '2026-07-18 12:56:55', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 50.00, 50.00, 123.50, 1073.50, 'completed', 'usr-admin-001', '2026-07-18 10:56:55', '2026-07-18 10:57:24', 1),
('bf437309-8881-4c90-9034-b021aa8c19df', 'POS-20260716-1084', '2026-07-16 15:37:09', '64e084cd-4fdd-409b-9137-56e30c685640', 730.00, 'fixed', 10.00, 10.00, 0.00, 720.00, 'completed', 'usr-admin-001', '2026-07-16 13:37:09', '2026-07-16 13:37:09', 0),
('c170b9e2-2cea-4261-9743-aead6140c519', 'POS-20260718-4349', '2026-07-18 11:33:17', '64e084cd-4fdd-409b-9137-56e30c685640', 330.00, 'fixed', 0.00, 0.00, 0.00, 330.00, 'completed', 'usr-admin-001', '2026-07-18 09:33:17', '2026-07-18 10:55:02', 1),
('c1f0710b-3e47-4e52-9a60-2f2eabd924ce', 'POS-20260718-5658', '2026-07-18 12:56:55', '030e6c08-51b5-4a1e-b35a-a16514a36485', 500.00, 'fixed', 0.00, 0.00, 65.00, 565.00, 'completed', 'usr-admin-001', '2026-07-18 10:56:55', '2026-07-18 10:57:24', 1),
('cc140771-b724-4e9f-b206-f6356c0b81e8', 'POS-SUM-20260717', '2026-07-17 09:23:29', '64e084cd-4fdd-409b-9137-56e30c685640', 7890.00, 'fixed', 45.00, 45.00, 0.00, 7845.00, 'completed', 'usr-admin-001', '2026-07-20 07:23:29', '2026-07-20 07:23:29', 0),
('cdc0e5e7-d35b-47cc-81f9-389abfd6f7e3', 'POS-20260720-1237', '2026-07-20 12:50:14', '64e084cd-4fdd-409b-9137-56e30c685640', 100.00, 'fixed', 0.00, 0.00, 0.00, 100.00, 'completed', 'usr-admin-001', '2026-07-20 10:50:14', '2026-07-20 10:50:14', 0),
('ce05f2d5-33a2-4829-8966-2ebdf54e9621', 'POS-20260716-6671', '2026-07-16 16:21:17', '64e084cd-4fdd-409b-9137-56e30c685640', 150.00, 'fixed', 10.00, 10.00, 0.00, 140.00, 'completed', 'usr-admin-001', '2026-07-16 14:21:17', '2026-07-16 14:21:17', 0),
('ced875c6-197f-413f-af5d-2c88d736b788', 'POS-20260718-6996', '2026-07-18 12:57:24', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 50.00, 50.00, 123.50, 1073.50, 'completed', 'usr-admin-001', '2026-07-18 10:57:24', '2026-07-18 12:19:10', 1),
('d4813390-9171-4285-af4e-3ea14f3d4fce', 'POS-20260718-2684', '2026-07-18 12:55:29', '030e6c08-51b5-4a1e-b35a-a16514a36485', 500.00, 'fixed', 0.00, 0.00, 65.00, 565.00, 'completed', 'usr-admin-001', '2026-07-18 10:55:29', '2026-07-18 10:56:21', 1),
('e4bd4d1f-a0c9-4c57-ace8-dcbd15c921f2', 'POS-20260716-4465', '2026-07-16 14:35:01', '64e084cd-4fdd-409b-9137-56e30c685640', 750.00, 'fixed', 0.00, 0.00, 0.00, 750.00, 'completed', 'usr-admin-001', '2026-07-16 12:35:01', '2026-07-16 12:35:01', 0),
('e55daf02-b11e-4572-b668-73cc95329ba5', 'POS-SUM-20260719-DEL-acf062a7', '2026-07-19 17:20:41', '64e084cd-4fdd-409b-9137-56e30c685640', 3340.02, 'fixed', 30.00, 30.00, 0.00, 3310.02, 'completed', 'usr-admin-001', '2026-07-19 15:20:41', '2026-07-19 15:49:01', 1),
('f00a6da2-98c1-467b-b50c-7935f7dfdb04', 'POS-20260718-5163-DEL-67d7a445', '2026-07-18 14:21:40', '030e6c08-51b5-4a1e-b35a-a16514a36485', 1000.00, 'fixed', 100.00, 100.00, 117.00, 1017.00, 'completed', 'usr-admin-001', '2026-07-18 12:21:40', '2026-07-18 12:21:41', 1),
('f14d4b7c-6bae-4e7b-b3de-778f5b0d80d3', 'POS-20260718-6831', '2026-07-18 12:55:03', '030e6c08-51b5-4a1e-b35a-a16514a36485', 500.00, 'fixed', 0.00, 0.00, 65.00, 565.00, 'completed', 'usr-admin-001', '2026-07-18 10:55:03', '2026-07-18 10:55:29', 1),
('f8d5570c-2be4-4eb2-a9b9-35543776e90d', 'POS-20260716-9279', '2026-07-16 17:52:18', '64e084cd-4fdd-409b-9137-56e30c685640', 200.00, 'fixed', 30.00, 30.00, 0.00, 170.00, 'completed', 'usr-admin-001', '2026-07-16 15:52:18', '2026-07-16 15:52:18', 0),
('fb729613-943e-4ea2-99a2-8950d64f8ecd', 'POS-20260719-6043', '2026-07-19 17:47:16', '64e084cd-4fdd-409b-9137-56e30c685640', 300.00, 'fixed', 0.00, 0.00, 0.00, 300.00, 'completed', 'usr-admin-001', '2026-07-19 15:47:16', '2026-07-19 15:47:16', 0),
('fce7eb12-aed6-403b-9e97-e720b32f29e1', 'POS-20260716-1757', '2026-07-16 16:31:39', '64e084cd-4fdd-409b-9137-56e30c685640', 1480.00, 'fixed', 35.00, 35.00, 0.00, 1445.00, 'completed', 'usr-admin-001', '2026-07-16 14:31:39', '2026-07-16 14:31:39', 0);

-- --------------------------------------------------------

--
-- Table structure for table `pos_items`
--

CREATE TABLE `pos_items` (
  `id` varchar(36) NOT NULL,
  `pos_id` varchar(36) NOT NULL,
  `item_id` varchar(36) NOT NULL,
  `quantity` decimal(14,2) NOT NULL,
  `rate` decimal(14,2) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `discount` decimal(14,2) DEFAULT 0.00,
  `tax` decimal(14,2) DEFAULT 0.00,
  `net_amount` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pos_items`
--

INSERT INTO `pos_items` (`id`, `pos_id`, `item_id`, `quantity`, `rate`, `amount`, `discount`, `tax`, `net_amount`) VALUES
('0b8d352e-4802-48c9-b815-ccd478f93637', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 3.00, 355.00, 1065.00, 0.00, 0.00, 1065.00),
('10a755e8-05f7-4030-9c73-2869b6f3b7a0', '04f62b7a-a63d-40e9-b19c-cd2bb8ce11cc', '4bb831da-2e3d-4482-bd62-05df0c171742', 1.00, 550.00, 550.00, 0.00, 0.00, 544.86),
('14348bae-7af2-459b-8397-6af224171d40', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 10.00, 20.00, 200.00, 0.00, 0.00, 200.00),
('15b5c17b-3985-412a-b087-9ca35fc2b3c4', 'f8d5570c-2be4-4eb2-a9b9-35543776e90d', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 10.00, 20.00, 200.00, 0.00, 0.00, 170.00),
('160ff8ec-e41d-420b-b3fd-28b1fff8c1dd', '9ab55263-ae90-43ba-a6e2-f8130fbd03ea', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 1.00, 500.00, 500.00, 0.00, 65.00, 565.00),
('1a714d00-fc86-46ea-8d38-13863e90b4ab', 'fce7eb12-aed6-403b-9e97-e720b32f29e1', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 2.00, 365.00, 730.00, 0.00, 0.00, 712.74),
('20d34806-917a-455f-a128-c4f077685ba7', '3af2f3a0-edf5-4300-8d16-bb318df25a9e', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 100.00, 117.00, 1017.00),
('25014c87-c11a-456b-b168-e22d968d4462', '498439be-4d2b-4df7-9403-bf6006c8ed2c', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 50.00, 123.50, 1073.50),
('2506a62f-61b6-4627-ae28-e63e1d7086bf', 'ced875c6-197f-413f-af5d-2c88d736b788', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 50.00, 123.50, 1073.50),
('31fca67f-6e4a-406d-bbc7-bf06870a1a7c', 'cc140771-b724-4e9f-b206-f6356c0b81e8', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 2.00, 20.00, 40.00, 0.23, 0.00, 39.77),
('328b725a-ce56-42dd-9727-7dd33dad8f45', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', 'b1e6e633-3a08-42d9-8da2-fe6d7acdf463', 1.00, 250.00, 250.00, 0.00, 0.00, 250.00),
('3a73d963-5591-4840-8f7c-182f8dad5e1d', 'cc140771-b724-4e9f-b206-f6356c0b81e8', 'b6533a14-377b-4d29-8d77-fa4d2fe883ee', 1.00, 1100.00, 1100.00, 6.27, 0.00, 1093.73),
('3e054b83-ec40-4fb1-8704-252c50292ad9', '353b67a1-a463-4098-b44c-05cd80b15f63', '6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 1.00, 750.00, 750.00, 0.00, 0.00, 750.00),
('42ed7925-7a54-4d8b-bc85-36cee76e88f6', '45ad33ce-256f-4194-92a9-0fb17ca8fcfa', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 1.00, 280.00, 280.00, 0.00, 0.00, 280.00),
('4514b772-ca89-480e-b64a-e4bf44c82636', 'ac5b152b-4ec0-40ea-a1e5-4b800aabd8f5', 'a48cf127-40e9-404e-a9ea-2814657da992', 1.00, 15.00, 15.00, 0.00, 0.00, 15.00),
('45bc3323-897d-46d4-bb2f-a983ae4ffe80', '04eb4b86-5a3c-4bb0-a863-534bdc5d772c', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 50.00, 123.50, 1073.50),
('48ff5935-4480-4499-9367-42252239536e', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 2.00, 270.00, 540.00, 0.00, 0.00, 540.00),
('4a2f8397-a58d-45cf-882c-d00c0b7b8617', 'bf437309-8881-4c90-9034-b021aa8c19df', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 2.00, 365.00, 730.00, 0.00, 0.00, 720.00),
('4cb7b379-f2e4-481c-a7c6-849c32c205e7', '54082647-54aa-4e48-b2da-5659d64b8a1b', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 3.00, 110.00, 330.00, 0.00, 0.00, 330.00),
('520fb6ae-372a-45ba-baa4-cfe08c4ae9ba', '065ab38d-f7bc-4332-bbab-e346935971cb', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 1.00, 20.00, 20.00, 0.00, 0.00, 20.00),
('52b05b36-c9cd-4fc7-9c33-178c90339f59', '2fcb02d4-7308-41b4-8275-bfb740ae9ee5', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 100.00, 117.00, 1017.00),
('54339fa5-e338-4131-b162-ba4dcc6fe7cc', '6c9e2c19-9d01-43eb-8f22-108771c23706', '4bb831da-2e3d-4482-bd62-05df0c171742', 1.00, 550.00, 550.00, 0.00, 0.00, 550.00),
('56672ebf-299b-4354-ada9-b858e2a31d3e', '04f62b7a-a63d-40e9-b19c-cd2bb8ce11cc', '1a8166b8-3107-444c-9634-2f27df10e913', 1.00, 340.00, 340.00, 0.00, 0.00, 336.82),
('5e43808e-9329-4092-88b8-c87b786a53e3', '1d87aa7c-be78-4825-9132-3e205f88039a', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 50.00, 123.50, 1073.50),
('5eafd28d-af51-47ec-ba2a-f3f9441bce6b', '5262b0a7-22f4-4187-8005-c2463b09673c', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 1.00, 500.00, 500.00, 0.00, 65.00, 565.00),
('603e8be0-8961-4ce7-b220-4671b7ae2757', '4cc49e10-031e-4b50-98ce-59f4c523e27e', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 1.00, 500.00, 500.00, 0.00, 65.00, 565.00),
('642c0896-a54d-43e6-add8-83e8b6a1f0e3', '45ad33ce-256f-4194-92a9-0fb17ca8fcfa', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 1.00, 110.00, 110.00, 0.00, 0.00, 110.00),
('646f1121-312e-4c00-bbe5-1da233251f44', 'c170b9e2-2cea-4261-9743-aead6140c519', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 3.00, 110.00, 330.00, 0.00, 0.00, 330.00),
('699b463b-c617-41c4-b4c6-a9614bc3c814', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', 'a48cf127-40e9-404e-a9ea-2814657da992', 12.00, 15.00, 180.00, 0.00, 0.00, 180.00),
('6b804b52-cb6f-413b-af48-212aa17a31a0', '28ac7168-6571-4dca-b74f-1ac0bf34c313', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 1.00, 500.00, 500.00, 0.00, 65.00, 565.00),
('6f5cfba9-2e79-4cd6-97ec-9fb3bcd86344', 'cc140771-b724-4e9f-b206-f6356c0b81e8', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 2.00, 180.00, 360.00, 2.05, 0.00, 357.95),
('746e354c-e374-4759-985c-72bdc7e110a0', 'e55daf02-b11e-4572-b668-73cc95329ba5', '7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 1.00, 250.00, 250.00, 2.25, 0.00, 247.75),
('755179ec-d806-43a1-a269-05dab42fac44', 'a6ee8862-822e-466f-94c6-1ac261422922', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 100.00, 117.00, 1017.00),
('7f706099-faaf-47af-abc1-7b8c641201ad', 'cc140771-b724-4e9f-b206-f6356c0b81e8', '3f737cab-beef-4873-8d28-30f48bb20818', 2.00, 280.00, 560.00, 3.19, 0.00, 556.81),
('8487b6ae-b07b-4eff-9178-32006c1515b2', 'a9a5ed7e-bb2c-45a9-a79f-c4b989ec15a3', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 1.00, 180.00, 180.00, 0.00, 0.00, 180.00),
('85fad983-9538-4d70-b6c0-c808195ca79a', '6c9e2c19-9d01-43eb-8f22-108771c23706', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 3.00, 110.00, 330.00, 0.00, 0.00, 330.00),
('87d1147a-c5bc-4713-8ea2-f257270b7b76', '191dc0b8-e254-4ba2-956b-960fba7080e5', '5599eb46-8e58-4bbc-957c-7bee386693b6', 20.00, 10.00, 200.00, 0.00, 0.00, 170.00),
('8b5267f9-0813-464f-a444-e85b8f638b1d', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', '3f737cab-beef-4873-8d28-30f48bb20818', 2.00, 290.00, 580.00, 0.00, 0.00, 580.00),
('8bba6be0-34f6-4bd0-b753-79c35c391cb2', 'e55daf02-b11e-4572-b668-73cc95329ba5', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 2.00, 270.00, 540.00, 4.85, 0.00, 535.15),
('8dba3119-ffc3-47b6-a2bf-784750e09d58', '5d2f6e0b-a0a5-4f70-bcdc-91358c905522', 'a48cf127-40e9-404e-a9ea-2814657da992', 7.00, 15.00, 105.00, 5.00, 0.00, 100.00),
('8dc637d6-5ab6-4662-b3c9-4a89fcab730c', 'cc140771-b724-4e9f-b206-f6356c0b81e8', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 2.00, 280.00, 560.00, 3.19, 0.00, 556.81),
('8e0a9fed-e288-4be7-acaf-8c4012d48b19', '54082647-54aa-4e48-b2da-5659d64b8a1b', '4bb831da-2e3d-4482-bd62-05df0c171742', 1.00, 550.00, 550.00, 0.00, 0.00, 550.00),
('8eca0162-cd53-4fff-9d6e-9d4ae1f198b6', 'e55daf02-b11e-4572-b668-73cc95329ba5', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 7.00, 357.86, 2505.02, 22.50, 0.00, 2482.52),
('8f072de9-3871-489b-82e3-097d65ec3a55', '90589e45-2814-4770-b182-fabeb4262015', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 1.00, 500.00, 500.00, 0.00, 65.00, 565.00),
('8f596873-a93c-4508-98ba-c98ce3c4ab07', '6eb4ca27-4d3b-4c21-99f7-c0e34a57d03f', '4bb831da-2e3d-4482-bd62-05df0c171742', 1.00, 550.00, 550.00, 0.00, 0.00, 525.56),
('907b59fd-3c02-43d1-949f-61eeb69135d3', '2a086fdc-9ace-4aee-8eb1-35efc69ff30a', '5599eb46-8e58-4bbc-957c-7bee386693b6', 25.00, 10.00, 250.00, 30.00, 0.00, 220.00),
('90c8d240-2783-4fe6-b15f-88a84d34697c', '8bcd5dbb-010c-4cd8-90b6-ffec0003f266', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 100.00, 117.00, 1017.00),
('910ab31a-1297-4d15-8c9b-bc602f5b2ae7', 'c1f0710b-3e47-4e52-9a60-2f2eabd924ce', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 1.00, 500.00, 500.00, 0.00, 65.00, 565.00),
('93bd3996-383c-440e-8594-6a3b50f53d18', '9b1b162d-a279-422d-a5d3-0cdb0b0d0487', 'a48cf127-40e9-404e-a9ea-2814657da992', 1.00, 15.00, 15.00, 0.00, 0.00, 15.00),
('9418e0ff-f744-49f7-9885-42d79a422c54', 'ac5b152b-4ec0-40ea-a1e5-4b800aabd8f5', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 3.00, 110.00, 330.00, 0.00, 0.00, 330.00),
('947625ce-bfd7-45d7-ba2e-188a3003d8b1', 'cc140771-b724-4e9f-b206-f6356c0b81e8', '4bb831da-2e3d-4482-bd62-05df0c171742', 2.00, 550.00, 1100.00, 6.27, 0.00, 1093.73),
('9aa9db83-e268-4374-8f7a-6affd3492507', 'cc140771-b724-4e9f-b206-f6356c0b81e8', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 2.00, 110.00, 220.00, 1.25, 0.00, 218.75),
('9bf908d5-5b38-486c-9164-75a302111576', 'a367c0c5-b986-4fbe-b81e-4831c62767dc', '49225a12-9859-4acf-9e02-7c2f19ed4fda', 3.00, 1000.00, 3000.00, 0.00, 390.00, 3390.00),
('9e4b7b69-8aeb-42ab-8920-705b51fa2431', 'fce7eb12-aed6-403b-9e97-e720b32f29e1', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 1.00, 750.00, 750.00, 0.00, 0.00, 732.26),
('a1a9c6d8-85a5-451d-9042-d08397eb6a2d', '6c9e2c19-9d01-43eb-8f22-108771c23706', 'a48cf127-40e9-404e-a9ea-2814657da992', 1.00, 15.00, 15.00, 0.00, 0.00, 15.00),
('a958fb32-0eab-4072-adbe-11c86ce3bc09', '98a7f933-6da6-4094-8250-048069f90d32', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 1.00, 180.00, 180.00, 0.00, 0.00, 180.00),
('a9c06760-8754-45f2-8668-1de47675499c', '54082647-54aa-4e48-b2da-5659d64b8a1b', 'a48cf127-40e9-404e-a9ea-2814657da992', 1.00, 15.00, 15.00, 0.00, 0.00, 15.00),
('acbfc958-42c1-4804-8f46-321625f855e8', '6eb4ca27-4d3b-4c21-99f7-c0e34a57d03f', 'a48cf127-40e9-404e-a9ea-2814657da992', 10.00, 15.00, 150.00, 0.00, 0.00, 143.33),
('b124b706-44e5-482b-8b90-b9c04e4b19b8', '3faae224-113d-40d1-903b-53545f84ed9c', 'a48cf127-40e9-404e-a9ea-2814657da992', 20.00, 15.00, 300.00, 0.00, 0.00, 280.00),
('b38cf214-d11e-4452-b12a-63c597dbe43f', '81edbda9-388b-4c17-83ae-313c2193fb03', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 100.00, 117.00, 1017.00),
('b4d01160-9d6c-4d17-b2d6-d8a788c0a9e9', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 1.00, 280.00, 280.00, 0.00, 0.00, 280.00),
('b54f2209-42b6-4263-9a8f-8fb2973e2dce', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', '5599eb46-8e58-4bbc-957c-7bee386693b6', 26.00, 10.00, 260.00, 0.00, 0.00, 260.00),
('bdab86e1-b1f6-4381-ac1a-9d6c6148b458', '1bcd5ffb-694e-46f3-9aaa-f886f37dfc97', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 100.00, 117.00, 1017.00),
('bdb88140-c2fd-4a24-9764-9a559d3693bd', 'bbe498f0-e7e4-4f35-b267-3888d06b3e4c', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 50.00, 123.50, 1073.50),
('bfe16dac-d1ee-44d6-9016-0b23b5729f40', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 1.00, 20.00, 20.00, 0.00, 0.00, 20.00),
('c206d748-bb04-44eb-9d07-96543625d9a8', 'e55daf02-b11e-4572-b668-73cc95329ba5', 'a48cf127-40e9-404e-a9ea-2814657da992', 3.00, 15.00, 45.00, 0.40, 0.00, 44.60),
('c32f9bdc-d33e-4a60-8fce-0bd77ffeae8b', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', '4bb831da-2e3d-4482-bd62-05df0c171742', 2.00, 550.00, 1100.00, 0.00, 0.00, 1100.00),
('c85f9ce2-0c1d-4221-96f6-b745e8883344', '3cc99a37-a51f-46bc-ba77-243f16b8108e', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 3.00, 180.00, 540.00, 0.00, 0.00, 540.00),
('c9172167-bb8e-4681-ab5f-909a0c9c745a', '44618e71-aa8e-4074-a881-82fd47b1d050', '5599eb46-8e58-4bbc-957c-7bee386693b6', 10.00, 10.00, 100.00, 0.00, 0.00, 90.00),
('cc055d79-f71c-475c-ad00-5ed497197bbf', '8d1ae08d-dbb8-447f-beb2-5b5d965f92e4', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 100.00, 117.00, 1017.00),
('d05df21c-1cbc-4b56-a0d0-9981c0b1fade', '04f62b7a-a63d-40e9-b19c-cd2bb8ce11cc', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 1.00, 180.00, 180.00, 0.00, 0.00, 178.32),
('d1fbc406-1865-4664-9341-b65d577bf11c', '4fb7f04e-fb5a-440f-8925-a0c9f3f71757', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 2.00, 365.00, 730.00, 0.00, 0.00, 710.00),
('d6bfad27-403f-4114-9e74-afc0b5484d46', '91f4905e-a237-45ac-9cc5-32f6745efd67', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 5.00, 365.00, 1825.00, 0.00, 0.00, 1775.00),
('da341cfa-d443-488f-8e8e-2c8a0880c805', 'cc140771-b724-4e9f-b206-f6356c0b81e8', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 10.00, 365.00, 3650.00, 20.82, 0.00, 3629.18),
('dd121a43-82b2-4ad2-ae9d-3b03383418a9', 'a35e1aa2-b6b9-4ba3-b91e-4ffffd4ac50d', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 1.00, 20.00, 20.00, 0.00, 0.00, 20.00),
('e2b19351-ba78-410c-9cdf-0ee0c8c14829', '52248e67-46f0-4563-a732-1076fddc188d', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 50.00, 123.50, 1073.50),
('e3d152fe-c254-4113-92e2-9b4767edbabf', 'f14d4b7c-6bae-4e7b-b3de-778f5b0d80d3', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 1.00, 500.00, 500.00, 0.00, 65.00, 565.00),
('e4ab62da-669d-43f3-92ac-6c2c122f3366', 'ac5b152b-4ec0-40ea-a1e5-4b800aabd8f5', '4bb831da-2e3d-4482-bd62-05df0c171742', 1.00, 550.00, 550.00, 0.00, 0.00, 550.00),
('e6f89871-3aff-4b77-83de-10e611e5aa08', 'cc140771-b724-4e9f-b206-f6356c0b81e8', 'a48cf127-40e9-404e-a9ea-2814657da992', 20.00, 15.00, 300.00, 1.71, 0.00, 298.29),
('e731082e-ecb6-4503-a858-5baa5e515efe', '98a7f933-6da6-4094-8250-048069f90d32', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 1.00, 110.00, 110.00, 0.00, 0.00, 110.00),
('ecb59699-fd54-45d3-8ce7-1b0fed520b69', 'a4d4e223-563d-4ce9-8e24-df44f83b8929', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 50.00, 123.50, 1073.50),
('f309be14-0111-48ce-9767-82fa0335b779', 'e4bd4d1f-a0c9-4c57-ace8-dcbd15c921f2', '6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 1.00, 750.00, 750.00, 0.00, 0.00, 750.00),
('fc9fef47-f5f0-4b5a-9596-90923495f27f', '6eb4ca27-4d3b-4c21-99f7-c0e34a57d03f', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 10.00, 20.00, 200.00, 0.00, 0.00, 191.11),
('fcaa975d-d6e2-49d5-867b-f70114f6060e', 'ce05f2d5-33a2-4829-8966-2ebdf54e9621', 'a48cf127-40e9-404e-a9ea-2814657da992', 10.00, 15.00, 150.00, 0.00, 0.00, 140.00),
('fd764332-45d5-49de-8f58-1bfce8c62e26', 'f00a6da2-98c1-467b-b50c-7935f7dfdb04', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 2.00, 500.00, 1000.00, 100.00, 117.00, 1017.00),
('fec630f3-d3be-4966-b9c1-9c67818c0a37', 'd4813390-9171-4285-af4e-3ea14f3d4fce', '004897c6-8a0f-446c-acc1-81a8cc6ce89a', 1.00, 500.00, 500.00, 0.00, 65.00, 565.00),
('ff9997be-79e6-456c-9a29-30e16e274b38', 'fb729613-943e-4ea2-99a2-8950d64f8ecd', '5e21fcfb-5077-4a22-97f3-574bda1923f6', 1.00, 300.00, 300.00, 0.00, 0.00, 300.00),
('ff9a1d57-aedb-40a8-b4d0-987949e9e344', 'cdc0e5e7-d35b-47cc-81f9-389abfd6f7e3', '4376ce7f-e0ae-498d-849d-0f0b494d84a7', 1.00, 100.00, 100.00, 0.00, 0.00, 100.00);

-- --------------------------------------------------------

--
-- Table structure for table `pos_payments`
--

CREATE TABLE `pos_payments` (
  `id` varchar(36) NOT NULL,
  `pos_id` varchar(36) NOT NULL,
  `payment_mode` enum('cash','qr','card','bank') NOT NULL,
  `account_id` varchar(36) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pos_payments`
--

INSERT INTO `pos_payments` (`id`, `pos_id`, `payment_mode`, `account_id`, `amount`, `reference_no`) VALUES
('08bdbe71-dd0c-404c-ad9e-d5e3ce4d0bd3', '3cc99a37-a51f-46bc-ba77-243f16b8108e', 'cash', 'acc-1010', 540.00, NULL),
('161b2e8f-aa92-4711-9b4e-ab559811a441', '91f4905e-a237-45ac-9cc5-32f6745efd67', 'cash', 'acc-1010', 1775.00, NULL),
('1a6a8ead-0c69-446b-8368-301735d5cf52', '191dc0b8-e254-4ba2-956b-960fba7080e5', 'cash', 'acc-1010', 170.00, NULL),
('1f4fa782-bf8c-40ab-b505-30215758b58c', '28ac7168-6571-4dca-b74f-1ac0bf34c313', 'cash', 'acc-1010', 565.00, 'CASH-REF-2'),
('230ad930-6034-4f49-b498-0930c2c6c597', '52248e67-46f0-4563-a732-1076fddc188d', 'cash', 'acc-1010', 1073.50, 'CASH-REF-2-UPD'),
('23267e42-e2d9-4a98-a85c-e8bfdac5c7fe', 'cc140771-b724-4e9f-b206-f6356c0b81e8', 'cash', 'acc-1010', 3905.00, NULL),
('23783be7-6229-45b2-8ed4-4ff940872a3c', 'f14d4b7c-6bae-4e7b-b3de-778f5b0d80d3', 'cash', 'acc-1010', 565.00, 'CASH-REF-2'),
('2612c5bd-d84c-44bb-9ac4-329a0e5bbdd1', '498439be-4d2b-4df7-9403-bf6006c8ed2c', 'cash', 'acc-1010', 1073.50, 'CASH-REF-2-UPD'),
('275f9ad5-67bb-4d7b-887d-14c9c407dde3', 'e4bd4d1f-a0c9-4c57-ace8-dcbd15c921f2', 'qr', 'acc-1030', 750.00, NULL),
('28e63e82-0f9a-4293-aa28-04a764203886', 'fce7eb12-aed6-403b-9e97-e720b32f29e1', 'qr', 'acc-1030', 1445.00, NULL),
('2a393027-1aff-4a59-8e83-0b2c307bb65c', '1bcd5ffb-694e-46f3-9aaa-f886f37dfc97', 'cash', 'acc-1010', 1017.00, 'CASH-REF-1'),
('31d0e0eb-4aca-4e06-a84d-1e7807018c6d', '8bcd5dbb-010c-4cd8-90b6-ffec0003f266', 'cash', 'acc-1010', 1017.00, 'CASH-REF-1'),
('38c6aec0-7c7b-4c5d-b39b-3a7f381c9b2b', '98a7f933-6da6-4094-8250-048069f90d32', 'cash', 'acc-1010', 290.00, NULL),
('38f3d437-b45f-4442-b5c7-4f35e164ef5a', '5262b0a7-22f4-4187-8005-c2463b09673c', 'cash', 'acc-1010', 565.00, 'CASH-REF-3'),
('442d187a-9c86-44a5-84c5-d07957205cae', '4fb7f04e-fb5a-440f-8925-a0c9f3f71757', 'qr', 'acc-1030', 710.00, NULL),
('445a50b8-4c85-4eb2-a585-8e8078bf670c', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', 'bank', 'acc-1020', 710.00, NULL),
('4d129f92-e990-459b-ae70-7c86093876cc', 'a6ee8862-822e-466f-94c6-1ac261422922', 'cash', 'acc-1010', 1017.00, 'CASH-REF-1'),
('4f45ca9e-50e8-449f-912c-902f39942e13', '90589e45-2814-4770-b182-fabeb4262015', 'cash', 'acc-1010', 565.00, 'CASH-REF-3'),
('53a78b30-55bf-40d5-abcb-50cbabfcc02e', 'ac5b152b-4ec0-40ea-a1e5-4b800aabd8f5', 'cash', 'acc-1010', 895.00, NULL),
('5a3321e0-2c7c-4175-b99a-fb489ba638e3', '8d1ae08d-dbb8-447f-beb2-5b5d965f92e4', 'cash', 'acc-1010', 1017.00, 'CASH-REF-1'),
('5a9e2045-272c-48b9-a70d-44539c4facbf', '4cc49e10-031e-4b50-98ce-59f4c523e27e', 'cash', 'acc-1010', 565.00, 'CASH-REF-2'),
('5cb5f031-4ac5-4866-9481-80f3f0a613cb', '6c9e2c19-9d01-43eb-8f22-108771c23706', 'cash', 'acc-1010', 895.00, NULL),
('5d57544c-8d7c-49d8-90be-404a43152374', '04eb4b86-5a3c-4bb0-a863-534bdc5d772c', 'cash', 'acc-1010', 1073.50, 'CASH-REF-2-UPD'),
('5d7655ab-5b6d-45fb-8ed2-fa2af957b3b3', 'f00a6da2-98c1-467b-b50c-7935f7dfdb04', 'cash', 'acc-1010', 1017.00, 'CASH-REF-1'),
('60791f93-c6c6-42df-9af5-2c73dab80d0d', 'fb729613-943e-4ea2-99a2-8950d64f8ecd', 'bank', 'acc-1020', 300.00, NULL),
('6878bd91-e6cb-42a7-9525-2d359eface56', 'ce05f2d5-33a2-4829-8966-2ebdf54e9621', 'cash', 'acc-1010', 140.00, NULL),
('694af107-6b7a-4838-a2cb-c10f02747996', '04f62b7a-a63d-40e9-b19c-cd2bb8ce11cc', 'cash', 'acc-1010', 1060.00, NULL),
('6af61e59-52d1-4d0f-a0cb-af6c2ddd9c0e', 'ced875c6-197f-413f-af5d-2c88d736b788', 'cash', 'acc-1010', 1073.50, 'CASH-REF-2-UPD'),
('6e1ee5e5-382d-41c2-a37a-d69bff38e157', 'bbe498f0-e7e4-4f35-b267-3888d06b3e4c', 'cash', 'acc-1010', 1073.50, 'CASH-REF-2-UPD'),
('76cd394c-eebe-46c7-8f91-df90368f6f22', '9b1b162d-a279-422d-a5d3-0cdb0b0d0487', 'cash', 'acc-1010', 15.00, NULL),
('7bc9600e-e4fd-4f43-9f38-5d9e9766ce4d', '9ab55263-ae90-43ba-a6e2-f8130fbd03ea', 'cash', 'acc-1010', 565.00, 'CASH-REF-3'),
('7f001544-7b7a-491e-8b00-c2bab0bd4c17', '2fcb02d4-7308-41b4-8275-bfb740ae9ee5', 'cash', 'acc-1010', 1017.00, 'CASH-REF-1'),
('81820b6e-e33c-4af5-a8a8-3fe4b9b93129', '3b8882b6-1a77-4523-b8eb-ac5c32aa48a7', 'cash', 'acc-1010', 3677.60, NULL),
('8220ac5d-80ea-4aad-8fee-4c9e1657faf1', 'e55daf02-b11e-4572-b668-73cc95329ba5', 'bank', 'acc-1020', 1520.00, NULL),
('8b1276f8-b493-4337-b403-ef45b35f1660', '54082647-54aa-4e48-b2da-5659d64b8a1b', 'cash', 'acc-1010', 895.00, NULL),
('8c7130b8-3f35-4c95-b843-d49a5874014a', 'a35e1aa2-b6b9-4ba3-b91e-4ffffd4ac50d', 'cash', 'acc-1010', 20.00, NULL),
('968e097c-1b11-44f4-8e9d-5277b2820fe4', '81edbda9-388b-4c17-83ae-313c2193fb03', 'cash', 'acc-1010', 1017.00, 'CASH-REF-1'),
('96946b88-174d-4742-82e3-39e705809740', '44618e71-aa8e-4074-a881-82fd47b1d050', 'qr', 'acc-1030', 90.00, NULL),
('96bfc85a-9016-4e56-b78a-ceaf2592968c', '1d87aa7c-be78-4825-9132-3e205f88039a', 'cash', 'acc-1010', 1073.50, 'CASH-REF-2-UPD'),
('98884484-bec9-455c-a7d9-6f71ac7ea3f4', 'e55daf02-b11e-4572-b668-73cc95329ba5', 'cash', 'acc-1010', 1790.02, NULL),
('ad7eac07-cba8-4f6b-bee7-d7b5c9f872c3', 'bf437309-8881-4c90-9034-b021aa8c19df', 'qr', 'acc-1030', 720.00, NULL),
('b235639b-276b-44ae-8411-99a54dfaf2ec', 'cc140771-b724-4e9f-b206-f6356c0b81e8', 'bank', 'acc-1020', 3940.00, NULL),
('b92cb1ec-3c6b-459d-8ba6-e59b96263052', 'a9a5ed7e-bb2c-45a9-a79f-c4b989ec15a3', 'cash', 'acc-1010', 180.00, NULL),
('c20737e2-a2f1-48a2-9e7f-d56848b55a8d', '2a086fdc-9ace-4aee-8eb1-35efc69ff30a', 'cash', 'acc-1010', 220.00, NULL),
('ccd02067-0446-49ec-95b5-dad49a96fa4b', '45ad33ce-256f-4194-92a9-0fb17ca8fcfa', 'cash', 'acc-1010', 390.00, NULL),
('ce1f518a-cd6c-48e8-bb5e-317b9c6bab76', '5d2f6e0b-a0a5-4f70-bcdc-91358c905522', 'cash', 'acc-1010', 100.00, NULL),
('d3b70315-5957-4d25-b511-4c21c940e733', '065ab38d-f7bc-4332-bbab-e346935971cb', 'cash', 'acc-1010', 20.00, NULL),
('d6ccb083-8e82-4614-a526-a509b8d15238', 'f8d5570c-2be4-4eb2-a9b9-35543776e90d', 'cash', 'acc-1010', 170.00, NULL),
('d863e290-569d-4f6c-a982-e1c018a20ce7', 'a4d4e223-563d-4ce9-8e24-df44f83b8929', 'cash', 'acc-1010', 1073.50, 'CASH-REF-2-UPD'),
('d9c6dc56-5e9d-491a-b227-701595b5abda', 'c170b9e2-2cea-4261-9743-aead6140c519', 'cash', 'acc-1010', 330.00, NULL),
('dd021944-f31c-404f-a01b-50d88fc75918', 'cdc0e5e7-d35b-47cc-81f9-389abfd6f7e3', 'cash', 'acc-1010', 100.00, NULL),
('e06893bf-c847-4280-aa0c-78759ec1205e', 'a367c0c5-b986-4fbe-b81e-4831c62767dc', 'bank', 'acc-1020', 710.00, NULL),
('e4681774-6a64-45b2-99b2-103319c65c1b', '3faae224-113d-40d1-903b-53545f84ed9c', 'cash', 'acc-1010', 280.00, NULL),
('e7a93423-73d1-4556-80f3-f710591647f9', 'a367c0c5-b986-4fbe-b81e-4831c62767dc', 'cash', 'acc-1010', 12938.50, NULL),
('f13f99a1-8302-4d8c-97bc-175679027c86', 'c1f0710b-3e47-4e52-9a60-2f2eabd924ce', 'cash', 'acc-1010', 565.00, 'CASH-REF-2'),
('f270d574-1022-48ae-b4be-fafc262b97c1', '3af2f3a0-edf5-4300-8d16-bb318df25a9e', 'cash', 'acc-1010', 1017.00, 'CASH-REF-1'),
('f39944df-1194-48c9-8c36-77c31afafdad', '6eb4ca27-4d3b-4c21-99f7-c0e34a57d03f', 'cash', 'acc-1010', 860.00, NULL),
('f5f861d8-6ed9-4250-b954-0a3cf4cd253e', '353b67a1-a463-4098-b44c-05cd80b15f63', 'qr', 'acc-1030', 750.00, NULL),
('ff27a568-8150-4832-840f-d6620a85b0ff', 'd4813390-9171-4285-af4e-3ea14f3d4fce', 'cash', 'acc-1010', 565.00, 'CASH-REF-2');

-- --------------------------------------------------------

--
-- Table structure for table `pos_returns`
--

CREATE TABLE `pos_returns` (
  `id` varchar(36) NOT NULL,
  `original_pos_id` varchar(36) NOT NULL,
  `return_date` date NOT NULL,
  `total_return_amount` decimal(14,2) NOT NULL,
  `refund_mode` enum('cash','qr','credit_note') NOT NULL,
  `status` enum('completed','reversed') DEFAULT 'completed',
  `created_by` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_return_items`
--

CREATE TABLE `pos_return_items` (
  `id` varchar(36) NOT NULL,
  `return_id` varchar(36) NOT NULL,
  `item_id` varchar(36) NOT NULL,
  `quantity` decimal(14,2) NOT NULL,
  `rate` decimal(14,2) NOT NULL,
  `amount` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reference_codes`
--

CREATE TABLE `reference_codes` (
  `id` varchar(36) NOT NULL,
  `type` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `value` decimal(12,4) DEFAULT 0.0000,
  `symbol` varchar(10) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reference_codes`
--

INSERT INTO `reference_codes` (`id`, `type`, `name`, `code`, `value`, `symbol`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
('01e6a28d-9b57-437e-84f9-94a75eeb19a6', 'category', 'Wine', 'Wine', 0.0000, NULL, '', 1, '2026-05-11 14:23:00', '2026-05-11 14:24:03'),
('0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 'tax_code', 'Non-Taxable', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:41:42', '2026-05-14 13:41:42'),
('2795353a-e3db-436a-adaa-8f4b60cddb50', 'units', 'other', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:41:42', '2026-05-14 13:41:42'),
('2ae5110e-1887-4079-8d5b-b7355d406691', 'category', 'other', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:42:16', '2026-05-14 13:42:16'),
('5581720a-90fe-4f2c-8cbb-56d1c7a3da56', 'category', 'Cigaratte', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('584b1f1e-25b4-4d1a-83b9-b448d6a964f4', 'category', 'Local', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('5b907b99-9627-420c-bda6-70853db398bb', 'category', 'Rum', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('62b97a58-0df5-41d9-af9f-ff57a7b08d79', 'category', 'Juices', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('71acc735-19e5-4a9b-9f59-7a7e54289789', 'category', 'Vodka', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('738b9b15-7d82-4f6e-81e0-036df7634221', 'category', 'Local Wine', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('99e11903-0216-45e7-a233-40067e22da37', 'category', 'Energy Drinks', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('9b1656e9-ec64-40ab-b7a8-da784752d6a3', 'tax_code', 'VAT 13%', NULL, 13.0000, NULL, NULL, 1, '2026-05-14 13:41:42', '2026-05-14 13:41:42'),
('a7873e69-1f4e-48d4-8183-2d88642cade0', 'category', 'Soft Drinks', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('ccaa5d61-5fdd-4cd2-924d-6eff7b5999de', 'category', 'Beer', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('d09f7e54-9661-4a8d-8a06-30545e0e0106', 'status', 'Active', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:41:00', '2026-05-14 13:41:00'),
('d14f742a-cde3-4419-abf2-f229b5893983', 'units', 'pc', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('da0ce599-2ee4-4614-886a-fea3f3bf8234', 'category', 'Hukka', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('e11d2b56-f508-49de-ad55-925461cf0900', 'units', 'btl', 'BTL', 0.0000, NULL, '', 1, '2026-05-11 14:23:06', '2026-05-11 14:23:06'),
('e2ba19f4-744f-43d8-8d09-8fbc7c9f3db0', 'units', 'bottle', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:41:42', '2026-05-14 13:41:42'),
('f4015fda-14e6-405c-8d23-9228975eb6e8', 'category', 'Whiskey', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39'),
('f682d657-b67f-4b5f-9d12-8baf2b2d1647', 'category', 'Water', NULL, 0.0000, NULL, NULL, 1, '2026-05-14 13:33:39', '2026-05-14 13:33:39');

-- --------------------------------------------------------

--
-- Table structure for table `system_info`
--

CREATE TABLE `system_info` (
  `id` int(30) NOT NULL,
  `meta_field` text NOT NULL,
  `meta_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_info`
--

INSERT INTO `system_info` (`id`, `meta_field`, `meta_value`) VALUES
(1, 'name', 'MNS Liquors'),
(2, 'short_name', 'MNS'),
(3, 'logo', 'uploads/logo.jpg'),
(4, 'user_avatar', 'uploads/user_avatar.jpg'),
(5, 'cover', 'uploads/cover.png'),
(6, 'content', ''),
(7, 'address', 'Gokarneshwor 9,\r\nKathmandu Nepal'),
(8, 'contact', '9801987220'),
(9, 'pan_no', '123456789'),
(10, 'email', 'mnsliquors@gmail.com'),
(11, 'print_title', 'Tax Invoice'),
(12, 'print_logo_show', '0'),
(13, 'print_header_cols', '2'),
(14, 'print_remarks_pos', 'above'),
(15, 'print_footer_text', 'Page 1 of 1'),
(16, 'git_path', 'C:\\Users\\USERE\\AppData\\Local\\GitHubDesktop\\app-3.5.6\\resources\\app\\git\\cmd\\git.exe'),
(17, 'mysql_bin', 'C:\\xampp\\mysql\\bin\\'),
(18, 'website', ''),
(19, 'signatory_label', 'Authorized Signatory'),
(20, 'date_format', 'Y-m-d'),
(21, 'decimal_places', '2'),
(22, 'DataTables_Table_0_length', '25'),
(23, 'ref_customer_payment_prefix', 'CPAY'),
(24, 'ref_customer_payment_sep', '-'),
(25, 'ref_customer_payment_next', '3'),
(26, 'ref_customer_payment_pad', '5'),
(27, 'ref_customer_prefix', 'C'),
(28, 'ref_customer_sep', '-'),
(29, 'ref_customer_next', '37'),
(30, 'ref_customer_pad', '5'),
(31, 'ref_expense_prefix', 'EXP'),
(32, 'ref_expense_sep', '-'),
(33, 'ref_expense_next', '2'),
(34, 'ref_expense_pad', '5'),
(35, 'ref_item_prefix', 'I'),
(36, 'ref_item_sep', '-'),
(37, 'ref_item_next', '31'),
(38, 'ref_item_pad', '5'),
(39, 'ref_journal_entry_prefix', 'JV'),
(40, 'ref_journal_entry_sep', '-'),
(41, 'ref_journal_entry_next', '2'),
(42, 'ref_journal_entry_pad', '5'),
(43, 'ref_purchase_order_prefix', 'PO'),
(44, 'ref_purchase_order_sep', '-'),
(45, 'ref_purchase_order_next', '1'),
(46, 'ref_purchase_order_pad', '5'),
(47, 'ref_customer_invoice_prefix', 'SI'),
(48, 'ref_customer_invoice_sep', '-'),
(49, 'ref_customer_invoice_next', '5'),
(50, 'ref_customer_invoice_pad', '5'),
(51, 'ref_vendor_bill_prefix', 'VI'),
(52, 'ref_vendor_bill_sep', '-'),
(53, 'ref_vendor_bill_next', '8'),
(54, 'ref_vendor_bill_pad', '5'),
(55, 'ref_vendor_payment_prefix', 'VPAY'),
(56, 'ref_vendor_payment_sep', '-'),
(57, 'ref_vendor_payment_next', '7'),
(58, 'ref_vendor_payment_pad', '5'),
(59, 'ref_vendor_prefix', 'V'),
(60, 'ref_vendor_sep', '-'),
(61, 'ref_vendor_next', '26'),
(62, 'ref_vendor_pad', '5'),
(63, 'default_test', 'test_val'),
(64, 'default_ar_account', 'acc-1100'),
(65, 'default_ap_account', 'acc-2100'),
(66, 'default_asset_account', 'acc-1200'),
(67, 'default_cogs_account', 'acc-5100'),
(68, 'default_income_account', 'acc-4100'),
(69, 'default_expense_account', 'acc-6170'),
(70, 'default_tax_account', 'acc-2200'),
(71, 'default_discount_account', 'acc-6160'),
(72, 'default_profit_account', ''),
(73, 'default_bank_account', 'acc-1020'),
(74, 'default_cash_account', 'acc-1010'),
(75, 'default_customer_id', '64e084cd-4fdd-409b-9137-56e30c685640'),
(76, 'default_vendor_id', ''),
(77, 'default_income_account', 'acc-4100'),
(78, 'default_tax_account', 'acc-2200'),
(79, 'default_discount_account', 'acc-6160'),
(80, 'default_cogs_account', 'acc-5100'),
(81, 'default_asset_account', 'acc-1200'),
(82, 'default_cash_account', 'acc-1010'),
(83, 'default_bank_account', 'acc-1020'),
(84, 'default_income_account', 'acc-4100'),
(85, 'default_tax_account', 'acc-2200'),
(86, 'default_discount_account', 'acc-6160'),
(87, 'default_cogs_account', 'acc-5100'),
(88, 'default_asset_account', 'acc-1200'),
(89, 'default_cash_account', 'acc-1010'),
(90, 'default_bank_account', 'acc-1020'),
(91, 'default_change_account', 'acc-1010'),
(92, 'ref_Journal_next', '1'),
(93, 'ref_inventory_adjustment_next', '4'),
(94, 'ref_account_transfer_next', '3'),
(95, 'fiscal_year_start', '2026-07-17'),
(96, 'fiscal_year_end', '2027-07-16');

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(30) NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `action` text NOT NULL,
  `action_type` varchar(50) DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `module` varchar(255) NOT NULL,
  `ref_id` varchar(100) NOT NULL,
  `field_name` varchar(100) DEFAULT NULL,
  `old_data` text DEFAULT NULL,
  `new_data` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `device_info` text DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transaction_headers`
--

CREATE TABLE `transaction_headers` (
  `id` varchar(36) NOT NULL,
  `txn_number` varchar(30) NOT NULL,
  `txn_type` enum('vendor_bill','customer_invoice','customer_payment','vendor_payment','account_transfer','expense','cash_denomination','inventory_adjustment','Journal') NOT NULL,
  `txn_date` date NOT NULL,
  `fiscal_year` int(11) NOT NULL,
  `fiscal_month` int(11) NOT NULL,
  `fiscal_period` char(7) NOT NULL,
  `status` enum('draft','approved','posted','voided','open','paid','partial') NOT NULL DEFAULT 'draft',
  `reference_number` varchar(50) DEFAULT NULL,
  `memo` text DEFAULT NULL,
  `created_by` varchar(36) NOT NULL,
  `approved_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `posted_at` timestamp NULL DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `party_id` varchar(36) DEFAULT NULL,
  `party_type` enum('customer','vendor','user') DEFAULT NULL,
  `net_amount` decimal(14,2) DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source` varchar(50) DEFAULT NULL,
  `is_readonly` tinyint(1) NOT NULL DEFAULT 0,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_headers`
--

INSERT INTO `transaction_headers` (`id`, `txn_number`, `txn_type`, `txn_date`, `fiscal_year`, `fiscal_month`, `fiscal_period`, `status`, `reference_number`, `memo`, `created_by`, `approved_by`, `created_at`, `posted_at`, `is_deleted`, `party_id`, `party_type`, `net_amount`, `updated_at`, `source`, `is_readonly`, `is_locked`) VALUES
('1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'SI-00002', 'customer_invoice', '2026-07-18', 2026, 7, '2026-07', 'open', NULL, '', 'usr-admin-001', NULL, '2026-07-18 14:01:03', NULL, 0, 'c666d6db-baa6-4692-810d-e23509f4e1c5', 'customer', 940.00, '2026-07-19 07:06:56', NULL, 0, 0),
('13ef5b2a-743e-43e0-b4ce-886ff8c09e86', 'POS-PAY-20260717', 'customer_payment', '2026-07-17', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-17 13:40:26', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 7845.00, '2026-07-20 07:23:29', NULL, 0, 0),
('161e3d0c-e842-4082-ae2c-729d5dc7e4bd', 'VPAY-00002', 'vendor_payment', '2026-07-16', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-17 14:19:55', NULL, 0, NULL, NULL, 0.00, '2026-07-19 16:16:37', NULL, 0, 0),
('184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'VI-00001', 'vendor_bill', '2026-07-16', 2026, 7, '2026-07', 'paid', 'VI-00001', '', 'usr-admin-001', NULL, '2026-07-16 11:21:22', NULL, 0, NULL, NULL, 0.00, '2026-07-19 16:16:37', NULL, 0, 0),
('19e8e0a8-4074-4ae2-8e97-8c409b52f162', 'POS-PAY-20260719-DEL-e7386fa4', 'customer_payment', '2026-07-19', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-19 06:26:46', NULL, 1, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 3425.02, '2026-07-19 15:48:41', NULL, 0, 0),
('1fcbec29-de11-4e6a-8bed-589b251a75b3', 'POS-20260718-3289-DEL-e46db66d', 'customer_invoice', '2026-07-18', 2026, 7, '2026-07', 'open', NULL, NULL, 'usr-admin-001', NULL, '2026-07-18 10:29:04', NULL, 1, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 0.00, '2026-07-18 12:40:53', NULL, 0, 0),
('2e0536d3-1fc0-496e-8ab1-62010c9d0a39', 'EXP-00001', 'expense', '2026-07-19', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-19 07:16:12', NULL, 0, 'tea', 'user', 130.00, '2026-07-19 15:09:58', NULL, 0, 0),
('2ed95978-897d-4a89-98b9-4c20b14e26a2', 'PAY-POS-20260720', 'customer_payment', '2026-07-20', 2026, 7, '2026-07', 'posted', NULL, NULL, 'usr-admin-001', NULL, '2026-07-20 10:47:52', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 320.00, '2026-07-20 10:50:14', NULL, 0, 0),
('367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'POS-SUM-20260717', 'customer_invoice', '2026-07-17', 2026, 7, '2026-07', 'paid', NULL, '', 'usr-admin-001', NULL, '2026-07-17 13:40:26', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 7845.00, '2026-07-20 07:23:29', NULL, 0, 0),
('4065ba21-c172-4bd8-8f13-e9d0867be8f4', 'CPAY-00002', 'customer_payment', '2026-07-19', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-19 06:37:29', NULL, 0, NULL, NULL, 0.00, '2026-07-19 06:37:29', NULL, 0, 0),
('40caee36-c141-41ff-b7cd-db1d58271191', 'PAY-POS-20260719', 'customer_payment', '2026-07-19', 2026, 7, '2026-07', 'posted', NULL, NULL, 'usr-admin-001', NULL, '2026-07-19 15:47:16', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 805.00, '2026-07-19 16:11:00', NULL, 0, 0),
('42397e38-06a7-41c4-b1ee-9470f1c137e8', 'CD-20260718-7249', 'cash_denomination', '2026-07-18', 2026, 7, '2026-07', 'posted', NULL, NULL, 'usr-admin-001', NULL, '2026-07-18 09:27:45', NULL, 0, 'Shift_A', NULL, 13595.00, '2026-07-18 09:27:45', NULL, 0, 0),
('4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'VI-00005', 'vendor_bill', '2026-07-18', 2026, 7, '2026-07', 'paid', 'VI-00005', '', 'usr-admin-001', NULL, '2026-07-18 08:53:41', NULL, 0, NULL, NULL, 0.00, '2026-07-18 08:54:00', NULL, 0, 0),
('51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'SI-00003', 'customer_invoice', '2026-07-18', 2026, 7, '2026-07', 'open', NULL, '', 'usr-admin-001', NULL, '2026-07-18 14:32:36', NULL, 0, '030e6c08-51b5-4a1e-b35a-a16514a36485', 'customer', 715.00, '2026-07-18 14:32:36', NULL, 0, 0),
('5485bd0d-9f39-4898-adb9-7b6e5a9f5a9e', 'POS-PAY-20260718-DEL-8bcc91f6', 'customer_payment', '2026-07-18', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-18 12:21:40', NULL, 1, '030e6c08-51b5-4a1e-b35a-a16514a36485', 'customer', 3390.00, '2026-07-18 12:36:15', NULL, 0, 0),
('564d9249-32bc-4548-8901-9bcee3420994', 'POS-PAY-20260718-3289-DEL-6a6e', 'customer_payment', '2026-07-18', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-18 10:29:04', NULL, 1, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 0.00, '2026-07-18 12:35:33', NULL, 0, 0),
('5d27e5fa-2465-463b-bbd4-388435dc2a16', 'VPAY-00005', 'vendor_payment', '2026-07-18', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-18 08:54:00', NULL, 0, NULL, NULL, 0.00, '2026-07-18 08:54:00', NULL, 0, 0),
('6a76457e-1018-4649-930c-bf5c82e39ac4', 'VPAY-00003', 'vendor_payment', '2026-07-16', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-17 14:39:35', NULL, 0, NULL, NULL, 0.00, '2026-07-19 16:16:37', NULL, 0, 0),
('74b88ca2-7b1f-45d2-af55-090607b85296', 'ADJ-0002', 'inventory_adjustment', '2026-07-18', 2026, 7, '2026-07', 'posted', 'ADJ-0002', 'gorkha damaged and OD exchanged for 3 qtr to 1 half ', 'usr-admin-001', NULL, '2026-07-17 15:02:40', NULL, 0, 'acc-5200', '', -1000.06, '2026-07-20 10:51:08', NULL, 0, 0),
('84b42a30-42f7-429b-9041-20ba1aef7642', 'VPAY-00006', 'vendor_payment', '2026-07-19', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-19 06:28:32', NULL, 0, NULL, NULL, 0.00, '2026-07-19 06:28:32', NULL, 0, 0),
('8661472c-e952-464c-ab75-f89a20b45c45', 'ADJ-0003', 'inventory_adjustment', '2026-07-17', 2026, 7, '2026-07', 'posted', 'ADJ-0003', 'For hukka coil ', 'usr-admin-001', NULL, '2026-07-20 10:49:42', NULL, 0, 'acc-3100', '', 1950.00, '2026-07-20 10:51:30', NULL, 0, 0),
('9493494b-e8fe-482d-9c22-638b6e31492b', 'VPAY-00004', 'vendor_payment', '2026-07-18', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-18 08:50:16', NULL, 0, NULL, NULL, 0.00, '2026-07-18 08:50:16', NULL, 0, 0),
('99ef144b-131a-4762-8f41-2547d67a71b0', 'POS-SUM-20260718', 'customer_invoice', '2026-07-18', 2026, 7, '2026-07', 'paid', NULL, '', 'usr-admin-001', NULL, '2026-07-18 12:51:47', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 4475.00, '2026-07-18 16:06:06', NULL, 0, 0),
('a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'SI-00001', 'customer_invoice', '2026-07-17', 2026, 7, '2026-07', 'paid', NULL, '', 'usr-admin-001', NULL, '2026-07-17 13:56:26', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 5170.00, '2026-07-19 06:37:29', NULL, 0, 0),
('a2648690-e770-43e7-9acb-9c6654dad464', 'SI-00004-DEL-d17b5f9a', 'customer_invoice', '2026-07-15', 2026, 7, '2026-07', 'open', NULL, 'old payment not received', 'usr-admin-001', NULL, '2026-07-19 07:08:04', NULL, 1, '48e56ded-e263-41de-968d-f134b7c22deb', 'customer', 1550.00, '2026-07-19 08:29:14', NULL, 0, 0),
('ad0b83cc-3d86-4a6c-be55-c4953a6098ba', 'VI-00002', 'vendor_bill', '2026-07-17', 2026, 7, '2026-07', 'paid', 'VI-00002', '', 'usr-admin-001', NULL, '2026-07-17 13:43:08', NULL, 0, NULL, NULL, 0.00, '2026-07-17 14:40:24', NULL, 0, 0),
('af040956-157e-4c5c-b8e7-110b4ebac66a', 'ADJ-0001', 'inventory_adjustment', '2026-07-17', 2026, 7, '2026-07', 'posted', 'ADJ-0001', 'Opening balance for stock', 'usr-admin-001', NULL, '2026-07-16 11:34:30', NULL, 0, 'bbe5c26b-091b-4b2c-939c-8a18220bcc5a', '', 80506.89, '2026-07-20 10:50:44', NULL, 0, 0),
('b4946e1c-3bb5-4340-b266-cc042370bb1f', 'POS-SUM-20260718-DEL-66d9d116', 'customer_invoice', '2026-07-18', 2026, 7, '2026-07', 'open', NULL, 'Edited by test', 'usr-admin-001', NULL, '2026-07-18 12:21:41', NULL, 1, '030e6c08-51b5-4a1e-b35a-a16514a36485', 'customer', 3390.00, '2026-07-18 12:40:38', NULL, 0, 0),
('b5a93114-9471-4b73-9f90-6e54d85faf7a', 'VI-00003', 'vendor_bill', '2026-07-16', 2026, 7, '2026-07', 'paid', 'VI-00003', '', 'usr-admin-001', NULL, '2026-07-17 14:19:37', NULL, 0, NULL, NULL, 0.00, '2026-07-19 16:16:37', NULL, 0, 0),
('bd96863a-ad0f-4bc7-8d87-174f5e20dbe8', 'POS-PAY-20260718', 'customer_payment', '2026-07-18', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-18 12:51:47', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 4475.00, '2026-07-18 16:06:06', NULL, 0, 0),
('c562ad2f-f088-4e49-81e2-bd7267eb7c95', 'POS-PAY-20260716', 'customer_payment', '2026-07-16', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-16 12:35:01', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 0.00, '2026-07-19 16:16:37', NULL, 0, 0),
('c88a9f13-93bd-4be6-9669-ae18cd279955', 'POS-SUM-20260716', 'customer_invoice', '2026-07-16', 2026, 7, '2026-07', 'paid', NULL, '', 'usr-admin-001', NULL, '2026-07-16 12:35:01', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 0.00, '2026-07-19 16:16:37', NULL, 0, 0),
('c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', 'INV-POS-20260720', 'customer_invoice', '2026-07-20', 2026, 7, '2026-07', 'paid', NULL, NULL, 'usr-admin-001', NULL, '2026-07-20 10:47:52', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 320.00, '2026-07-20 10:50:14', NULL, 0, 0),
('d062aea6-6bef-4a34-9ebc-79fe4aa1add9', 'XFER-0001', 'account_transfer', '2026-07-17', 2026, 7, '2026-07', 'posted', 'XFER-0001', 'automatically transfered to bank ', 'usr-admin-001', NULL, '2026-07-17 14:51:44', NULL, 0, 'acc-1030', '', 4465.00, '2026-07-17 14:51:44', NULL, 0, 0),
('d7efebb8-20c4-47e6-a841-aff97ea5ecd7', 'CD-20260720-7779', 'cash_denomination', '2026-07-20', 2026, 7, '2026-07', 'posted', NULL, NULL, 'usr-admin-001', NULL, '2026-07-20 07:33:26', NULL, 0, 'Shift_A', NULL, 14440.00, '2026-07-20 07:33:26', NULL, 0, 0),
('d94529b1-a60a-424c-b22a-2c2a7c073fad', 'JV-00001-DEL-be248a6f', 'Journal', '2026-07-19', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-19 07:39:18', NULL, 1, NULL, NULL, 11000.00, '2026-07-19 07:45:41', NULL, 0, 0),
('dc85e963-3b8d-47f3-a40d-0052832989a4', 'VPAY-00001', 'vendor_payment', '2026-07-17', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-17 13:43:27', NULL, 0, NULL, NULL, 0.00, '2026-07-17 13:43:27', NULL, 0, 0),
('eb86ca66-4db1-4447-807d-8891c8ba4cd3', 'XFER-0002', 'account_transfer', '2026-07-19', 2026, 7, '2026-07', 'posted', 'XFER-0002', '', 'usr-admin-001', NULL, '2026-07-19 06:27:07', NULL, 0, 'acc-1010', '', 7100.00, '2026-07-19 06:27:07', NULL, 0, 0),
('eee12567-6915-4618-9c57-db11d63d30c2', 'VI-00004', 'vendor_bill', '2026-07-18', 2026, 7, '2026-07', 'paid', 'VI-00004', '', 'usr-admin-001', NULL, '2026-07-18 08:50:03', NULL, 0, NULL, NULL, 0.00, '2026-07-18 08:50:16', NULL, 0, 0),
('f3a6aaf2-f3fd-420c-b622-7eb0d6433fc4', 'VI-00007-DEL-c4ece17e', 'vendor_bill', '2026-07-13', 2026, 7, '2026-07', 'open', 'VI-00007', 'old amount adjustment', 'usr-admin-001', NULL, '2026-07-19 07:00:33', NULL, 1, NULL, NULL, 0.00, '2026-07-19 08:29:05', NULL, 0, 0),
('f3a78934-2237-4c1d-b763-49b3aa300be5', 'INV-POS-20260719', 'customer_invoice', '2026-07-19', 2026, 7, '2026-07', 'paid', NULL, NULL, 'usr-admin-001', NULL, '2026-07-19 15:47:16', NULL, 0, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 805.00, '2026-07-19 16:11:00', NULL, 0, 0),
('f4cc4cd1-1d4f-46e6-802d-ed824f6d4571', 'CPAY-00001', 'customer_payment', '2026-07-17', 2026, 7, '2026-07', 'posted', '', '', 'usr-admin-001', NULL, '2026-07-17 13:56:47', NULL, 0, NULL, NULL, 0.00, '2026-07-17 13:56:47', NULL, 0, 0),
('f558e762-5097-4d32-a731-9b615dd98e0a', 'POS-SUM-20260719-DEL-acf062a7', 'customer_invoice', '2026-07-19', 2026, 7, '2026-07', 'open', NULL, '', 'usr-admin-001', NULL, '2026-07-19 06:26:46', NULL, 1, '64e084cd-4fdd-409b-9137-56e30c685640', 'customer', 3425.02, '2026-07-19 15:49:01', NULL, 0, 0),
('f9143322-9f7a-4bd2-9b04-c5d0a4074eb3', 'VI-00006', 'vendor_bill', '2026-07-19', 2026, 7, '2026-07', 'paid', 'VI-00006', '', 'usr-admin-001', NULL, '2026-07-19 06:27:54', NULL, 0, NULL, NULL, 0.00, '2026-07-19 06:28:32', NULL, 0, 0),
('opening-balances-txn-uuid', 'OPENING-BALANCES', 'Journal', '2026-06-15', 2026, 6, '2026-06', 'posted', '', 'System Opening Balances', 'usr-admin-001', NULL, '2026-07-15 13:46:45', NULL, 0, NULL, NULL, 364212.00, '2026-07-20 08:09:14', NULL, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `transaction_lines`
--

CREATE TABLE `transaction_lines` (
  `id` varchar(36) NOT NULL,
  `header_id` varchar(36) NOT NULL,
  `item_id` varchar(36) DEFAULT NULL,
  `account_id` varchar(36) NOT NULL,
  `line_number` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(12,4) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `discount_pct` decimal(5,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) NOT NULL,
  `tax_amount` decimal(14,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  `cost_price` decimal(12,2) NOT NULL,
  `gross_profit` decimal(14,2) NOT NULL,
  `created_by` varchar(36) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_lines`
--

INSERT INTO `transaction_lines` (`id`, `header_id`, `item_id`, `account_id`, `line_number`, `description`, `quantity`, `unit`, `unit_price`, `discount_pct`, `tax_rate`, `tax_amount`, `line_total`, `cost_price`, `gross_profit`, `created_by`) VALUES
('067d84d4-8df2-4c45-819b-329f90d8a6e0', 'f558e762-5097-4d32-a731-9b615dd98e0a', '7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 'acc-4100', 2, NULL, 1.0000, NULL, 247.75, NULL, 0.00, 0.00, 247.75, 240.00, 7.75, 'usr-admin-001'),
('07d99bfc-cd85-4f68-a58e-2ef64a999225', 'a2648690-e770-43e7-9acb-9c6654dad464', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'acc-4100', 1, NULL, 1.0000, 'bottle', 1550.00, NULL, 0.00, 0.00, 1550.00, 0.00, 1550.00, NULL),
('092ade08-4f4e-4908-9d44-4723238ea3e7', 'f9143322-9f7a-4bd2-9b04-c5d0a4074eb3', 'e88436f2-0460-408d-9494-190246334d27', 'acc-1200', 1, NULL, 12.0000, 'pc', 641.67, NULL, 0.00, 0.00, 7700.04, 641.67, 0.00, NULL),
('0a8394f3-237b-42d1-8165-7881ad3486e3', '1fcbec29-de11-4e6a-8bed-589b251a75b3', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'acc-4100', 1, NULL, 1.0000, NULL, 20.00, NULL, 0.00, 0.00, 20.00, 11.00, 9.00, 'usr-admin-001'),
('0d4fdc12-39a1-4a04-aebd-a17b8c36884d', 'b5a93114-9471-4b73-9f90-6e54d85faf7a', 'a48cf127-40e9-404e-a9ea-2814657da992', 'acc-1200', 1, NULL, 400.0000, NULL, 13.05, NULL, 0.00, 0.00, 5220.00, 13.05, 0.00, NULL),
('0d6b8189-9a07-4ffc-85fb-31563b10045b', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'acc-1200', 19, NULL, 3.0000, NULL, 670.88, NULL, 0.00, 0.00, 2012.64, 670.88, 0.00, NULL),
('0f327939-233c-4986-bb6d-d8497f1c5667', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '5e21fcfb-5077-4a22-97f3-574bda1923f6', 'acc-1200', 9, NULL, 13.0000, NULL, 275.00, NULL, 0.00, 0.00, 3575.00, 275.00, 0.00, NULL),
('133138dd-b326-461d-b6c1-cc446a88507e', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-4100', 5, NULL, 10.0000, 'btl', 365.00, NULL, 0.00, 0.00, 3650.00, 329.17, 358.30, NULL),
('1a9dbd76-9b8d-4cad-a1ee-57debf6bd6e8', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '5e21fcfb-5077-4a22-97f3-574bda1923f6', 'acc-1200', 16, NULL, 16.0000, 'btl', 275.00, NULL, 0.00, 0.00, 4400.00, 275.00, 0.00, NULL),
('2386ba0e-0171-4a8d-910e-241242e19374', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'a48cf127-40e9-404e-a9ea-2814657da992', 'acc-4100', 2, NULL, 30.0000, NULL, 15.00, NULL, 0.00, 0.00, 450.00, 13.05, 58.50, NULL),
('23b6ebcc-fe50-4257-b743-6fd31e8f2627', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'cc197d32-be40-4c35-aee8-b553af156838', 'acc-1200', 19, NULL, 12.0000, 'pc', 300.00, NULL, 0.00, 0.00, 3600.00, 300.00, 0.00, NULL),
('2412fdd0-4b55-442d-8b77-446f02901f15', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '742c00bc-0e41-4714-a2ae-c873fa9a5ff9', 'acc-1200', 10, NULL, 1.0000, NULL, 671.00, NULL, 0.00, 0.00, 671.00, 671.00, 0.00, NULL),
('24a203bc-7c3d-4c75-9841-3d120433f8e2', 'c88a9f13-93bd-4be6-9669-ae18cd279955', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'acc-4100', 3, NULL, 11.0000, NULL, 20.00, NULL, 0.00, 0.00, 220.00, 16.10, 42.90, NULL),
('2540addd-d8f5-41f2-a2e1-ceb11b69b97a', '99ef144b-131a-4762-8f41-2547d67a71b0', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'acc-4100', 9, NULL, 26.0000, 'pc', 10.00, NULL, 0.00, 0.00, 260.00, 8.00, 52.00, NULL),
('2ce41447-ec98-4a42-bade-f11ae3af405a', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '2149eeae-1056-4160-bf17-cb71cf454395', 'acc-1200', 4, NULL, 3.0000, NULL, 1800.00, NULL, 0.00, 0.00, 5400.00, 1800.00, 0.00, NULL),
('2e959fa4-a13b-4b8d-9ddb-2a60f553f8ff', 'f558e762-5097-4d32-a731-9b615dd98e0a', 'a48cf127-40e9-404e-a9ea-2814657da992', 'acc-4100', 3, NULL, 11.0000, NULL, 14.51, NULL, 0.00, 0.00, 159.60, 13.05, 16.05, 'usr-admin-001'),
('3255ee2b-e227-49c9-9a1d-dbbeb6fae651', 'c88a9f13-93bd-4be6-9669-ae18cd279955', '6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 'acc-4100', 6, NULL, 2.0000, NULL, 750.00, NULL, 0.00, 0.00, 1500.00, 650.00, 200.00, NULL),
('3362e51a-cb41-449a-9284-da1b7b627fa7', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'acc-4100', 2, NULL, 1.0000, NULL, 360.00, NULL, 0.00, 0.00, 360.00, 320.00, 40.00, NULL),
('373c2dae-ca1a-449c-b368-6a56eb684510', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '4bb831da-2e3d-4482-bd62-05df0c171742', 'acc-1200', 4, NULL, 8.0000, 'btl', 509.00, NULL, 0.00, 0.00, 4072.00, 509.00, 0.00, NULL),
('37f334df-c455-4225-82c8-682f24de1506', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'acc-1200', 7, NULL, 160.0000, NULL, 16.10, NULL, 0.00, 0.00, 2576.00, 16.10, 0.00, NULL),
('3cecd6e6-f4e4-4491-8ffb-f839327e037c', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'acc-4100', 9, NULL, 2.0000, 'pc', 110.00, NULL, 0.00, 0.00, 220.00, 83.33, 53.34, NULL),
('3e7f8fd4-2611-40e1-93cf-fd834cb0e9b8', '51173f00-0d47-48e6-a2a0-8c13fb7999bf', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-4100', 1, NULL, 1.0000, NULL, 355.00, NULL, 0.00, 0.00, 355.00, 329.17, 25.83, NULL),
('40017e66-4d2f-4668-8a25-a5bb3fd9ddf1', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '919b9e31-52a2-48f9-8795-533b0a081663', 'acc-1200', 1, NULL, 26.0000, NULL, 231.25, NULL, 0.00, 0.00, 6012.50, 231.25, 0.00, NULL),
('43c396af-c885-4b53-b73e-83c8fcf91681', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'acc-1200', 8, NULL, 16.0000, 'btl', 254.19, NULL, 0.00, 0.00, 4067.04, 254.19, 0.00, NULL),
('4505abf5-55bb-4f3f-9c2b-861d419a3528', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', '1a8166b8-3107-444c-9634-2f27df10e913', 'acc-4100', 1, NULL, 12.0000, NULL, 312.50, NULL, 0.00, 0.00, 3750.00, 304.17, 99.96, NULL),
('4709cad6-d1cb-40e4-8232-e66e01e34be5', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '7515afaf-1b16-46c6-8b2b-c5e7bfcb9f11', 'acc-1200', 18, NULL, 1.0000, NULL, 671.00, NULL, 0.00, 0.00, 671.00, 671.00, 0.00, NULL),
('496d7d3c-a1b7-4a96-9892-78e08d191761', 'f3a6aaf2-f3fd-420c-b622-7eb0d6433fc4', '2f9197f7-228d-481c-8ae8-0bbbfc7f998d', 'acc-1200', 1, NULL, 1.0000, 'bottle', 22825.00, NULL, 0.00, 0.00, 22825.00, 22825.00, 0.00, NULL),
('4ffff177-d564-4445-b163-92a709609b2a', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'a48cf127-40e9-404e-a9ea-2814657da992', 'acc-4100', 7, NULL, 20.0000, 'pc', 15.00, NULL, 0.00, 0.00, 300.00, 13.05, 39.00, NULL),
('502332f5-3d6f-43d7-8c41-8d1eae5bc8e8', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 'a48cf127-40e9-404e-a9ea-2814657da992', 'acc-4100', 3, NULL, 8.0000, NULL, 14.38, NULL, 0.00, 0.00, 115.00, 13.05, 10.60, 'usr-admin-001'),
('51325d7c-18dd-49c3-a9f9-6f88d55bb5ac', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '305ddcc7-fe37-4d60-9f6d-ae92481d343c', 'acc-1200', 3, NULL, 2.0000, NULL, 425.00, NULL, 0.00, 0.00, 850.00, 425.00, 0.00, NULL),
('5232bb23-f5dd-4441-ba31-f62022917e12', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '49963ff8-5d2d-4c0d-a531-f5048e644817', 'acc-1200', 16, NULL, 9.0000, NULL, 440.00, NULL, 0.00, 0.00, 3960.00, 440.00, 0.00, NULL),
('53103064-8037-4176-aeb1-2e2d3ff88a2c', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', '96a4afa3-9243-4f63-acd5-107aa08d6039', 'acc-1200', 3, NULL, 2.0000, 'pc', 240.00, NULL, 0.00, 0.00, 480.00, 240.00, 0.00, NULL),
('55134ee6-1506-4c8e-9200-6d11c905f381', '99ef144b-131a-4762-8f41-2547d67a71b0', '3f737cab-beef-4873-8d28-30f48bb20818', 'acc-4100', 8, NULL, 2.0000, 'btl', 290.00, NULL, 0.00, 0.00, 580.00, 258.33, 63.34, NULL),
('5c03c3e8-4823-4f77-813c-8fcb77534f6d', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '9e894c3b-f0ab-40bb-8d13-17dc033754d6', 'acc-1200', 15, NULL, 16.0000, NULL, 385.42, NULL, 0.00, 0.00, 6166.72, 385.42, 0.00, NULL),
('5d52134c-dc48-4c6b-a434-48150e73afee', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', '0481f181-7d31-4f6b-95d7-a8c3956acd0f', 'acc-1200', 4, NULL, 3.0000, 'other', 266.67, NULL, 0.00, 0.00, 800.01, 266.67, 0.00, NULL),
('616e752b-5120-4403-8d89-edffeae40e7f', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'f497b9d6-6552-4a41-9b27-4c2fc43fece3', 'acc-1200', 17, NULL, 1.0000, NULL, 6500.00, NULL, 0.00, 0.00, 6500.00, 6500.00, 0.00, NULL),
('62e84557-efc6-4789-b948-2c6d24ceed40', '74b88ca2-7b1f-45d2-af55-090607b85296', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'acc-1200', 3, NULL, -3.0000, NULL, 670.88, NULL, 0.00, 0.00, -2012.64, 670.88, 0.00, NULL),
('634b5a6d-4d31-4b31-9861-f72ac02338bf', '99ef144b-131a-4762-8f41-2547d67a71b0', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-4100', 4, NULL, 3.0000, 'btl', 355.00, NULL, 0.00, 0.00, 1065.00, 329.17, 77.49, NULL),
('66bcdc98-99df-40c5-8c9b-819f736d625f', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '84f1e8aa-26c2-4228-844c-ff33746ad52b', 'acc-1200', 6, NULL, 1.0000, NULL, 5983.00, NULL, 0.00, 0.00, 5983.00, 5983.00, 0.00, NULL),
('684a08d7-13d5-4a7a-8f07-69eb9c649dd3', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'f51bffdf-4860-461e-ab42-cdfc4c941cb7', 'acc-1200', 21, NULL, 4.0000, NULL, 2000.00, NULL, 0.00, 0.00, 8000.00, 2000.00, 0.00, NULL),
('696b9dd1-c2be-4e21-b29e-ecde9ee8953b', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'e88436f2-0460-408d-9494-190246334d27', 'acc-1200', 2, NULL, 6.0000, 'pc', 625.00, NULL, 0.00, 0.00, 3750.00, 625.00, 0.00, NULL),
('6fa7c7da-77c2-4ca6-84c4-d37cb873bdae', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '58704d38-49ac-4535-a4c2-344aa9acf53b', 'acc-1200', 18, NULL, 3.0000, 'pc', 1200.00, NULL, 0.00, 0.00, 3600.00, 1200.00, 0.00, NULL),
('70ee4ced-3c7e-4ad7-b2f9-bac34163a0fc', '99ef144b-131a-4762-8f41-2547d67a71b0', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'acc-4100', 1, NULL, 10.0000, 'pc', 20.00, NULL, 0.00, 0.00, 200.00, 16.10, 39.00, NULL),
('7395c511-acae-4ae1-a591-4a8ccf8d0e1d', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '66e8bb29-94f3-4cd7-ac67-14ada9da1c4c', 'acc-1200', 5, NULL, 12.0000, 'btl', 525.00, NULL, 0.00, 0.00, 6300.00, 525.00, 0.00, NULL),
('73b72c39-5ccd-4f35-9c4b-beaa1280353e', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '6b59f47f-75a8-4bd6-a4a8-5689dfcbafe1', 'acc-1200', 11, NULL, 9.0000, NULL, 650.00, NULL, 0.00, 0.00, 5850.00, 650.00, 0.00, NULL),
('7ad59b37-ee64-43b3-99bf-32fdb0ac0232', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'acc-1200', 9, NULL, 24.0000, 'bottle', 156.25, NULL, 0.00, 0.00, 3750.00, 156.25, 0.00, NULL),
('7ced1fb8-4277-430d-83c2-ff4331b931ad', 'ad0b83cc-3d86-4a6c-be55-c4953a6098ba', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'acc-1200', 2, NULL, 24.0000, NULL, 243.75, NULL, 0.00, 0.00, 5850.00, 243.75, 0.00, NULL),
('7e2da1cc-67c1-432b-91af-a0b70efe6ec8', 'c88a9f13-93bd-4be6-9669-ae18cd279955', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'acc-4100', 8, NULL, 10.0000, NULL, 10.00, NULL, 0.00, 0.00, 100.00, 8.00, 20.00, NULL),
('7eba497c-8040-4faf-8890-2bfe92d60001', 'ad0b83cc-3d86-4a6c-be55-c4953a6098ba', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-1200', 1, NULL, 36.0000, NULL, 329.17, NULL, 0.00, 0.00, 11850.00, 329.17, 0.00, NULL),
('7fa5ae13-0e85-43cb-bde2-411cfcad091e', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-4100', 1, NULL, 1.0000, 'btl', 360.00, NULL, 0.00, 0.00, 360.00, 329.17, 30.83, NULL),
('801d8b5c-137c-41a8-bfcd-7609fba0a50f', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '3d0e5228-5462-4ae9-b843-51b352361479', 'acc-1200', 12, NULL, 8.0000, 'pc', 1341.75, NULL, 0.00, 0.00, 10734.00, 1341.75, 0.00, NULL),
('82f2b89d-c777-48a4-81ae-3f835e188089', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '1a8166b8-3107-444c-9634-2f27df10e913', 'acc-1200', 11, NULL, 12.0000, 'pc', 304.17, NULL, 0.00, 0.00, 3650.04, 304.17, 0.00, NULL),
('86e9b170-ba42-4c53-9d02-468ff5bd695f', '99ef144b-131a-4762-8f41-2547d67a71b0', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'acc-4100', 7, NULL, 1.0000, 'btl', 280.00, NULL, 0.00, 0.00, 280.00, 254.19, 25.81, NULL),
('8718dda7-7621-4a1d-aa83-8d3fdfd5689c', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '581f69c5-b0a8-4e0f-9d7d-43e9225a9b40', 'acc-1200', 8, NULL, 5.0000, NULL, 135.00, NULL, 0.00, 0.00, 675.00, 135.00, 0.00, NULL),
('8d3b2eec-4cb9-44d4-b822-0c4a6097ef4b', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', '3f737cab-beef-4873-8d28-30f48bb20818', 'acc-4100', 3, NULL, 2.0000, 'btl', 280.00, NULL, 0.00, 0.00, 560.00, 258.33, 43.34, NULL),
('8f610d9e-c935-4b6e-9d74-4f9c60d48f4b', 'f3a78934-2237-4c1d-b763-49b3aa300be5', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'acc-4100', 2, NULL, 1.0000, NULL, 110.00, NULL, 0.00, 0.00, 110.00, 83.33, 26.67, 'usr-admin-001'),
('92ae487e-2a9a-40cb-89c7-c2c444f3a636', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'acc-4100', 4, NULL, 1.0000, NULL, 280.00, NULL, 0.00, 0.00, 280.00, 254.19, 25.81, 'usr-admin-001'),
('95d935a3-8c00-4526-9357-782138e9e368', '1208ab57-cae6-4d9b-a5e2-3dbdd1ef72d1', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'acc-4100', 2, NULL, 1.0000, 'pc', 20.00, NULL, 0.00, 0.00, 20.00, 16.10, 3.90, NULL),
('966c8e0f-1f31-4ee4-a00f-1d817cd23f7f', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'acc-1200', 14, NULL, 17.0000, NULL, 11.00, NULL, 0.00, 0.00, 187.00, 11.00, 0.00, NULL),
('96e39c46-5b32-4188-97ac-86edd6b7f995', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', '4bb831da-2e3d-4482-bd62-05df0c171742', 'acc-4100', 2, NULL, 2.0000, 'btl', 550.00, NULL, 0.00, 0.00, 1100.00, 509.00, 82.00, NULL),
('97bd7b07-3603-4ee6-ba65-1d907284f6f3', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'f78c3fcb-7c77-4c35-a371-72075d6f61e5', 'acc-1200', 2, NULL, 25.0000, NULL, 300.00, NULL, 0.00, 0.00, 7500.00, 300.00, 0.00, NULL),
('9a337ef9-5534-4873-a5a5-95639ce324da', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'acc-4100', 8, NULL, 2.0000, 'btl', 280.00, NULL, 0.00, 0.00, 560.00, 254.19, 51.62, NULL),
('9d3bd1d4-b94a-49a4-9459-a506f9a3186d', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'acc-4100', 4, NULL, 6.0000, NULL, 180.00, NULL, 0.00, 0.00, 1080.00, 156.25, 142.50, NULL),
('a4641429-66fe-4bc5-8b07-25707d3df714', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'c101a46e-b532-482f-aa71-cb6ce07431d3', 'acc-1200', 20, NULL, 1.0000, NULL, 5675.00, NULL, 0.00, 0.00, 5675.00, 5675.00, 0.00, NULL),
('a594ee71-0f1d-4f20-adb7-b64fc5eb7493', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'acc-1200', 10, NULL, 120.0000, 'pc', 83.33, NULL, 0.00, 0.00, 9999.60, 83.33, 0.00, NULL),
('a652ea7c-ecff-4893-9e38-aa0b165c56f2', 'f558e762-5097-4d32-a731-9b615dd98e0a', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-4100', 4, NULL, 7.0000, NULL, 354.65, NULL, 0.00, 0.00, 2482.52, 329.17, 178.33, 'usr-admin-001'),
('a78ed355-487b-4904-837c-6e525876fa19', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', '4376ce7f-e0ae-498d-849d-0f0b494d84a7', 'acc-4100', 1, NULL, 1.0000, NULL, 100.00, NULL, 0.00, 0.00, 100.00, 50.00, 50.00, 'usr-admin-001'),
('a7957cb4-318f-47e8-9665-80d4ca2449f0', '8661472c-e952-464c-ab75-f89a20b45c45', '4376ce7f-e0ae-498d-849d-0f0b494d84a7', 'acc-1200', 1, NULL, 39.0000, NULL, 50.00, NULL, 0.00, 0.00, 1950.00, 50.00, 0.00, NULL),
('aa3b7d6f-8d99-419f-a23f-3415f687e191', '99ef144b-131a-4762-8f41-2547d67a71b0', 'a48cf127-40e9-404e-a9ea-2814657da992', 'acc-4100', 3, NULL, 12.0000, 'pc', 15.00, NULL, 0.00, 0.00, 180.00, 13.05, 23.40, NULL),
('aa994383-90af-4c0f-8dbc-fd173450f452', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'acc-4100', 2, NULL, 25.0000, NULL, 8.80, NULL, 0.00, 0.00, 220.00, 8.00, 20.00, 'usr-admin-001'),
('aacccafe-6bb1-470f-99ab-30d9ff27b2e3', 'f558e762-5097-4d32-a731-9b615dd98e0a', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'acc-4100', 1, NULL, 2.0000, NULL, 267.58, NULL, 0.00, 0.00, 535.15, 243.75, 47.65, 'usr-admin-001'),
('af206603-7d2d-4301-82e5-1735828d5f5f', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '49225a12-9859-4acf-9e02-7c2f19ed4fda', 'acc-1200', 17, NULL, 3.0000, 'bottle', 2100.00, NULL, 0.00, 0.00, 6300.00, 2100.00, 0.00, NULL),
('b0703d37-b3dc-4b91-98a8-9e4ce0d99ee7', 'f3a78934-2237-4c1d-b763-49b3aa300be5', '5e21fcfb-5077-4a22-97f3-574bda1923f6', 'acc-4100', 1, NULL, 1.0000, NULL, 300.00, NULL, 0.00, 0.00, 300.00, 275.00, 25.00, 'usr-admin-001'),
('b7520724-e563-48b3-8a0b-765d7f9dda54', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'b6533a14-377b-4d29-8d77-fa4d2fe883ee', 'acc-4100', 1, NULL, 1.0000, 'btl', 1100.00, NULL, 0.00, 0.00, 1100.00, 1016.75, 83.25, NULL),
('b8517c66-b4a6-4a40-bc0b-941040e7d728', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '996248e3-1b15-4b68-ab39-eac57f3a71ea', 'acc-1200', 13, NULL, 1.0000, NULL, 1800.00, NULL, 0.00, 0.00, 1800.00, 1800.00, 0.00, NULL),
('bb3adc5f-40f6-49d3-8896-e20fa6c0a877', 'c88a9f13-93bd-4be6-9669-ae18cd279955', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'acc-4100', 10, NULL, 1.0000, NULL, 750.00, NULL, 0.00, 0.00, 750.00, 670.88, 79.12, NULL),
('bb4ea71c-08a1-4e02-bef2-3234ebb591a1', 'c88a9f13-93bd-4be6-9669-ae18cd279955', '4bb831da-2e3d-4482-bd62-05df0c171742', 'acc-4100', 5, NULL, 1.0000, NULL, 550.00, NULL, 0.00, 0.00, 550.00, 509.00, 41.00, NULL),
('bb88c575-5995-4003-b7c0-c28430ab9655', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-1200', 15, NULL, 24.0000, 'btl', 329.17, NULL, 0.00, 0.00, 7900.08, 329.17, 0.00, NULL),
('bce359a4-964d-4d97-994f-98e4c717eb0d', 'c88a9f13-93bd-4be6-9669-ae18cd279955', '1a8166b8-3107-444c-9634-2f27df10e913', 'acc-4100', 9, NULL, 1.0000, NULL, 340.00, NULL, 0.00, 0.00, 340.00, 304.17, 35.83, NULL),
('beb5ea95-baa4-4b1d-9da7-2f5400601427', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', '7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 'acc-1200', 1, NULL, 4.0000, 'pc', 240.00, NULL, 0.00, 0.00, 960.00, 240.00, 0.00, NULL),
('bf6553ad-9e4a-4b3a-a608-d56f5ae5df9e', '99ef144b-131a-4762-8f41-2547d67a71b0', 'b1e6e633-3a08-42d9-8da2-fe6d7acdf463', 'acc-4100', 10, NULL, 1.0000, 'pc', 250.00, NULL, 0.00, 0.00, 250.00, 240.00, 10.00, NULL),
('bff3f3e7-23f2-4d0b-a776-063f694dcacd', '99ef144b-131a-4762-8f41-2547d67a71b0', '17d37cfe-9fd1-4dca-bc6d-af63fa236373', 'acc-4100', 5, NULL, 2.0000, 'btl', 270.00, NULL, 0.00, 0.00, 540.00, 243.75, 52.50, NULL),
('c065f95d-3380-41ba-baec-b84df37de700', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'acc-4100', 4, NULL, 10.0000, NULL, 17.00, NULL, 0.00, 0.00, 170.00, 16.10, 9.00, NULL),
('c09389a8-aa85-4b71-9608-9178885fb88f', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '3f737cab-beef-4873-8d28-30f48bb20818', 'acc-1200', 12, NULL, 3.0000, NULL, 258.33, NULL, 0.00, 0.00, 774.99, 258.33, 0.00, NULL),
('c254e846-b5cb-4cdf-8a9f-d72e4e1b28a0', '74b88ca2-7b1f-45d2-af55-090607b85296', '3d0e5228-5462-4ae9-b843-51b352361479', 'acc-1200', 2, NULL, 1.0000, NULL, 1341.75, NULL, 0.00, 0.00, 1341.75, 1341.75, 0.00, NULL),
('c4dea607-3e0b-4277-ba0b-5719dd4d0b40', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', '96a4afa3-9243-4f63-acd5-107aa08d6039', 'acc-4100', 3, NULL, 1.0000, NULL, 250.00, NULL, 0.00, 0.00, 250.00, 240.00, 10.00, NULL),
('c6044a1d-6024-44e3-9356-aa204dbdfd0e', '99ef144b-131a-4762-8f41-2547d67a71b0', '29576fa7-2a38-44c0-8827-76cb1e5ce2b4', 'acc-4100', 2, NULL, 1.0000, 'pc', 20.00, NULL, 0.00, 0.00, 20.00, 11.00, 9.00, NULL),
('caa6a49d-200b-4353-902e-fc845acb51fd', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-4100', 1, NULL, 11.0000, NULL, 365.00, NULL, 0.00, 0.00, 4015.00, 329.17, 394.13, NULL),
('cad07366-8671-44b8-9f87-039486972d9e', '74b88ca2-7b1f-45d2-af55-090607b85296', 'dd1ac436-ebca-4fa2-9407-44f158256b13', 'acc-1200', 1, NULL, -1.0000, NULL, 329.17, NULL, 0.00, 0.00, -329.17, 329.17, 0.00, NULL),
('cc7f057f-c6c6-4b18-9831-2f7a7bfdd165', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '3f737cab-beef-4873-8d28-30f48bb20818', 'acc-1200', 13, NULL, 12.0000, 'btl', 258.33, NULL, 0.00, 0.00, 3100.00, 258.33, 0.00, NULL),
('ce4ed042-c78e-44b7-ae57-81f401ce7845', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'd20f5087-48f0-4ec3-950c-d7393884aed4', 'acc-1200', 1, NULL, 3.0000, 'bottle', 1034.00, NULL, 0.00, 0.00, 3102.00, 1034.00, 0.00, NULL),
('cf4bda7e-9c04-4234-bbe1-53edc837460f', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'b6533a14-377b-4d29-8d77-fa4d2fe883ee', 'acc-1200', 14, NULL, 4.0000, 'btl', 1016.75, NULL, 0.00, 0.00, 4067.00, 1016.75, 0.00, NULL),
('d08cddae-6f8d-4c90-989b-0637a72eaa15', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', '3f737cab-beef-4873-8d28-30f48bb20818', 'acc-4100', 6, NULL, 2.0000, 'btl', 280.00, NULL, 0.00, 0.00, 560.00, 258.33, 43.34, NULL),
('d1c236a8-5d4a-40b7-b6d7-2a3d5bedf30e', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '9e2df543-8630-4d81-b273-1cd77a32ae65', 'acc-1200', 3, NULL, 4.0000, 'bottle', 2683.50, NULL, 0.00, 0.00, 10734.00, 2683.50, 0.00, NULL),
('d309cfa3-54e5-4494-9f1d-fb3283c6f28b', 'af040956-157e-4c5c-b8e7-110b4ebac66a', '5599eb46-8e58-4bbc-957c-7bee386693b6', 'acc-1200', 5, NULL, 200.0000, NULL, 8.00, NULL, 0.00, 0.00, 1600.00, 8.00, 0.00, NULL),
('dd9fd700-5c74-4de8-b297-b46cc2b6648c', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', '86ebb97b-c3c7-46d0-9f1a-fe999dacda22', 'acc-4100', 4, NULL, 2.0000, 'pc', 20.00, NULL, 0.00, 0.00, 40.00, 16.10, 7.80, NULL),
('df8ca2f2-0853-4d51-a659-d76db74662d3', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'b1e6e633-3a08-42d9-8da2-fe6d7acdf463', 'acc-1200', 2, NULL, 3.0000, 'pc', 240.00, NULL, 0.00, 0.00, 720.00, 240.00, 0.00, NULL),
('e2c8f4a4-7dd7-4269-a53f-cf9615e3ccfb', '99ef144b-131a-4762-8f41-2547d67a71b0', '4bb831da-2e3d-4482-bd62-05df0c171742', 'acc-4100', 6, NULL, 2.0000, 'btl', 550.00, NULL, 0.00, 0.00, 1100.00, 509.00, 82.00, NULL),
('e36e9f33-c913-44dc-a8da-db82ac06ed98', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'e88436f2-0460-408d-9494-190246334d27', 'acc-4100', 5, NULL, 1.0000, NULL, 750.00, NULL, 0.00, 0.00, 750.00, 625.00, 125.00, NULL),
('e80bfb30-abe5-4e9f-b523-34df88c04dd2', 'af040956-157e-4c5c-b8e7-110b4ebac66a', 'c3973817-9b13-4a7a-888c-ad920161c5ea', 'acc-1200', 22, NULL, 16.0000, NULL, 254.19, NULL, 0.00, 0.00, 4067.04, 254.19, 0.00, NULL),
('e8a0d485-f756-4ae7-a90a-5b6566c6118e', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'da007f07-7cfa-4c99-b90a-1afab623bb63', 'acc-1200', 6, NULL, 12.0000, 'btl', 808.33, NULL, 0.00, 0.00, 9699.96, 808.33, 0.00, NULL),
('ef48b24e-8251-47d1-a279-f7465e4f37f6', 'b4946e1c-3bb5-4340-b266-cc042370bb1f', '49225a12-9859-4acf-9e02-7c2f19ed4fda', 'acc-4100', 1, NULL, 3.0000, NULL, 1000.00, NULL, 13.00, 390.00, 3390.00, 2100.00, -3300.00, NULL),
('f0d36cf8-b971-4d41-b955-1e9931dccdcf', 'eee12567-6915-4618-9c57-db11d63d30c2', '1a8166b8-3107-444c-9634-2f27df10e913', 'acc-1200', 1, NULL, 24.0000, NULL, 304.17, NULL, 0.00, 0.00, 7300.00, 304.17, 0.00, NULL),
('f3c25fbe-8a8a-4496-933c-f64b5011eb40', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'c9d5cd17-fb9a-47dc-a938-17e8fba12e9c', 'acc-1200', 20, NULL, 2.0000, 'bottle', 1100.00, NULL, 0.00, 0.00, 2200.00, 1100.00, 0.00, NULL),
('f826b0ae-70c2-4237-b2fd-5dae04aca853', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'c1cac95d-404c-424f-8e9d-8d74198e7b9e', 'acc-4100', 3, NULL, 2.0000, 'bottle', 180.00, NULL, 0.00, 0.00, 360.00, 156.25, 47.50, NULL),
('f9824097-b4a3-440a-ac40-84e086988a4e', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '65a60e75-a453-4c11-bb40-99ef492b3dcb', 'acc-1200', 7, NULL, 16.0000, 'btl', 670.88, NULL, 0.00, 0.00, 10734.08, 670.88, 0.00, NULL),
('f9e86bd4-d749-4e85-825f-85970b4f27bf', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', '7c4cfa4d-3d7d-4ae6-bbbb-2844fdd455b4', 'acc-4100', 2, NULL, 1.0000, NULL, 250.00, NULL, 0.00, 0.00, 250.00, 240.00, 10.00, NULL),
('fbc533af-8c73-46f9-a728-4ed65e0f90c3', 'c88a9f13-93bd-4be6-9669-ae18cd279955', '92eaee6a-2aad-47ec-949f-78cd44e8074b', 'acc-4100', 7, NULL, 1.0000, NULL, 110.00, NULL, 0.00, 0.00, 110.00, 83.33, 26.67, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `transaction_links`
--

CREATE TABLE `transaction_links` (
  `id` varchar(36) NOT NULL,
  `parent_id` varchar(36) NOT NULL,
  `child_id` varchar(36) NOT NULL,
  `link_type` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_links`
--

INSERT INTO `transaction_links` (`id`, `parent_id`, `child_id`, `link_type`, `created_at`) VALUES
('012f4edc-89ce-40e6-92ca-d8a993fc94e4', '40caee36-c141-41ff-b7cd-db1d58271191', 'f3a78934-2237-4c1d-b763-49b3aa300be5', 'payment:805', '2026-07-19 16:11:00'),
('2a1890d6-d5da-488e-b31a-6bf64a634764', '4065ba21-c172-4bd8-8f13-e9d0867be8f4', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'payment:2600', '2026-07-19 06:37:29'),
('3abc344e-7b78-4746-bd48-4bbe0601f82b', '2ed95978-897d-4a89-98b9-4c20b14e26a2', 'c9ed387e-5587-4f9a-bc32-3aa7d461dc3a', 'payment:320', '2026-07-20 10:50:14'),
('3df09edc-d08f-4383-8ea0-a1c0475937dc', 'c562ad2f-f088-4e49-81e2-bd7267eb7c95', 'c88a9f13-93bd-4be6-9669-ae18cd279955', 'payment:8920', '2026-07-18 10:01:36'),
('4e625779-dc50-4b7e-96e3-bb39581b9b0e', '84b42a30-42f7-429b-9041-20ba1aef7642', 'f9143322-9f7a-4bd2-9b04-c5d0a4074eb3', 'payment:7700.04', '2026-07-19 06:28:32'),
('7fc69b0c-825d-4319-89bd-b5908bbe21a5', 'dc85e963-3b8d-47f3-a40d-0052832989a4', 'ad0b83cc-3d86-4a6c-be55-c4953a6098ba', 'payment:17700', '2026-07-17 14:40:24'),
('8a51a4fb-66c8-4e01-ab90-c7b67c28f68b', 'f4cc4cd1-1d4f-46e6-802d-ed824f6d4571', 'a142d33b-4c6f-42d2-84ac-9e80b2ef4128', 'payment:2570', '2026-07-18 12:20:20'),
('8c2ff66f-2919-471c-a5d8-31cefd716be9', '6a76457e-1018-4649-930c-bf5c82e39ac4', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', 'payment:115500', '2026-07-17 14:39:35'),
('cdfac897-4527-4f3b-a90f-42e2a6fec140', '9493494b-e8fe-482d-9c22-638b6e31492b', 'eee12567-6915-4618-9c57-db11d63d30c2', 'payment:7300', '2026-07-18 08:50:16'),
('dc617c17-1d15-4b5e-b93d-d61e0fa0c975', '5d27e5fa-2465-463b-bbd4-388435dc2a16', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'payment:2960.01', '2026-07-18 08:54:00'),
('e255ba59-6749-4873-bf72-1c198bfec8bf', '13ef5b2a-743e-43e0-b4ce-886ff8c09e86', '367d8e6f-20ec-4df7-85fb-bf9dbe8b1fc5', 'payment:7845', '2026-07-18 10:07:48'),
('f0cee6a0-8ac1-4c54-aa8f-235bca31222f', '161e3d0c-e842-4082-ae2c-729d5dc7e4bd', 'b5a93114-9471-4b73-9f90-6e54d85faf7a', 'payment:5220', '2026-07-17 14:19:55'),
('f5004832-d0bb-47ec-ba34-b961018a30b4', 'bd96863a-ad0f-4bc7-8d87-174f5e20dbe8', '99ef144b-131a-4762-8f41-2547d67a71b0', 'payment:4475', '2026-07-18 16:06:06');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','manager','cashier','accountant') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `avatar` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `full_name`, `email`, `password_hash`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`, `is_deleted`, `avatar`) VALUES
('usr-acc-001', 'hari_acc', 'Hari Prasad', 'hari.acc@mnsliquors.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'accountant', 0, NULL, '2026-04-29 11:25:30', '2026-05-21 10:53:19', 0, NULL),
('usr-admin-001', 'admin', 'Sanjay poudel', 'admin@mnsliquors.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, '2026-07-20 10:47:29', '2026-04-29 10:39:44', '2026-07-20 10:47:29', 0, NULL),
('usr-csh-001', 'user', 'Sarah Shrestha', 'sarah.csh@mnsliquors.com', '$2y$10$uUItPO3aTq5PAZPdZwL6tu7g11MoBK13f3zCHj246AyxTxG0yUeY2', 'cashier', 1, '2026-05-21 13:41:23', '2026-04-29 11:25:30', '2026-05-21 13:41:23', 0, NULL),
('usr-mgr-001', 'john_mgr', 'John Doe', 'john.mgr@mnsliquors.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 1, NULL, '2026-04-29 11:25:30', '2026-05-02 13:56:00', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_dashboard_preferences`
--

CREATE TABLE `user_dashboard_preferences` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `layout_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`layout_data`)),
  `filters_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`filters_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_meta`
--

CREATE TABLE `user_meta` (
  `user_id` varchar(36) DEFAULT NULL,
  `meta_field` varchar(255) NOT NULL,
  `meta_value` text NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

CREATE TABLE `vendors` (
  `id` varchar(36) NOT NULL,
  `vendor_code` varchar(20) DEFAULT NULL,
  `company_name` varchar(150) NOT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `vat_number` varchar(20) DEFAULT NULL,
  `payable_account_id` varchar(36) NOT NULL,
  `payment_terms_days` int(11) DEFAULT NULL,
  `credit_limit` decimal(14,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vendors`
--

INSERT INTO `vendors` (`id`, `vendor_code`, `company_name`, `contact_name`, `phone`, `email`, `address`, `pan_number`, `vat_number`, `payable_account_id`, `payment_terms_days`, `credit_limit`, `is_active`, `created_at`, `is_deleted`, `updated_at`) VALUES
('238619da-598e-4461-96f0-14f805a7ff62', 'V-00002', 'Navraj(Hookah Flavours)', NULL, '9826853024', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('3751370b-41be-4946-8098-6b1a2fedabb5', 'V-00016', 'S To A Traders', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('3cb41852-33bf-49a6-97f1-da89173c3ba9', 'V-00017', 'KingsHill Wine', NULL, '9851370572', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('53566186-b9c3-434f-a272-69a46a765c00', 'V-00019', 'Friendship suppliers pvt ltd', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('595ad7ac-65b6-4cca-97f4-e8954b8ebebd', 'V-00020', 'Pradip Karanjit', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('7e51941d-d8e3-4507-89ad-523ab7655869', 'V-00022', 'CD Distillery', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('9664a5f1-727b-4b5e-92ee-cfa9fce76b01', 'V-00001', 'DDT suppliers', '', '', '', 'naryantar jorpati', '300000001', NULL, 'acc-2100', 0, 0.00, 1, '2026-05-03 10:45:50', 0, '2026-05-03 10:45:50'),
('97f649ec-0d9a-4087-935e-5d982456b2de', 'V-00023', 'S.K Suppliers', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('a5215a8c-775f-4ed3-b9f9-3a5accbfc00d', 'V-00014', 'AA Booze House', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('a5b60d76-1192-4064-bd84-b7e37395b746', 'V-00013', 'Bidhan Traders', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('b413e155-7abe-4421-88ce-682b675ae07d', 'V-00005', 'Baba Hukka', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('ba6f41fa-ef17-4cc3-a2e0-ac56b09b758c', 'V-00006', 'Aila house', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('bb7427e8-eb08-4198-8af5-5258b41f614c', 'V-00007', 'Ramesh shrestha water man', NULL, '9861675663', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('c233e230-1660-446c-a134-e4cd7f75354a', 'V-00009', 'Kiki', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('cea33a88-8ca8-4bce-9a14-13a5264438e8', 'V-00010', 'Modern Trade', '', '9860801400', '', '', '', NULL, 'acc-2100', 0, 0.00, 0, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('da84c0d3-a0a4-459f-8a7f-66de79921ace', 'V-00011', 'Coca-Cola', NULL, '9766917490', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('dd489025-341b-45f2-a09a-e13a7596f878', 'V-00012', 'SPG trading Pvt ltd', NULL, '9801012531', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12'),
('fed80e61-0c60-4cc7-805f-12e8ccae2406', 'V-00024', 'MRS Traders', NULL, '', NULL, NULL, NULL, NULL, 'acc-2100', NULL, NULL, 1, '2026-05-10 13:31:35', 0, '2026-05-23 14:01:12');

-- --------------------------------------------------------

--
-- Table structure for table `vendor_bills`
--

CREATE TABLE `vendor_bills` (
  `id` varchar(36) NOT NULL,
  `header_id` varchar(36) NOT NULL,
  `vendor_id` varchar(36) NOT NULL,
  `bill_date` date NOT NULL,
  `due_date` date NOT NULL,
  `vendor_invoice_number` varchar(50) DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL,
  `discount_amount` decimal(14,2) NOT NULL,
  `tax_amount` decimal(14,2) NOT NULL,
  `total_amount` decimal(14,2) NOT NULL,
  `amount_paid` decimal(14,2) NOT NULL,
  `balance_due` decimal(14,2) NOT NULL,
  `payment_status` enum('unpaid','partial','paid') NOT NULL,
  `created_by` varchar(36) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vendor_bills`
--

INSERT INTO `vendor_bills` (`id`, `header_id`, `vendor_id`, `bill_date`, `due_date`, `vendor_invoice_number`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `amount_paid`, `balance_due`, `payment_status`, `created_by`) VALUES
('142c0747-6546-40cc-a807-8a56e474a9d7', 'f9143322-9f7a-4bd2-9b04-c5d0a4074eb3', 'c233e230-1660-446c-a134-e4cd7f75354a', '2026-07-19', '2026-08-18', 'VI-00006', 7700.04, 0.00, 0.00, 7700.04, 7700.04, 0.00, 'paid', NULL),
('6e75f137-3359-4979-9c80-d2a71a40e72c', '184afb0d-ea02-49b1-b94a-0b6b05ed9597', '9664a5f1-727b-4b5e-92ee-cfa9fce76b01', '2026-07-16', '2026-08-15', 'VI-00001', 115759.80, 259.80, 0.00, 115500.00, 115500.00, 0.00, 'paid', NULL),
('83d25b31-5b14-4892-ae4d-0900993cda0b', 'ad0b83cc-3d86-4a6c-be55-c4953a6098ba', '9664a5f1-727b-4b5e-92ee-cfa9fce76b01', '2026-07-17', '2026-08-16', 'VI-00002', 17700.00, 0.00, 0.00, 17700.00, 17700.00, 0.00, 'paid', NULL),
('b4758adc-fb75-43d2-86f2-2d15e8999beb', 'f3a6aaf2-f3fd-420c-b622-7eb0d6433fc4', '53566186-b9c3-434f-a272-69a46a765c00', '2026-07-13', '2026-08-18', 'VI-00007-DEL-c4ece17e', 22825.00, 0.00, 0.00, 22825.00, 0.00, 22825.00, 'unpaid', NULL),
('c5325d3f-8eba-4c0f-9cc3-426b2b80ea4c', 'b5a93114-9471-4b73-9f90-6e54d85faf7a', 'c233e230-1660-446c-a134-e4cd7f75354a', '2026-07-16', '2026-08-16', 'VI-00003', 5220.00, 0.00, 0.00, 5220.00, 5220.00, 0.00, 'paid', NULL),
('e8aaa66e-ca4d-44fd-8ba4-c7c5d604c221', '4a666f7e-7dbc-4347-a802-4a5dabe3b630', 'c233e230-1660-446c-a134-e4cd7f75354a', '2026-07-18', '2026-08-17', 'VI-00005', 2960.01, 0.00, 0.00, 2960.01, 2960.01, 0.00, 'paid', NULL),
('eb514924-e131-4b1a-8ee8-9fcc6be37334', 'eee12567-6915-4618-9c57-db11d63d30c2', '9664a5f1-727b-4b5e-92ee-cfa9fce76b01', '2026-07-18', '2026-08-17', 'VI-00004', 7300.00, 0.00, 0.00, 7300.00, 7300.00, 0.00, 'paid', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_code` (`account_code`),
  ADD KEY `parent_account_id` (`parent_account_id`);

--
-- Indexes for table `account_transfers`
--
ALTER TABLE `account_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `header_id` (`header_id`),
  ADD KEY `from_account_id` (`from_account_id`),
  ADD KEY `to_account_id` (`to_account_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cash_denominations`
--
ALTER TABLE `cash_denominations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `header_id` (`header_id`),
  ADD KEY `counted_by` (`counted_by`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_code` (`customer_code`),
  ADD KEY `receivable_account_id` (`receivable_account_id`);

--
-- Indexes for table `customer_invoices`
--
ALTER TABLE `customer_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `header_id` (`header_id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_ci_date` (`invoice_date`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `header_id` (`header_id`),
  ADD KEY `expense_account_id` (`expense_account_id`),
  ADD KEY `paid_from_account_id` (`paid_from_account_id`),
  ADD KEY `vendor_id` (`vendor_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `cogs_account_id` (`cogs_account_id`),
  ADD KEY `income_account_id` (`income_account_id`),
  ADD KEY `inventory_account_id` (`inventory_account_id`),
  ADD KEY `idx_items_sku_active` (`sku`,`is_deleted`),
  ADD KEY `idx_items_del_act` (`is_deleted`,`is_active`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `header_id` (`header_id`),
  ADD KEY `idx_je_acc_date` (`account_id`,`entry_date`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vendor_id` (`vendor_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `bank_account_id` (`bank_account_id`),
  ADD KEY `applied_to_txn_id` (`applied_to_txn_id`),
  ADD KEY `header_id` (`header_id`);

--
-- Indexes for table `pos_entry`
--
ALTER TABLE `pos_entry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `idx_pos_date` (`date_time`),
  ADD KEY `idx_pos_customer` (`customer_id`);

--
-- Indexes for table `pos_items`
--
ALTER TABLE `pos_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pos_id` (`pos_id`),
  ADD KEY `idx_pos_item` (`item_id`);

--
-- Indexes for table `pos_payments`
--
ALTER TABLE `pos_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pos_id` (`pos_id`),
  ADD KEY `account_id` (`account_id`),
  ADD KEY `idx_pos_payment_mode` (`payment_mode`);

--
-- Indexes for table `pos_returns`
--
ALTER TABLE `pos_returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `original_pos_id` (`original_pos_id`);

--
-- Indexes for table `pos_return_items`
--
ALTER TABLE `pos_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `return_id` (`return_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `reference_codes`
--
ALTER TABLE `reference_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `type` (`type`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `idx_ref_codes_type_active` (`type`,`is_active`);

--
-- Indexes for table `system_info`
--
ALTER TABLE `system_info`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `module` (`module`),
  ADD KEY `ref_id` (`ref_id`);

--
-- Indexes for table `transaction_headers`
--
ALTER TABLE `transaction_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `txn_number` (`txn_number`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_transaction_headers_txn_type` (`txn_type`),
  ADD KEY `idx_transaction_headers_status` (`status`),
  ADD KEY `idx_transaction_headers_txn_date` (`txn_date`),
  ADD KEY `idx_transaction_headers_is_deleted` (`is_deleted`),
  ADD KEY `idx_th_date_status` (`txn_date`,`status`,`is_deleted`),
  ADD KEY `idx_th_date_type` (`txn_date`,`txn_type`),
  ADD KEY `idx_th_source` (`source`),
  ADD KEY `idx_th_is_locked` (`is_locked`);

--
-- Indexes for table `transaction_lines`
--
ALTER TABLE `transaction_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transaction_lines_header_id` (`header_id`),
  ADD KEY `idx_transaction_lines_item_id` (`item_id`),
  ADD KEY `idx_transaction_lines_account_id` (`account_id`),
  ADD KEY `idx_tl_item_account` (`item_id`,`account_id`);

--
-- Indexes for table `transaction_links`
--
ALTER TABLE `transaction_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`),
  ADD KEY `child_id` (`child_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_is_active` (`is_active`);

--
-- Indexes for table `user_dashboard_preferences`
--
ALTER TABLE `user_dashboard_preferences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_meta`
--
ALTER TABLE `user_meta`
  ADD KEY `user_id` (`user_id`),
  ADD KEY `user_id_2` (`user_id`,`meta_field`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendor_code` (`vendor_code`),
  ADD KEY `payable_account_id` (`payable_account_id`);

--
-- Indexes for table `vendor_bills`
--
ALTER TABLE `vendor_bills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `header_id` (`header_id`),
  ADD UNIQUE KEY `vendor_invoice_number` (`vendor_invoice_number`),
  ADD KEY `vendor_id` (`vendor_id`),
  ADD KEY `idx_vb_date` (`bill_date`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=722;

--
-- AUTO_INCREMENT for table `system_info`
--
ALTER TABLE `system_info`
  MODIFY `id` int(30) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(30) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`parent_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `account_transfers`
--
ALTER TABLE `account_transfers`
  ADD CONSTRAINT `account_transfers_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `account_transfers_ibfk_2` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `account_transfers_ibfk_3` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `cash_denominations`
--
ALTER TABLE `cash_denominations`
  ADD CONSTRAINT `cash_denominations_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `cash_denominations_ibfk_2` FOREIGN KEY (`counted_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`receivable_account_id`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `customer_invoices`
--
ALTER TABLE `customer_invoices`
  ADD CONSTRAINT `customer_invoices_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `customer_invoices_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`expense_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `expenses_ibfk_3` FOREIGN KEY (`paid_from_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `expenses_ibfk_4` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`);

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`cogs_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `items_ibfk_2` FOREIGN KEY (`income_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `items_ibfk_3` FOREIGN KEY (`inventory_account_id`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `journal_entries_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`),
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `payments_ibfk_4` FOREIGN KEY (`bank_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `payments_ibfk_5` FOREIGN KEY (`applied_to_txn_id`) REFERENCES `transaction_headers` (`id`);

--
-- Constraints for table `pos_entry`
--
ALTER TABLE `pos_entry`
  ADD CONSTRAINT `pos_entry_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Constraints for table `pos_items`
--
ALTER TABLE `pos_items`
  ADD CONSTRAINT `pos_items_ibfk_1` FOREIGN KEY (`pos_id`) REFERENCES `pos_entry` (`id`),
  ADD CONSTRAINT `pos_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `pos_payments`
--
ALTER TABLE `pos_payments`
  ADD CONSTRAINT `pos_payments_ibfk_1` FOREIGN KEY (`pos_id`) REFERENCES `pos_entry` (`id`),
  ADD CONSTRAINT `pos_payments_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `pos_returns`
--
ALTER TABLE `pos_returns`
  ADD CONSTRAINT `pos_returns_ibfk_1` FOREIGN KEY (`original_pos_id`) REFERENCES `pos_entry` (`id`);

--
-- Constraints for table `pos_return_items`
--
ALTER TABLE `pos_return_items`
  ADD CONSTRAINT `pos_return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `pos_returns` (`id`),
  ADD CONSTRAINT `pos_return_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `transaction_headers`
--
ALTER TABLE `transaction_headers`
  ADD CONSTRAINT `transaction_headers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `transaction_headers_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `transaction_lines`
--
ALTER TABLE `transaction_lines`
  ADD CONSTRAINT `transaction_lines_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `transaction_lines_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `transaction_lines_ibfk_3` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `transaction_links`
--
ALTER TABLE `transaction_links`
  ADD CONSTRAINT `transaction_links_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `transaction_links_ibfk_2` FOREIGN KEY (`child_id`) REFERENCES `transaction_headers` (`id`);

--
-- Constraints for table `user_dashboard_preferences`
--
ALTER TABLE `user_dashboard_preferences`
  ADD CONSTRAINT `user_dashboard_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_meta`
--
ALTER TABLE `user_meta`
  ADD CONSTRAINT `fk_user_meta_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vendors`
--
ALTER TABLE `vendors`
  ADD CONSTRAINT `vendors_ibfk_1` FOREIGN KEY (`payable_account_id`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `vendor_bills`
--
ALTER TABLE `vendor_bills`
  ADD CONSTRAINT `vendor_bills_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `transaction_headers` (`id`),
  ADD CONSTRAINT `vendor_bills_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
