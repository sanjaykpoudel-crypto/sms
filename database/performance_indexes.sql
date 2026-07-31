-- ============================================================
-- performance_indexes.sql
-- ERP Performance Optimization — Missing Index Migration
-- Run once against sms_db to add all high-impact indexes.
-- All use IF NOT EXISTS to be safe to re-run.
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- transaction_headers  (most-queried table in the system)
-- ────────────────────────────────────────────────────────────
-- Used by every report, dashboard, and transaction list
ALTER TABLE `transaction_headers`
    ADD INDEX IF NOT EXISTS `idx_th_date_type_del`   (`txn_date`, `txn_type`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_th_type_status_del` (`txn_type`, `status`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_th_date_del_status`  (`txn_date`, `is_deleted`, `status`),
    ADD INDEX IF NOT EXISTS `idx_th_party_type`       (`party_id`, `party_type`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_th_txn_number`       (`txn_number`),
    ADD INDEX IF NOT EXISTS `idx_th_location_date`    (`location_id`, `txn_date`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_th_fiscal_period`    (`fiscal_period`, `txn_type`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_th_created_at`       (`created_at`);

-- ────────────────────────────────────────────────────────────
-- journal_entries  (hit in every balance/GL/report query)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `journal_entries`
    ADD INDEX IF NOT EXISTS `idx_je_acc_date_type`    (`account_id`, `entry_date`, `entry_type`),
    ADD INDEX IF NOT EXISTS `idx_je_header_id`        (`header_id`),
    ADD INDEX IF NOT EXISTS `idx_je_entry_date`       (`entry_date`),
    ADD INDEX IF NOT EXISTS `idx_je_item_id`          (`item_id`),
    ADD INDEX IF NOT EXISTS `idx_je_party_type_id`    (`party_type`, `party_id`),
    ADD INDEX IF NOT EXISTS `idx_je_acc_header`       (`account_id`, `header_id`),
    ADD INDEX IF NOT EXISTS `idx_je_fiscal_period`    (`fiscal_period`, `account_id`);

-- ────────────────────────────────────────────────────────────
-- transaction_lines  (invoice/bill detail joins)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `transaction_lines`
    ADD INDEX IF NOT EXISTS `idx_tl_header_id`        (`header_id`),
    ADD INDEX IF NOT EXISTS `idx_tl_item_id`          (`item_id`),
    ADD INDEX IF NOT EXISTS `idx_tl_header_item`      (`header_id`, `item_id`);

-- ────────────────────────────────────────────────────────────
-- customer_invoices
-- ────────────────────────────────────────────────────────────
ALTER TABLE `customer_invoices`
    ADD INDEX IF NOT EXISTS `idx_ci_header_id`        (`header_id`),
    ADD INDEX IF NOT EXISTS `idx_ci_customer_id`      (`customer_id`),
    ADD INDEX IF NOT EXISTS `idx_ci_payment_status`   (`payment_status`),
    ADD INDEX IF NOT EXISTS `idx_ci_due_date`         (`due_date`),
    ADD INDEX IF NOT EXISTS `idx_ci_cust_status`      (`customer_id`, `payment_status`),
    ADD INDEX IF NOT EXISTS `idx_ci_balance_due`      (`balance_due`),
    ADD INDEX IF NOT EXISTS `idx_ci_invoice_date`     (`invoice_date`);

-- ────────────────────────────────────────────────────────────
-- vendor_bills
-- ────────────────────────────────────────────────────────────
ALTER TABLE `vendor_bills`
    ADD INDEX IF NOT EXISTS `idx_vb_header_id`        (`header_id`),
    ADD INDEX IF NOT EXISTS `idx_vb_vendor_id`        (`vendor_id`),
    ADD INDEX IF NOT EXISTS `idx_vb_due_date`         (`due_date`),
    ADD INDEX IF NOT EXISTS `idx_vb_balance_due`      (`balance_due`),
    ADD INDEX IF NOT EXISTS `idx_vb_vendor_status`    (`vendor_id`, `balance_due`);

-- ────────────────────────────────────────────────────────────
-- payments
-- ────────────────────────────────────────────────────────────
ALTER TABLE `payments`
    ADD INDEX IF NOT EXISTS `idx_pay_header_id`       (`header_id`),
    ADD INDEX IF NOT EXISTS `idx_pay_date_type`       (`payment_date`, `payment_type`),
    ADD INDEX IF NOT EXISTS `idx_pay_customer_id`     (`customer_id`),
    ADD INDEX IF NOT EXISTS `idx_pay_vendor_id`       (`vendor_id`),
    ADD INDEX IF NOT EXISTS `idx_pay_bank_acct`       (`bank_account_id`);

-- ────────────────────────────────────────────────────────────
-- pos_entry
-- ────────────────────────────────────────────────────────────
ALTER TABLE `pos_entry`
    ADD INDEX IF NOT EXISTS `idx_pe_datetime_del`     (`date_time`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_pe_customer_del`     (`customer_id`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_pe_invoice_no`       (`invoice_no`),
    ADD INDEX IF NOT EXISTS `idx_pe_location_date`    (`location_id`, `date_time`);

-- ────────────────────────────────────────────────────────────
-- pos_items
-- ────────────────────────────────────────────────────────────
ALTER TABLE `pos_items`
    ADD INDEX IF NOT EXISTS `idx_pi_pos_id`           (`pos_id`),
    ADD INDEX IF NOT EXISTS `idx_pi_item_id`          (`item_id`),
    ADD INDEX IF NOT EXISTS `idx_pi_pos_item`         (`pos_id`, `item_id`);

-- ────────────────────────────────────────────────────────────
-- pos_payments
-- ────────────────────────────────────────────────────────────
ALTER TABLE `pos_payments`
    ADD INDEX IF NOT EXISTS `idx_pp_pos_id`           (`pos_id`),
    ADD INDEX IF NOT EXISTS `idx_pp_account_id`       (`account_id`);

-- ────────────────────────────────────────────────────────────
-- items
-- ────────────────────────────────────────────────────────────
ALTER TABLE `items`
    ADD INDEX IF NOT EXISTS `idx_items_del_act_stock` (`is_deleted`, `is_active`, `current_stock`),
    ADD INDEX IF NOT EXISTS `idx_items_sku`           (`sku`),
    ADD INDEX IF NOT EXISTS `idx_items_category`      (`item_category`, `is_deleted`, `is_active`),
    ADD INDEX IF NOT EXISTS `idx_items_reorder`       (`is_active`, `is_deleted`, `reorder_level`, `current_stock`);

-- ────────────────────────────────────────────────────────────
-- accounts
-- ────────────────────────────────────────────────────────────
ALTER TABLE `accounts`
    ADD INDEX IF NOT EXISTS `idx_acc_type_sub`        (`account_type`, `account_subtype`, `is_active`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_acc_subtype_act`     (`account_subtype`, `is_active`, `is_deleted`),
    ADD INDEX IF NOT EXISTS `idx_acc_type_id`         (`account_type_id`);

-- ────────────────────────────────────────────────────────────
-- customers / vendors
-- ────────────────────────────────────────────────────────────
ALTER TABLE `customers`
    ADD INDEX IF NOT EXISTS `idx_cust_del_act`        (`is_deleted`, `is_active`),
    ADD INDEX IF NOT EXISTS `idx_cust_created`        (`created_at`, `is_deleted`);

ALTER TABLE `vendors`
    ADD INDEX IF NOT EXISTS `idx_vend_del_act`        (`is_deleted`, `is_active`),
    ADD INDEX IF NOT EXISTS `idx_vend_created`        (`created_at`, `is_deleted`);

-- ────────────────────────────────────────────────────────────
-- expenses
-- ────────────────────────────────────────────────────────────
ALTER TABLE `expenses`
    ADD INDEX IF NOT EXISTS `idx_exp_header_id`       (`header_id`),
    ADD INDEX IF NOT EXISTS `idx_exp_date_cat`        (`expense_date`, `expense_category`);

-- ────────────────────────────────────────────────────────────
-- system_info  (heavily read, rarely written)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `system_info`
    ADD INDEX IF NOT EXISTS `idx_si_meta_field`       (`meta_field`);

-- ────────────────────────────────────────────────────────────
-- transaction_links
-- ────────────────────────────────────────────────────────────
ALTER TABLE `transaction_links`
    ADD INDEX IF NOT EXISTS `idx_tl_parent_id`        (`parent_id`),
    ADD INDEX IF NOT EXISTS `idx_tl_child_id`         (`child_id`),
    ADD INDEX IF NOT EXISTS `idx_tl_link_type`        (`link_type`),
    ADD INDEX IF NOT EXISTS `idx_tl_parent_child`     (`parent_id`, `child_id`);

-- ────────────────────────────────────────────────────────────
-- cash_denominations
-- ────────────────────────────────────────────────────────────
ALTER TABLE `cash_denominations`
    ADD INDEX IF NOT EXISTS `idx_cd_date_type`        (`denomination_date`, `denomination_type`),
    ADD INDEX IF NOT EXISTS `idx_cd_header_id`        (`header_id`);

-- ────────────────────────────────────────────────────────────
-- inventory_balances
-- ────────────────────────────────────────────────────────────
ALTER TABLE `inventory_balances`
    ADD INDEX IF NOT EXISTS `idx_ib_item_loc`         (`item_id`, `location_id`);

-- ────────────────────────────────────────────────────────────
-- dashboard_kpi_cache
-- ────────────────────────────────────────────────────────────
ALTER TABLE `dashboard_kpi_cache`
    ADD INDEX IF NOT EXISTS `idx_dkc_key_expires`     (`cache_key`, `expires_at`);

-- ────────────────────────────────────────────────────────────
-- system_logs  (write-heavy, read occasionally)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `system_logs`
    ADD INDEX IF NOT EXISTS `idx_sl_date_user`        (`date_created`, `user_id`),
    ADD INDEX IF NOT EXISTS `idx_sl_ref_id`           (`ref_id`),
    ADD INDEX IF NOT EXISTS `idx_sl_table_action`     (`table_name`, `action_type`);

-- ────────────────────────────────────────────────────────────
-- reference_codes  (used for tax, categories, payment_status)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `reference_codes`
    ADD INDEX IF NOT EXISTS `idx_rc_type_active`      (`type`, `is_active`),
    ADD INDEX IF NOT EXISTS `idx_rc_code`             (`code`);

-- ────────────────────────────────────────────────────────────
-- fiscal_years
-- ────────────────────────────────────────────────────────────
ALTER TABLE `fiscal_years`
    ADD INDEX IF NOT EXISTS `idx_fy_status`           (`status`),
    ADD INDEX IF NOT EXISTS `idx_fy_dates`            (`start_date`, `end_date`);

-- Done.
SELECT 'Performance indexes applied successfully.' AS result;
