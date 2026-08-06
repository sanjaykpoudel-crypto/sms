# Store Management System (SMS) — Complete Layman & User Guide

Welcome to the **Store Management System (SMS)**! This is a complete software built for retail stores, liquor shops, and businesses. It manages daily counter sales, inventory stock, customer and vendor balances, financial accounting, employee permissions, tasks, and business performance reports.

---

## 🛠️ 1. Quick Setup & Installation Guide

Follow these steps to run the software on your computer:

### Step 1: Put Project Files in XAMPP
Copy the project folder `sms-new` into your XAMPP web server directory:
```text
C:\xampp\htdocs\sms-new
```

### Step 2: Start XAMPP Server
1. Open **XAMPP Control Panel**.
2. Click **Start** for **Apache**.
3. Click **Start** for **MySQL**.

### Step 3: Load Database
1. Open your browser and go to `http://localhost/phpmyadmin`
2. Click **New** on the left menu and create a database named `sms_db`.
3. Select `sms_db`, click the **Import** tab at the top, choose the database file:
   ```text
   c:\xampp\htdocs\sms-new\database\sms_db.sql
   ```
4. Click **Import**.

### Step 4: Open System
Go to `http://localhost/sms-new/` in your browser.

---

## 🗄️ 2. Database & Login Credentials

- **File Location**: `database/DBConnection.php`
- **Database Host**: `localhost`
- **Database Name**: `sms_db`
- **Database Username**: `root`
- **Database Password**: *(Leave blank)*

### Default Login
- **Username**: `admin`
- **Password**: *(As configured in your user profile)*

---

## 💡 3. Simple Feature-by-Feature Guide & System Logic

Here is an easy explanation of every section of the system:

---

### 🖥️ A. Dashboard (`home.php`)
The Dashboard is your main control room when you log in.
- **Top Summary Cards**: Shows total Sales Today, Monthly Sales, Total Outstanding Customer Receivables (money owed to you), and Total Vendor Payables (money you owe).
- **Quick Action Buttons**: Fast 1-click shortcuts to open POS Counter Sale, New Customer Invoice, New Vendor Bill, or New Activity.
- **Daily Performance Charts**: Visual graphs showing daily sales trends and top-selling products.

---

### 🛒 B. POS (Point of Sale Counter Checkout)
Used for fast daily sales at the shop counter.
- **How it works**:
  1. Barcode scanning or quick-select items into the cart.
  2. Select payment method: Cash, Bank, QR/Esewa, or Customer Credit.
  3. Clicking **Complete Sale** automatically:
     - Deducts the item stock from inventory.
     - Adds money to Cash/Bank ledger.
     - Calculates 13% VAT and saves invoice record.
     - Prints a thermal receipt for the customer.

---

### 📄 C. Invoices & Sales
Used for creating detailed bills or wholesale customer invoices.
- **Invoice Register**: Lists all past sales invoices with filters by customer, payment status (Paid, Partial, Unpaid), and date.
- **Credit Memos & Returns**: When a customer returns goods, a Credit Memo is created to reduce customer debt and restore stock back to inventory.

---

### 🛍️ D. Purchases & Vendor Bills
Used when you buy new goods from suppliers (vendors) to restock your shop.
- **Vendor Bill Entry**: You enter bill details from the supplier.
  - Automatically increases product stock quantities.
  - Records supplier balance under **Accounts Payable**.
- **Vendor Credit / Debit Memos**: Used for product returns or purchase price adjustments with suppliers.

---

### 📦 E. Item & Inventory Management
Manages product items, stock quantities, and prices.
- **Item Master**: Set item name, SKU code, category, unit, cost price, selling price, and minimum low-stock warning limit.
- **Stock Adjustments**: Correct stock levels if items are broken, damaged, or expired.
- **Stock Transfers**: Move stock between different store locations or warehouses.

---

### 📒 F. Chart of Accounts & Accounting Lists
The backbone of double-entry accounting.
- **Chart of Accounts**: Master list of all money categories:
  - **Assets (`1000s`)**: Cash, Banks, Inventory, Receivables, Property.
  - **Liabilities (`2000s`)**: Vendor Payables, Loans Taken (Sahakari, Mamu, etc.).
  - **Equity (`3000s`)**: Owner Capital Invested.
  - **Revenue (`4000s`)**: Sales Income.
  - **Expenses (`5000s - 6000s`)**: Product Purchase Costs (COGS), Rent, Salaries, Electricity.
