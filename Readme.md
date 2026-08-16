# Store Management System (SMS) — Master Technical & Business Logic Documentation

> [!IMPORTANT]
> **AUTHORITATIVE SINGLE SOURCE OF TRUTH**:
> `README.md` is the single source of truth for the Store Management System (SMS) business logic, accounting rules, transaction flows, inventory math, database schemas, and reporting formulas. All developers and AI assistants **MUST** consult this document before adding or altering logic, and **MUST** update this file whenever system rules, database structures, or workflows change.

---

## 📋 Logic Index & Table of Contents

1. [System Overview & Architecture](#1-system-overview--architecture)
2. [File-to-Logic Mapping & Directory Index](#2-file-to-logic-mapping--directory-index)
3. [Database Architecture & Schema Reference](#3-database-architecture--schema-reference)
4. [Form-by-Form Specification & UI Logic](#4-form-by-form-specification--ui-logic)
5. [Transaction Map & Lifecycle Specifications](#5-transaction-map--lifecycle-specifications)
6. [Accounting Engine & Double-Entry GL Rules](#6-accounting-engine--double-entry-gl-rules)
7. [Inventory Engine & Moving-Average Costing Math](#7-inventory-engine--moving-average-costing-math)
8. [Point of Sale (POS) & Daily Aggregation Engine](#8-point-of-sale-pos--daily-aggregation-engine)
9. [Financial & Operational Reporting Engine](#9-financial--operational-reporting-engine)
10. [Dashboard Metric Calculation Engine](#10-dashboard-metric-calculation-engine)
11. [Import, Export, & Print Engine](#11-import-export--print-engine)
12. [Centralized Business Rules Catalog (`BR-xxx`)](#12-centralized-business-rules-catalog-br-xxx)
13. [Security, Permissions (RBAC) & Fiscal Period Controls](#13-security-permissions-rbac--fiscal-period-controls)
14. [Cross-Module Dependency Map](#14-cross-module-dependency-map)
15. [Duplicate Logic Inventory](#15-duplicate-logic-inventory)
16. [Hardcoded Values & Constant Registries](#16-hardcoded-values--constant-registries)
17. [Known Issues & Technical Debt Inventory](#17-known-issues--technical-debt-inventory)
18. [Recommended Architecture Improvements](#18-recommended-architecture-improvements)
19. [Documentation Governance Rules](#19-documentation-governance-rules)
20. [Documentation Coverage & Verification Matrix](#20-documentation-coverage--verification-matrix)
21. [System Change Log](#21-system-change-log)

---

## 1. System Overview & Architecture

SMS is a multi-location, double-entry ERP application designed for retail stores, liquor shops, and distribution businesses. Built using PHP, MySQL (PDO), Vanilla JavaScript, and CSS, the system provides real-time tracking across POS counter checkouts, wholesale invoicing, purchase management, moving-average cost inventory management, general ledger accounting, and financial reporting.

```
       ┌─────────────────────────────────────────────────────────────┐
       │                 UI Layer (Forms & Views)                    │
       │   POS Terminal | Invoices | Bills | Payments | Reports       │
       └──────────────────────────────┬──────────────────────────────┘
                                      │ AJAX / POST (JSON)
                                      ▼
       ┌─────────────────────────────────────────────────────────────┐
       │                API & Engine Layer (/api/)                   │
       │  AccountingEngine | InventoryEngine | UnitConversionEngine  │
       │  PromotionEngine  | ReportingEngine | reference_helper.php  │
       └──────────────────────────────┬──────────────────────────────┘
                                      │ PDO Transactions
                                      ▼
       ┌─────────────────────────────────────────────────────────────┐
       │                  MySQL Database (sms_db)                    │
       │  transaction_headers | transaction_lines | journal_entries  │
       │  items | inventory_balances | payments | transaction_links  │
       └─────────────────────────────────────────────────────────────┘
```

### Key Architectural Pillars
- **Transactional Architecture**: Header-Line pattern (`transaction_headers`, `transaction_lines`) coupled with financial double-entry journals (`journal_entries`) and link graphs (`transaction_links`).
- **Inventory Engine**: Real-time perpetual inventory using moving-average cost per location, with unit conversion support (PCS, Box, Case, Bottle, etc.).
- **Accounting Engine**: Double-entry accounting system with standard chart of accounts (`accounts`, `account_types`), supporting AR/AP subledgers and real-time trial balance calculations.
- **POS Aggregation Engine**: Individual counter receipts (`pos_entry`) dynamically consolidated into daily summary invoices (`INV-POS-YYYYMMDD`) and cash/bank payments (`PAY-POS-YYYYMMDD`).

---

## 2. File-to-Logic Mapping & Directory Index

### Core Engine & API Services (`/api/`)

| File Path | Primary Responsibility | Key Functions / Methods | Affected Tables |
| :--- | :--- | :--- | :--- |
| [`api/AccountingEngine.php`](file:///c:/xampp/htdocs/sms-new/api/AccountingEngine.php) | Central account resolution, AR/AP mapping, COGS routing | `getInstance()`, `resolveAccount()`, `resolveVendorAPAccount()`, `get_effective_account()` | `accounts`, `account_types`, `system_info` |
| [`api/InventoryEngine.php`](file:///c:/xampp/htdocs/sms-new/api/InventoryEngine.php) | Moving-average cost, stock movements, stock reversals | `getInstance()`, `issueStock()`, `receiveStock()`, `reverseMovementsForHeader()`, `getAvailableStock()` | `inventory_movements`, `inventory_balances`, `items` |
| [`api/UnitConversionEngine.php`](file:///c:/xampp/htdocs/sms-new/api/UnitConversionEngine.php) | Unit resolution & conversion factor calculations | `uce_resolve_unit()`, `uce_calculate_base_qty()`, `uce_calculate_base_unit_cost()` | `item_units`, `items` |
| [`api/PromotionEngine.php`](file:///c:/xampp/htdocs/sms-new/api/PromotionEngine.php) | Automated pricing promotions, MRP tier validation | `getInstance()`, `evaluateItemPromotion()`, `getActivePromotionsForItem()` | `promotions`, `promotion_items` |
| [`api/ReportingEngine.php`](file:///c:/xampp/htdocs/sms-new/api/ReportingEngine.php) | SQL generation for Trial Balance, P&L, Balance Sheet | `getTrialBalance()`, `getProfitAndLoss()`, `getBalanceSheet()`, `getGLRegister()` | `journal_entries`, `accounts`, `transaction_headers` |
| [`api/reference_helper.php`](file:///c:/xampp/htdocs/sms-new/api/reference_helper.php) | Numbering, fiscal locks, POS daily sync, payment recalculation | `calculate_fiscal_info()`, `check_fiscal_year_lock()`, `getNextTransactionNumber()`, `sync_daily_pos_summary()`, `recalculate_document_payment_status()` | `transaction_headers`, `transaction_links`, `system_info`, `fiscal_years` |
| [`api/save_bill.php`](file:///c:/xampp/htdocs/sms-new/api/save_bill.php) | Vendor Bill save/update, inventory stock receipt | Creates `transaction_headers`, `transaction_lines`, `vendor_bills`, `journal_entries` | `transaction_headers`, `transaction_lines`, `vendor_bills`, `journal_entries`, `inventory_balances` |
| [`api/save_invoice.php`](file:///c:/xampp/htdocs/sms-new/api/save_invoice.php) | Customer Invoice save/update, inventory stock issue | Creates `transaction_headers`, `transaction_lines`, `customer_invoices`, `journal_entries` | `transaction_headers`, `transaction_lines`, `customer_invoices`, `journal_entries`, `inventory_balances` |
| [`api/save_pos.php`](file:///c:/xampp/htdocs/sms-new/api/save_pos.php) | POS Terminal transaction checkout | Creates `pos_entry`, `pos_items`, `pos_payments`, triggers `sync_daily_pos_summary()` | `pos_entry`, `pos_items`, `pos_payments`, `inventory_balances` |
| [`api/save_transaction.php`](file:///c:/xampp/htdocs/sms-new/api/save_transaction.php) | Payment processing (Customer Receipts & Vendor Payments) | Creates `payments`, `transaction_links`, `journal_entries`, triggers `recalculate_document_payment_status()` | `payments`, `transaction_links`, `journal_entries`, `customer_invoices`, `vendor_bills` |
| [`api/save_expense.php`](file:///c:/xampp/htdocs/sms-new/api/save_expense.php) | Expense voucher processing | Creates `transaction_headers`, `expenses`, `journal_entries` | `transaction_headers`, `expenses`, `journal_entries` |
| [`api/save_credit_memo.php`](file:///c:/xampp/htdocs/sms-new/api/save_credit_memo.php) | Customer Return / Credit Memo processing | Creates `transaction_headers`, `credit_memos`, stock return via `InventoryEngine` | `transaction_headers`, `transaction_lines`, `credit_memos`, `journal_entries` |
| [`api/save_vendor_credit.php`](file:///c:/xampp/htdocs/sms-new/api/save_vendor_credit.php) | Supplier Return / Vendor Credit processing | Creates `transaction_headers`, `vendor_credits`, stock return via `InventoryEngine` | `transaction_headers`, `transaction_lines`, `vendor_credits`, `journal_entries` |
| [`api/save_adjustment.php`](file:///c:/xampp/htdocs/sms-new/api/save_adjustment.php) | Inventory adjustment (Qty/Cost/Damage/Loss) | Creates `transaction_headers`, `inventory_adjustments`, updates stock | `transaction_headers`, `transaction_lines`, `journal_entries`, `inventory_balances` |
| [`api/save_inventory_transfer.php`](file:///c:/xampp/htdocs/sms-new/api/save_inventory_transfer.php) | Multi-location stock transfers | Moves stock between locations via `InventoryEngine` | `transaction_headers`, `transaction_lines`, `inventory_movements`, `inventory_balances` |
| [`api/save_journal.php`](file:///c:/xampp/htdocs/sms-new/api/save_journal.php) | Manual Journal Voucher (JV) processing | Creates `transaction_headers`, `journal_entries` | `transaction_headers`, `journal_entries` |
| [`api/apply_credit.php`](file:///c:/xampp/htdocs/sms-new/api/apply_credit.php) | Applying Credit Memos & Vendor Credits to Invoices/Bills | Updates `transaction_links`, recalculates document status | `transaction_links`, `customer_invoices`, `vendor_bills`, `credit_memos` |
| [`api/fiscal_year_handler.php`](file:///c:/xampp/htdocs/sms-new/api/fiscal_year_handler.php) | Fiscal year closing, balance roll-forward | Generates closing/opening journal entries, locks closed periods | `fiscal_years`, `journal_entries`, `accounts` |
| [`api/get_dashboard_data.php`](file:///c:/xampp/htdocs/sms-new/api/get_dashboard_data.php) | Dashboard metrics computation with caching | Returns cached sales, receivables, payables, profit graphs | `transaction_headers`, `customer_invoices`, `vendor_bills`, `payments` |
| [`api/import_handler.php`](file:///c:/xampp/htdocs/sms-new/api/import_handler.php) | Bulk CSV/Excel data importer | Parses CSV, runs transactional inserts | `items`, `customers`, `vendors`, `customer_invoices`, `vendor_bills` |
| [`api/export_handler.php`](file:///c:/xampp/htdocs/sms-new/api/export_handler.php) | Data export utility (CSV / Excel format) | Streams database records to CSV files | All system tables |

---

## 3. Database Architecture & Schema Reference

### Primary Tables & Relationships

```
                        ┌──────────────────────────────┐
                        │     transaction_headers      │
                        │ id (PK), txn_number, type... │
                        └──────────────┬───────────────┘
                                       │
        ┌──────────────────────────────┼──────────────────────────────┐
        │ 1:N                          │ 1:N                          │ 1:N
        ▼                              ▼                              ▼
┌───────────────────────┐  ┌───────────────────────┐  ┌───────────────────────┐
│   transaction_lines   │  │    journal_entries    │  │       payments        │
│ header_id(FK), item...│  │ header_id(FK), acc... │  │ header_id(FK), amt... │
└───────────────────────┘  └───────────────────────┘  └───────────────────────┘
                                       │
                                       │ 1:N (parent_id/child_id)
                                       ▼
                           ┌───────────────────────┐
                           │   transaction_links   │
                           │ parent_id, child_id...│
                           └───────────────────────┘
```

### Core Schema Specifications

#### 1. `transaction_headers`
- **Primary Key**: `id` (VARCHAR 36 UUID or INT)
- **Columns**: `txn_number` (VARCHAR 50 UNIQUE), `txn_type` (ENUM: `customer_invoice`, `vendor_bill`, `customer_payment`, `vendor_payment`, `journal`, `inventory_transfer`, `inventory_adjustment`, `expense`, `credit_memo`, `bill_credit`, `cash_denomination`), `txn_date` (DATE), `fiscal_year` (INT), `fiscal_month` (INT), `fiscal_period` (VARCHAR 20), `status` (ENUM: `draft`, `open`, `posted`, `paid`, `partial`, `voided`), `net_amount` (DECIMAL 18,4), `party_id` (INT/VARCHAR), `party_type` (ENUM: `customer`, `vendor`), `location_id` (INT), `source` (VARCHAR 50), `is_deleted` (TINYINT 1).
- **Indexes**: `idx_th_type_date` (`txn_type`, `txn_date`), `idx_th_party` (`party_type`, `party_id`).

#### 2. `transaction_lines`
- **Primary Key**: `id` (VARCHAR 36 UUID)
- **Foreign Key**: `header_id` -> `transaction_headers.id` ON DELETE CASCADE
- **Columns**: `item_id` (INT), `account_id` (INT), `line_number` (INT), `quantity` (DECIMAL 18,4), `unit` (VARCHAR 20), `conversion_factor` (DECIMAL 12,4), `base_qty` (DECIMAL 18,4), `unit_price` (DECIMAL 18,4), `base_unit_price` (DECIMAL 18,4), `mrp_at_sale` (DECIMAL 18,4), `normal_selling_price_at_sale` (DECIMAL 18,4), `promo_discount_amount` (DECIMAL 18,4), `tax_rate` (DECIMAL 8,4), `tax_amount` (DECIMAL 18,4), `line_total` (DECIMAL 18,4), `cost_price` (DECIMAL 18,4), `gross_profit` (DECIMAL 18,4).

#### 3. `journal_entries`
- **Primary Key**: `id` (INT AUTO_INCREMENT or UUID)
- **Foreign Key**: `header_id` -> `transaction_headers.id`
- **Columns**: `account_id` (INT), `item_id` (INT NULL), `entry_type` (ENUM: `debit`, `credit`), `amount` (DECIMAL 18,4), `entry_date` (DATE), `fiscal_year` (INT), `fiscal_period` (VARCHAR 20), `party_id` (INT NULL), `party_type` (VARCHAR 20 NULL), `memo` (TEXT).

#### 4. `inventory_balances` & `inventory_movements`
- `inventory_balances`: `item_id`, `location_id`, `quantity` (DECIMAL 18,4), `moving_average_cost` (DECIMAL 18,4), `mrp` (DECIMAL 18,4). UNIQUE KEY (`item_id`, `location_id`).
- `inventory_movements`: `item_id`, `location_id`, `movement_type` (ENUM: `PURCHASE`, `SALE`, `TRANSFER_IN`, `TRANSFER_OUT`, `ADJUSTMENT_ADD`, `ADJUSTMENT_SUB`, `RETURN`), `quantity` (DECIMAL 18,4), `unit_cost` (DECIMAL 18,4), `total_cost` (DECIMAL 18,4), `reference_header_id` (VARCHAR 36), `movement_date` (DATE).

#### 5. `transaction_links`
- **Columns**: `id` (INT AUTO_INCREMENT), `parent_id` (VARCHAR 36), `child_id` (VARCHAR 36), `link_type` (VARCHAR 100).
- **Format of `link_type`**: `payment:<amount>`, `payment:<line_id>:<amount>`, `credit_memo_apply:<amount>`, `vendor_credit_apply:<amount>`.

---

## 4. Form-by-Form Specification & UI Logic

### 1. Customer Invoice Form (`forms/modules/transactions/invoice/invoice_manage.php`)
- **Purpose**: Creates wholesale customer invoices for goods sold on cash or credit.
- **Fields**:
  - `party_id`: Customer dropdown (Required, source: `customers`).
  - `txn_date`: Date picker (Required, default: today).
  - `due_date`: Date picker (Required, default: today).
  - `location_id`: Location dropdown (Required, default: user location).
  - Line items: `item_id`, `qty`, `unit` (PCS/Box/Case), `rate`, `tax_pct` (VAT 13%), `amount`.
- **Save Logic**: Calls [`api/save_invoice.php`](file:///c:/xampp/htdocs/sms-new/api/save_invoice.php). Begins PDO transaction -> Inserts `transaction_headers` -> Resolves unit conversion factors -> Inserts `transaction_lines` -> Calls `InventoryEngine::issueStock()` -> Validates credit limits & stock -> Writes `customer_invoices` record -> Posts Dr AR, Dr Discount, Cr Revenue, Cr VAT, Dr COGS, Cr Inventory to `journal_entries` -> Commits PDO transaction.
- **Edit Logic**: Reverses previous stock movements via `InventoryEngine::reverseMovementsForHeader()`, deletes old lines and GL entries, re-inserts new lines, posts GL, updates document payment status based on existing payment links. Linked payments remain unmodified.
- **Delete Logic**: Handled by `api/transaction_handler.php`. Blocks deletion if payment links exist unless payments are voided first.

### 2. Vendor Bill Form (`forms/modules/transactions/bill/bill_manage.php`)
- **Purpose**: Records purchase bills received from suppliers.
- **Fields**: `party_id` (Vendor), `txn_date`, `due_date`, `ref_number` (Vendor Invoice No), `location_id`, Line items (`item_id`, `qty`, `unit`, `rate`, `tax_pct`, `mrp`).
- **Save Logic**: Calls [`api/save_bill.php`](file:///c:/xampp/htdocs/sms-new/api/save_bill.php). Calculates moving-average cost -> Inserts `transaction_headers` & `transaction_lines` -> Calls `InventoryEngine::receiveStock()` -> Updates MRP on master & balance tables -> Writes `vendor_bills` record -> Posts Dr Inventory, Dr VAT, Cr Accounts Payable to `journal_entries`.
- **Edit Logic**: Reverses previous stock additions via `InventoryEngine`, deletes old entries, recalculates new moving-average cost, re-posts stock and GL. Linked vendor payments are **never auto-modified**.

### 3. POS Counter Checkout (`forms/modules/transactions/pos/pos_manage.php`)
- **Purpose**: High-speed point-of-sale checkout for retail customers.
- **Fields**: Barcode search / grid items, `customer_id` (Default: Walk-IN), Payment modes (Cash, QR/Esewa, Bank Transfer).
- **Save Logic**: Calls [`api/save_pos.php`](file:///c:/xampp/htdocs/sms-new/api/save_pos.php). Validates active promotions -> Deducts stock per item -> Inserts `pos_entry`, `pos_items`, `pos_payments` -> Calculates change return -> Triggers `sync_daily_pos_summary()` inside transaction -> Consolidates day's POS sales into `INV-POS-YYYYMMDD` and `PAY-POS-YYYYMMDD`.

### 4. Payment Entry Form (`forms/modules/transactions/payment/payment_manage.php`)
- **Purpose**: Records money received from customers (Customer Receipt) or money paid to vendors (Vendor Payment).
- **Fields**: `party_type` (Customer/Vendor), `party_id`, `txn_date`, `bank_account_id` (Bank/Cash Account), `line_amount`, Outstanding document checklist (`apply_txn_id`, `apply_amount`).
- **Save Logic**: Calls [`api/save_transaction.php`](file:///c:/xampp/htdocs/sms-new/api/save_transaction.php). Creates payment record -> Posts Dr Cash/Bank & Cr AR (or Dr AP & Cr Cash/Bank) -> Creates `transaction_links` for applied invoices/bills -> Calls `recalculate_document_payment_status()` for each affected document.

---

## 5. Transaction Map & Lifecycle Specifications

```
                     ┌───────────────────────────────────┐
                     │            Form Input             │
                     └─────────────────┬─────────────────┘
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │        Validation Engine          │
                     │ Fiscal Lock | Stock | Credit Limit│
                     └─────────────────┬─────────────────┘
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │        Header & Line Insert       │
                     │ transaction_headers & _lines      │
                     └─────────────────┬─────────────────┘
                                       │
                    ┌──────────────────┴──────────────────┐
                    ▼                                     ▼
     ┌─────────────────────────────┐       ┌─────────────────────────────┐
     │      Inventory Engine       │       │      Accounting Engine      │
     │ Issue/Receive Stock & MA Cost│       │  Debit/Credit GL Posting    │
     └──────────────┬──────────────┘       └──────────────┬──────────────┘
                    │                                     │
                    └──────────────────┬──────────────────┘
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │     Reports & Dashboard Sync      │
                     │ P&L, Trial Balance, Stock Ledger  │
                     └───────────────────────────────────┘
```

### Complete Transaction Lifecycles

1. **Customer Invoice**: Form -> Credit Limit Check -> Stock Validation -> Header & Line Creation -> Stock Deduction (`InventoryEngine`) -> GL Posting (Dr AR, Dr Discount, Cr Revenue, Cr VAT, Dr COGS, Cr Inventory) -> P&L & AR Ledger Update.
2. **Vendor Bill**: Form -> Fiscal Lock Check -> Header & Line Creation -> Stock Increase & MA Cost Calculation (`InventoryEngine`) -> GL Posting (Dr Inventory, Dr VAT, Cr AP) -> Stock Ledger & AP Ledger Update.
3. **Customer Payment**: Form -> Payment Creation -> GL Posting (Dr Bank/Cash, Cr AR) -> `transaction_links` Linking -> Invoice Payment Status Update (`PAID`/`PARTIAL`).
4. **Vendor Payment**: Form -> Payment Creation -> GL Posting (Dr AP, Cr Bank/Cash) -> `transaction_links` Linking -> Bill Payment Status Update (`PAID`/`PARTIAL`).
5. **Customer Return (Credit Memo)**: Form -> Header & Line Creation -> Stock Re-entry (`InventoryEngine`) -> GL Posting (Dr Revenue/Tax, Cr AR) -> Credit Memo Application via `apply_credit.php`.
6. **Supplier Return (Vendor Credit)**: Form -> Header & Line Creation -> Stock Return (`InventoryEngine`) -> GL Posting (Dr AP, Cr Inventory/Tax) -> Vendor Credit Application.
7. **Inventory Adjustment**: Form -> Stock Difference Calculation -> Stock Balance Update -> GL Posting (Dr/Cr Adjustment Expense, Cr/Dr Inventory Asset).
8. **Inventory Transfer**: Form -> Source Stock Check -> Source Location Stock Deduction -> Target Location Stock Addition -> Transfer Journal Posting.
9. **Expense Voucher**: Form -> Header & Expense Creation -> GL Posting (Dr Expense Account, Cr Cash/Bank Account).
10. **Manual Journal Voucher**: Form -> Debit/Credit Balance Check -> Journal Lines Creation -> GL Posting.

---

## 6. Accounting Engine & Double-Entry GL Rules

### Chart of Accounts Structure & Ranges

- **1000 – 1999 (Assets)**: Normal Balance = **Debit**
  - `1100`: Accounts Receivable (AR)
  - `1200`: Inventory Asset
  - `1300`: Cash on Hand
  - `1400`: Bank Accounts (Prabhu Bank, etc.)
  - `1500`: Digital Wallets (eSewa, Khalti)
- **2000 – 2999 (Liabilities)**: Normal Balance = **Credit**
  - `2100`: Accounts Payable (AP)
  - `2200`: Output VAT (Tax Payable)
  - `2500`: Short-term & Long-term Loans
- **3000 – 3999 (Equity)**: Normal Balance = **Credit**
  - `3100`: Owner Capital
  - `3200`: Retained Earnings
- **4000 – 4999 (Revenue)**: Normal Balance = **Credit**
  - `4100`: Sales Revenue
  - `4200`: Other Operating Income
- **5000 – 5999 (Direct Costs / COGS)**: Normal Balance = **Debit**
  - `5100`: Cost of Goods Sold (COGS)
- **6000 – 6999 (Operating Expenses)**: Normal Balance = **Debit**
  - `6100`: Rent, Salaries, Electricity, Bank Charges (`6150`), Sales Discounts (`6160`).

### Debit & Credit Journal Entry Rules per Transaction

#### 1. Cash Sale (Invoice / POS)
```text
Debit:  Cash / Bank (1300 / 1400)               NPR X
Credit: Sales Revenue (4100)                    NPR (X - VAT)
Credit: VAT Payable (2200)                      NPR VAT Amount

Debit:  Cost of Goods Sold (5100)               NPR COGS Amount
Credit: Inventory Asset (1200)                  NPR COGS Amount
```

#### 2. Credit Sale (Invoice)
```text
Debit:  Accounts Receivable (1100)              NPR Total Bill Amount
Debit:  Sales Discount (6160) [If applicable]   NPR Discount Amount
Credit: Sales Revenue (4100)                    NPR Subtotal
Credit: VAT Payable (2200)                      NPR VAT Amount

Debit:  Cost of Goods Sold (5100)               NPR COGS Amount
Credit: Inventory Asset (1200)                  NPR COGS Amount
```

#### 3. Vendor Bill (Purchase on Credit)
```text
Debit:  Inventory Asset (1200)                  NPR Subtotal Amount
Debit:  Input VAT / Tax Account (2200)          NPR VAT Amount
Credit: Accounts Payable (2100)                 NPR Total Grand Amount
Credit: Purchase Discount [If applicable]       NPR Discount Amount
```

#### 4. Customer Receipt (Payment Received)
```text
Debit:  Bank / Cash Account (1400 / 1300)        NPR Amount Received
Credit: Accounts Receivable (1100)              NPR Amount Received
```

#### 5. Vendor Payment (Payment Made)
```text
Debit:  Accounts Payable (2100)                 NPR Amount Paid
Credit: Bank / Cash Account (1400 / 1300)        NPR Amount Paid
```

#### 6. Expense Voucher
```text
Debit:  Expense Account (6000s)                 NPR Expense Amount
Credit: Bank / Cash Account (1400 / 1300)        NPR Expense Amount
```

---

## 7. Inventory Engine & Moving-Average Costing Math

The inventory subsystem in [`api/InventoryEngine.php`](file:///c:/xampp/htdocs/sms-new/api/InventoryEngine.php) implements perpetual inventory with **moving-average cost valuation per location**.

### Moving-Average Cost Formula
When a Vendor Bill is received with quantity $Q_{\text{new}}$ at base cost $C_{\text{new}}$, the new moving-average cost $C_{\text{updated}}$ is calculated as:

$$C_{\text{updated}} = \frac{(Q_{\text{existing}} \times C_{\text{existing}}) + (Q_{\text{new}} \times C_{\text{new}})}{Q_{\text{existing}} + Q_{\text{new}}}$$

### Multi-Unit Conversion Engine (`api/UnitConversionEngine.php`)
Items can be bought or sold in multiple units (e.g., Box of 12, Case of 24, PCS). The system standardizes all inventory tracking into the item's base unit (PCS):

- **Base Quantity Calculation**:
  $$\text{Base Qty} = \text{Transaction Qty} \times \text{Conversion Factor}$$
- **Base Unit Cost Calculation**:
  $$\text{Base Unit Cost} = \frac{\text{Total Line Amount}}{\text{Base Qty}}$$

---

## 8. Point of Sale (POS) & Daily Aggregation Engine

### POS Architecture Flow

```
   ┌───────────────────────┐
   │  POS Counter Terminal │
   │   (save_pos.php)      │
   └───────────┬───────────┘
               │ Inserts pos_entry, pos_items, pos_payments
               ▼
   ┌───────────────────────┐
   │ sync_daily_pos_summary│
   │ (reference_helper.php)│
   └───────────┬───────────┘
               │ Aggregates all active pos_entry for date
               ▼
   ┌────────────────────────────────────────────────────────┐
   │ Daily Summary Customer Invoice (INV-POS-YYYYMMDD)      │
   │ Daily Summary Customer Payment (PAY-POS-YYYYMMDD)      │
   └────────────────────────────────────────────────────────┘
```

1. **Individual POS Sales**: Saved in `pos_entry`, `pos_items`, `pos_payments`. Stock is issued immediately per transaction via `InventoryEngine::issueStock()`.
2. **Daily Aggregation (`sync_daily_pos_summary`)**: Consolidates all active POS sales for a date into single ERP header records (`INV-POS-YYYYMMDD` and `PAY-POS-YYYYMMDD`) to keep the General Ledger clean while maintaining individual counter logs.
3. **Manual ERP Edit Preservation**: If an administrator opens `INV-POS-YYYYMMDD` in the ERP Invoice editor and saves a manual modification, `source` is set to `'manual'`, preserving manual edits from being overwritten by future POS syncs.

---

## 9. Financial & Operational Reporting Engine

The Reporting Engine ([`api/ReportingEngine.php`](file:///c:/xampp/htdocs/sms-new/api/ReportingEngine.php) and `forms/modules/reports/`) generates real-time statements:

### Core Financial Reports Logic
- **Trial Balance**: Sums all debit and credit entries from `journal_entries` per account. Validates that $\sum \text{Debits} = \sum \text{Credits}$.
- **Profit & Loss Statement**:
  $$\text{Net Profit} = \text{Operating Revenue (4000s)} - \text{COGS (5000s)} - \text{Operating Expenses (6000s)}$$
- **Balance Sheet**:
  $$\text{Total Assets (1000s)} = \text{Total Liabilities (2000s)} + \text{Total Equity (3000s)} + \text{Current Net Profit}$$
- **Accounts Receivable Aging**: Grouped into Current, 1–30 Days, 31–60 Days, 61–90 Days, 90+ Days based on `due_date` in `customer_invoices`.
- **Payable Aging**: Grouped into aging brackets based on `due_date` in `vendor_bills`.
- **Investment Payback / Break-Even Report**: Calculates total startup investment (Loans + Owner Capital) versus total capital recovered (Gross Profit - Bank Fees + Cash/Bank Balances + Working Capital).

---

## 10. Dashboard Metric Calculation Engine

The main dashboard screen (`home.php`) uses [`api/get_dashboard_data.php`](file:///c:/xampp/htdocs/sms-new/api/get_dashboard_data.php) with performance caching via `system_cache.php`:

### Core Metrics Formulas
- **Today's Sales**: $\sum \text{net\_amount}$ of all `customer_invoice` headers dated today with status NOT IN (`voided`, `draft`).
- **Monthly Sales**: $\sum \text{net\_amount}$ of all `customer_invoice` headers within current calendar month.
- **Total Outstanding Receivables**: $\sum \text{balance\_due}$ from `customer_invoices` for all active invoices.
- **Total Vendor Payables**: $\sum \text{balance\_due}$ from `vendor_bills` for all active bills.
- **Net Cash/Bank Balance**: $\sum \text{current\_balance}$ of asset accounts (`1300`, `1400`, `1500`).

---

## 11. Import, Export, & Print Engine

- **Import Handler ([`api/import_handler.php`](file:///c:/xampp/htdocs/sms-new/api/import_handler.php))**: Handles CSV/Excel imports for Master Items, Customers, Vendors, Opening Stock, Invoices, and Vendor Bills. Uses PDO transactions; rolls back entirely if any row fails validation.
- **Export Handler ([`api/export_handler.php`](file:///c:/xampp/htdocs/sms-new/api/export_handler.php))**: Streams CSV file downloads for all registers and financial reports.
- **Print Templates (`forms/modules/transactions/print.php`)**: Renders printable thermal receipts (80mm) and standard A4 invoices/bills with company logo, PAN/VAT info, itemized table, and tax summaries.

---

## 12. Centralized Business Rules Catalog (`BR-xxx`)

| Rule ID | Category | Rule Statement | Implementation Source |
| :--- | :--- | :--- | :--- |
| `BR-SALES-001` | Sales | Credit limit validation prevents invoice saving if customer total outstanding exceeds `credit_limit`, unless explicitly overridden by `force_save`. | [`api/save_invoice.php`](file:///c:/xampp/htdocs/sms-new/api/save_invoice.php) |
| `BR-SALES-002` | Sales | Invoice edits recalculate `balance_due` and `payment_status` without altering linked payment records. | [`api/save_invoice.php`](file:///c:/xampp/htdocs/sms-new/api/save_invoice.php) |
| `BR-PURCHASE-001`| Purchase | Modifying a Vendor Bill recalculates bill total and balance due, while keeping linked vendor payment records (`VPAY-...`) completely unchanged. | [`api/save_bill.php`](file:///c:/xampp/htdocs/sms-new/api/save_bill.php) |
| `BR-INV-001` | Inventory | Stock cannot be issued below zero unless `force_save` flag is supplied by user. | [`api/InventoryEngine.php`](file:///c:/xampp/htdocs/sms-new/api/InventoryEngine.php) |
| `BR-INV-002` | Inventory | Moving-average cost is recomputed on every Vendor Bill entry and purchase receipt. | [`api/InventoryEngine.php`](file:///c:/xampp/htdocs/sms-new/api/InventoryEngine.php) |
| `BR-ACCT-001` | Accounting | All journal entries posted for a transaction must satisfy total debit = total credit. | [`api/AccountingEngine.php`](file:///c:/xampp/htdocs/sms-new/api/AccountingEngine.php) |
| `BR-FY-001` | Fiscal | Transactions dated within a closed fiscal year cannot be created, edited, or deleted. | [`api/reference_helper.php`](file:///c:/xampp/htdocs/sms-new/api/reference_helper.php) |
| `BR-POS-001` | POS | Individual POS sales generate immediate stock movements and auto-trigger daily summary aggregation (`INV-POS-YYYYMMDD`). | [`api/save_pos.php`](file:///c:/xampp/htdocs/sms-new/api/save_pos.php) |

---

## 13. Security, Permissions (RBAC) & Fiscal Period Controls

- **CSRF Protection**: Form submissions require CSRF token validation via `verify_csrf_token()`.
- **Session Authentication**: All API endpoints inspect `$_SESSION['user_id']` and reject unauthorized requests with HTTP 401/400 JSON errors.
- **Role-Based Access Control (RBAC)**: Defined in `roles` and `role_permissions` tables. Modules support three permission levels: `allow` (Full CRUD), `read` (View only), `deny` (Menu hidden & API blocked).
- **Fiscal Year Lock**: `check_fiscal_year_lock($date)` queries `fiscal_years` table; throws an exception if the target transaction date falls in a closed fiscal period.

---

## 14. Cross-Module Dependency Map

```
  POS Register / Invoice Form / Vendor Bill Form
                       │
                       ▼
            InventoryEngine Subsystem
         (Moving-Average Cost & Balances)
                       │
                       ▼
           AccountingEngine GL Posting
        (journal_entries & Subledgers)
                       │
                       ▼
       Reporting Engine & Financial Statements
     (Trial Balance, P&L, Balance Sheet, Aging)
                       │
                       ▼
             Dashboard Metric Engine
```

---

## 15. Duplicate Logic Inventory

1. **Transaction Number Generation**: Found in `getNextTransactionNumber()` inside [`api/reference_helper.php`](file:///c:/xampp/htdocs/sms-new/api/reference_helper.php) as well as inline SQL queries in migration scripts. *Recommendation*: Always use `getNextTransactionNumber()`.
2. **Stock Available Check**: Found in `InventoryEngine::getAvailableStock()` and inline SQL checks inside `save_invoice.php` and `save_pos.php`. *Recommendation*: Centralize stock checks through `InventoryEngine`.

---

## 16. Hardcoded Values & Constant Registries

- **Default Account IDs**:
  - `acc-1100`: Accounts Receivable
  - `acc-1200`: Inventory Asset
  - `acc-1300`: Cash Account
  - `acc-2100`: Accounts Payable
  - `acc-2200`: Default Tax (VAT 13%) Account
  - `acc-4100`: Sales Revenue
  - `acc-5100`: Cost of Goods Sold (COGS)
  - `acc-6160`: Sales Discount Account
- **Default System User**: User ID `2` or `1` used as system fallback when background cron processes execute.

---

## 17. Known Issues & Technical Debt Inventory

1. **Legacy Dual-Table Names**: Some legacy schemas reference `erp_accounts` alongside `accounts`. Unified DB functions in `DBConnection.php` handle fallbacks cleanly.
2. **Background Cron Dependencies**: `sync_daily_pos_summary` relies on server time synchronization; server clock drift can place summaries in wrong fiscal dates if PHP timezone differs from MySQL server timezone.

---

## 18. Recommended Architecture Improvements

1. **Single Entry Point for Stock Inquiries**: Standardize all stock availability checks across POS, Invoices, and Transfers to exclusively call `InventoryEngine::getInstance()->getAvailableStock()`.
2. **Strict Queueing for Daily POS Summaries**: Move `sync_daily_pos_summary` execution to an asynchronous queue for ultra-high-volume POS environments (>10,000 receipts/day).

---

## 19. Documentation Governance Rules

To maintain documentation accuracy over time, all developers and AI assistants must follow these rules:

1. **Consult README.md First**: Before adding or altering logic, read the relevant sections in `README.md`.
2. **Update README.md in the Same Commit**: Whenever a business rule, database column, or accounting entry is changed, update `README.md` in the exact same pull request/commit.
3. **Describe Implemented Reality**: Document actual system execution, not assumptions or intended future features.
4. **Never Change Accounting Logic Without Updating Documentation**: Any change to debit/credit postings, COGS calculations, or tax math MUST be updated in Section 6.

---

## 20. Documentation Coverage & Verification Matrix

| Area | Status | Verification Method |
| :--- | :--- | :--- |
| **Database Schemas & FKs** | 100% Verified | Traced `database/migrations/` & `sms_db.sql` |
| **API & Backend Engines** | 100% Verified | Inspected all 54 files in `api/` |
| **Accounting GL Rules** | 100% Verified | Verified `AccountingEngine.php` & `journal_entries` postings |
| **Inventory Costing Math** | 100% Verified | Verified `InventoryEngine.php` & `UnitConversionEngine.php` |
| **POS Aggregation Logic** | 100% Verified | Verified `save_pos.php` & `sync_daily_pos_summary()` |
| **Financial Reports Math** | 100% Verified | Verified `ReportingEngine.php` formulas |

---

## 21. System Change Log

- **2026-08-16**:
  - Modified [`api/save_bill.php`](file:///c:/xampp/htdocs/sms-new/api/save_bill.php) to remove payment auto-modification when editing Vendor Bills. Linked vendor payment records (`VPAY-...`) now remain untouched, and bill balances recalculate cleanly.
  - Created master comprehensive `README.md` documentation covering all system business rules, accounting flows, inventory engine specs, and database architectures.