- **Opening Balances**: Set starting balances for bank accounts and cash when migrating into SMS.

---

### 📅 G. Activities & Task Management
Helps manage daily tasks, follow-ups, and meetings.
- **Activity List**: Create tasks (e.g. "Call supplier for stock delivery", "Collect payment from customer").
- **Calendar View**: Visual calendar displaying scheduled tasks, events, and meetings by date.

---

### 🏬 H. System Information & Settings
Company configuration and system preferences.
- **System Information**: Store name, VAT/PAN registration number, address, phone numbers, and invoice header logo.
- **Auto Generated Numbers (`ref_codes`)**: Set automatic prefix codes for transactions (e.g. `INV-2026-`, `POS-`, `BILL-`, `JV-`).
- **WhatsApp Integration**: Allows sending instant WhatsApp invoice download links directly to customer phone numbers.
- **Accounting Periods / Fiscal Year Closing**: Manage financial year dates (e.g. FY 2082/83) and run fiscal closing operations.
- **Import / Export Data**: Bulk import items or customer records from Excel/CSV files, or export reports to Excel.
- **Backup & Restore**: Create 1-click database backups or restore previous backup files.

---

## 📊 4. How Investment Payback & Break-Even Calculation Works

This report (`forms/modules/reports/financial/break_even_payback_list.php`) answers: **"How much total money did I invest to start the business, and how much has been recovered so far?"**

### 1. Total Investment Target (Money Put In)
$$\text{Total Investment} = \text{Loans Borrowed} + \text{Owner's Capital Invested}$$

- **Loans Borrowed** (`acc-25xxx`): Sum of loans taken (e.g. Sahakari, Mamu, Sharmila loans).
- **Owner's Capital** (`acc-3100`): Personal capital invested into the shop.

$$\text{Total Capital Recovered} = (\text{Gross Profit} - \text{Bank Fees}) + \text{Bank/Cash Balance} + \text{Net Working Capital}$$

Where:
- **Gross Profit** = Total Sales Revenue − Product Purchase Cost (COGS).
- **Bank Fees & Charges** = Bank service charges, transaction fees, and interest expenses (`acc-6150`).
- **Bank/Cash Balance** = Cash on hand + Prabhu Bank + Esewa balances.
- **Net Working Capital** = Customer Receivables (money coming in) − Supplier Payables (money going out).

### 3. Remaining Target to Break-Even
$$\text{Unrecovered Investment} = \text{Total Investment} - \text{Total Capital Recovered}$$

- When this number reaches **Rs 0.00**, all startup loans and capital are 100% paid back!

### 4. Payback Progress Percentage
$$\text{Payback Progress \%} = \left(\frac{\text{Total Capital Recovered}}{\text{Total Investment}}\right) \times 100\%$$

---

## 🔌 5. Concept of the API (How the Backend Works)

### Restaurant Analogy
Think of the **API (`/api/` folder)** like a **waiter in a restaurant**:
- **You (Screen UI)** = Customer ordering food.
- **Database (`sms_db`)** = Kitchen storing ingredients.
- **API Scripts** = The waiter carrying requests, running validation math, updating stock/ledgers in the kitchen, and returning results back to your screen.

---

## 🔐 6. Role-Based Access Control (RBAC Security)

Control what each employee can see or do:
- **Full Access (Allow)**: View, add, edit, and delete entries.
- **Read Only**: View reports and records, but cannot edit or delete.
- **Hide**: Hides the menu button completely and blocks direct link access.

---

## 📁 7. File Directory Quick Reference

- `index.php`: Top navbar and layout shell.
- `home.php`: Dashboard screen.
- `database/sms_db.sql`: Complete consolidated database dump.
- `database/DBConnection.php`: Database connection settings.
- `api/`: Backend API calculation & database helper files.
- `forms/modules/`: Screen forms (Transactions, Master Lists, Reports, Settings, Activities).
