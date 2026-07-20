<?php
session_start();
// Session timeout: 1 hour (3600 seconds)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    // Timeout – destroy session and redirect to login page
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit();
}
// Update last activity timestamp
$_SESSION['last_activity'] = time();

require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$error = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_SESSION['user_id'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $db = db();
        $user = $db->fetchOne("SELECT * FROM users WHERE username = :username AND is_active = 1 AND is_deleted = 0", ['username' => $username]);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            // Update last login
            $db->execute("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :id", ['id' => $user['id']]);

            header("Location: index.php");
            exit();
        } else {
            $error = "Invalid username or password";
        }
    } else {
        $error = "Please fill in all fields";
    }
}

$is_logged_in = isset($_SESSION['user_id']);

// Always fetch system branding (needed on both login + dashboard)
if (!isset($db)) $db = db();
try {
    $sys_logo = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'logo'")['meta_value'] ?? '';
    $sys_name = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'name'")['meta_value'] ?? 'SMS ERP';
} catch (Exception $e) {
    $sys_logo = '';
    $sys_name = 'SMS ERP';
}

if ($is_logged_in) {
    // Get basic stats for the dashboard home
    try {
        $total_items = $db->fetchOne("SELECT COUNT(*) as count FROM items")['count'] ?? 0;
        $total_customers = $db->fetchOne("SELECT COUNT(*) as count FROM customers")['count'] ?? 0;
        $total_vendors = $db->fetchOne("SELECT COUNT(*) as count FROM vendors")['count'] ?? 0;
        $recent_transactions = $db->fetchAll("SELECT * FROM transaction_headers ORDER BY created_at DESC LIMIT 5");
    } catch (Exception $ex) {
        $total_items = 0;
        $total_customers = 0;
        $total_vendors = 0;
        $recent_transactions = [];
    }

    $page = $_GET['page'] ?? 'home';
    
    // Audit log for page views (Dashboard and Reports)
    if ($page === 'home' || strpos($page, 'reports/') === 0) {
        try {
            $db = db();
            $log_action = ($page === 'home') ? 'dashboard_view' : 'report_view';
            $db->execute("INSERT INTO audit_logs (table_name, action, record_id, old_values, new_values, user_id) VALUES (?, ?, ?, ?, ?, ?)", [
                'system_navigation',
                $log_action,
                $page,
                null,
                json_encode([
                    'page' => $page,
                    'accessed_at' => date('Y-m-d H:i:s'),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]),
                $_SESSION['user_id']
            ]);
        } catch (Exception $e) {
            // Fail silently
        }
    }
    
    // Check if it's a print page to hide headers/nav
    $is_print_page = (strpos($page, '/print') !== false || strpos($page, 'print/') !== false || $page === 'print');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $is_logged_in ? ucwords(str_replace(['/', '_'], ' ', $page)) . " | NetSuite" : "Login | SMS ERP"; ?>
    </title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        /* Custom styling for DataTables to match NetSuite aesthetics */
        .dataTables_wrapper .dataTables_length select {
            padding: 4px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }

        .dataTables_wrapper .dataTables_filter input {
            padding: 4px 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
            margin-left: 5px;
        }

        .dataTables_wrapper .dataTables_info {
            font-size: 12px;
            color: #666;
            margin-top: 10px;
        }

        .dataTables_wrapper .dataTables_paginate {
            font-size: 12px;
            margin-top: 10px;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 4px 10px !important;
            margin: 0 2px;
            border-radius: 3px !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--ns-primary) !important;
            color: white !important;
            border: 1px solid var(--ns-primary) !important;
        }

        /* Hide sorting arrows */
        table.dataTable thead .sorting:before,
        table.dataTable thead .sorting:after,
        table.dataTable thead .sorting_asc:before,
        table.dataTable thead .sorting_asc:after,
        table.dataTable thead .sorting_desc:before,
        table.dataTable thead .sorting_desc:after {
            display: none !important;
        }

        /* Notification Toast */
        #ns-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            padding: 15px 25px;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(200%);
            transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            font-weight: 600;
            border-left: 5px solid var(--ns-primary);
        }
        #ns-notification.show { transform: translateX(0); }
        #ns-notification.success { border-left-color: #2ecc71; }
        #ns-notification.error { border-left-color: #e74c3c; }

        <?php if ($is_logged_in && !empty($sys_font = ($db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'system_font'")['meta_value'] ?? ''))): ?>
        body, .ns-input, .ns-btn, table {
            font-family: <?php echo $sys_font; ?> !important;
        }
        <?php endif; ?>
    </style>
</head>

<body class="<?php echo $is_logged_in ? '' : 'auth-page'; ?>">
    <script>
        if (localStorage.getItem('ns_theme') === 'dark') {
            document.body.classList.add('dark-theme');
        }
    </script>
    <?php if (!$is_logged_in): ?>
        <div class="auth-container">
            <?php
            $login_logo_abs = !empty($sys_logo) ? (__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sys_logo)) : '';
            ?>
            <div style="text-align:center; margin-bottom:20px;">
                <?php if (!empty($sys_logo) && file_exists(__DIR__ . '/' . $sys_logo)): ?>
                    <div style="margin-bottom:15px; display: flex; justify-content: center; align-items: center;">
                        <img src="<?php echo htmlspecialchars($sys_logo); ?>" alt="Logo" style="max-height: 85px; max-width: 200px; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.35)); border-radius: 4px;">
                    </div>
                <?php else: ?>
                    <div style="font-size:40px; color:rgba(255,255,255,0.8); margin-bottom:10px;"><i class="fas fa-cube"></i></div>
                <?php endif; ?>
                <div style="font-size:20px; font-weight:700; color:#fff; letter-spacing:0.5px;"><?php echo htmlspecialchars($sys_name); ?></div>
            </div>
            <div class="glass-card">
                <div class="auth-header">
                    <h1>Welcome Back</h1>
                    <p>Enter your credentials to access your account</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-input" placeholder="Enter username"
                            required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" class="form-input" placeholder="••••••••"
                                required>
                            <button type="button" id="togglePassword" class="toggle-password">
                                <i class="fas fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">Sign In</button>
                </form>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const togglePassword = document.querySelector('#togglePassword');
                const password = document.querySelector('#password');
                const eyeIcon = document.querySelector('#eyeIcon');

                if (togglePassword) {
                    togglePassword.addEventListener('click', function (e) {
                        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                        password.setAttribute('type', type);
                        eyeIcon.classList.toggle('fa-eye');
                        eyeIcon.classList.toggle('fa-eye-slash');
                    });
                }
            });
        </script>
    <?php else: ?>
        <?php 
        if ($is_print_page) {
            $parts = explode('/', $page);
            $count = count($parts);
            $action = $parts[$count - 1];
            $dir_path = implode('/', array_slice($parts, 0, $count - 1));
            $page_path = "forms/modules/" . $dir_path . "/" . $action . ".php";
            if (file_exists($page_path)) {
                include $page_path;
                exit;
            }
        }
        ?>
        <header class="ns-header">
            <div style="display: flex; align-items: center; gap: 20px;">
                <div class="ns-logo" style="display: flex; align-items: center; gap: 10px;">
                    <?php if (!empty($sys_logo) && file_exists(__DIR__ . '/' . $sys_logo)): ?>
                        <img src="<?php echo htmlspecialchars($sys_logo); ?>" alt="Logo" style="height:28px; max-width: 120px; object-fit: contain; border-radius: 2px; vertical-align: middle;">
                    <?php else: ?>
                        <i class="fas fa-cube" style="font-size:22px;"></i>
                    <?php endif; ?>
                    <span style="font-size:15px; font-weight:700; letter-spacing:0.3px;"><?php echo htmlspecialchars($sys_name); ?></span>
                </div>
                <div
                    style="font-size: 11px; color: rgba(255,255,255,0.5); border-left: 1px solid rgba(255,255,255,0.2); padding-left: 20px; cursor: pointer;">
                    <i class="fas fa-search" style="margin-right: 5px;"></i> Global Search...
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 20px;">
                <div style="text-align: right; margin-right: 15px;">
<?php
    // Determine display name: prefer full_name, fallback to username
    $displayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
    // Determine role display: capitalize and map admin to Administrator
    $rawRole = strtolower($_SESSION['role'] ?? 'user');
    $roleMap = [
        'admin' => 'Administrator',
        'manager' => 'Manager',
        // Add more mappings as needed
    ];
    $displayRole = $roleMap[$rawRole] ?? ucfirst($rawRole);
?>
<div style="font-size: 12px; font-weight: bold; letter-spacing: 0.2px;">
    <?php echo htmlspecialchars($displayName); ?>
</div>
<div style="font-size: 10px; color: var(--ns-accent); opacity: 0.85; text-transform: capitalize; letter-spacing: 0.3px;">
    <i class="fas fa-user-tag" style="margin-right: 3px; font-size: 9px;"></i><?php echo htmlspecialchars($displayRole); ?>
</div>
                </div>
                <button id="ns-theme-toggle" title="Toggle Theme" style="background: none; border: none; color: white; font-size: 15px; cursor: pointer; transition: var(--ns-transition); margin-right: 15px; display: inline-flex; align-items: center; justify-content: center;" onmouseover="this.style.color='var(--ns-accent)'" onmouseout="this.style.color='white'">
                    <i class="fas fa-moon"></i>
                </button>
                <a href="logout.php" style="color: white; font-size: 16px; transition: var(--ns-transition);" title="Logout"
                    onmouseover="this.style.color='var(--ns-accent)'" onmouseout="this.style.color='white'"><i
                        class="fas fa-sign-out-alt"></i></a>
            </div>
        </header>

        <!-- Navigation Bar -->
        <nav class="ns-nav">
            <div class="ns-nav-item">
                <i class="fas fa-tasks" style="margin-right: 8px;"></i> Activities <i class="fas fa-caret-down"
                    style="margin-left: 5px; font-size: 10px; opacity: 0.7;"></i>
                <div class="ns-dropdown">
                    <a href="#" class="ns-dropdown-item"><i class="fas fa-calendar-alt"></i> Calendar</a>
                    <a href="#" class="ns-dropdown-item"><i class="fas fa-check-square"></i> Tasks</a>
                    <a href="#" class="ns-dropdown-item"><i class="fas fa-bullhorn"></i> Events</a>
                </div>
            </div>

            <a href="?page=home" class="ns-nav-item" title="Dashboard" style="padding: 0 15px;"><i
                    class="fas fa-home"></i></a>

            <div class="ns-nav-item">
                <i class="fas fa-exchange-alt" style="margin-right: 8px;"></i> Transactions <i class="fas fa-caret-down"
                    style="margin-left: 5px; font-size: 10px; opacity: 0.7;"></i>
                <div class="ns-dropdown">
                    <div class="ns-dropdown-item">
                        <i class="fas fa-cash-register"></i> POS <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/pos/manage" class="ns-sub-dropdown-item">New POS</a>
                            <a href="?page=transactions/pos" class="ns-sub-dropdown-item">POS List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-file-invoice"></i> Bills <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/bill/manage" class="ns-sub-dropdown-item">New Bill</a>
                            <a href="?page=transactions/bill" class="ns-sub-dropdown-item">Bill List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-file-invoice-dollar"></i> Invoices <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/invoice/manage" class="ns-sub-dropdown-item">New Invoice</a>
                            <a href="?page=transactions/invoice" class="ns-sub-dropdown-item">Invoice List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-money-bill-wave"></i> Payments <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/payment/manage" class="ns-sub-dropdown-item">Record Payment</a>
                            <a href="?page=transactions/payment" class="ns-sub-dropdown-item">Payment List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-wallet"></i> Expenses <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/expense/manage" class="ns-sub-dropdown-item">Enter Expense</a>
                            <a href="?page=transactions/expense" class="ns-sub-dropdown-item">Expense List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-book"></i> Journal <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/journal/manage" class="ns-sub-dropdown-item">New Journal Entry</a>
                            <a href="?page=transactions/journal" class="ns-sub-dropdown-item">Journal List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-coins"></i> Cash Denomination <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/cash_denom/manage" class="ns-sub-dropdown-item">New Entry</a>
                            <a href="?page=transactions/cash_denom" class="ns-sub-dropdown-item">List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-warehouse"></i> Adjustments <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/adjustment/manage" class="ns-sub-dropdown-item">New Adjustment</a>
                            <a href="?page=transactions/adjustment" class="ns-sub-dropdown-item">Adjustment List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-random"></i> Bank Transfer <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/transfer/manage" class="ns-sub-dropdown-item">New Transfer</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ns-nav-item">
                Lists <i class="fas fa-caret-down" style="margin-left: 5px; font-size: 10px; opacity: 0.7;"></i>
                <div class="ns-dropdown">
                    <a href="?page=master/account" class="ns-dropdown-item"><i class="fas fa-list-ul"></i> Accounts</a>
                    <a href="?page=master/customer" class="ns-dropdown-item"><i class="fas fa-users"></i> Customers</a>
                    <a href="?page=master/vendor" class="ns-dropdown-item"><i class="fas fa-user-tie"></i> Vendors</a>
                    <a href="?page=master/item" class="ns-dropdown-item"><i class="fas fa-boxes"></i> Items</a>
                    <a href="?page=system/users" class="ns-dropdown-item"><i class="fas fa-user-friends"></i> Employees</a>
                </div>
            </div>

            <div class="ns-nav-item">
                Reports <i class="fas fa-caret-down" style="margin-left: 5px; font-size: 10px; opacity: 0.7;"></i>
                <div class="ns-dropdown">
                    <div class="ns-dropdown-item">
                        <i class="fas fa-file-invoice-dollar"></i> Financial <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/financial/balance_sheet" class="ns-sub-dropdown-item">Balance Sheet</a>
                            <a href="?page=reports/financial/income_statement" class="ns-sub-dropdown-item">Income Statement</a>
                            <a href="?page=reports/financial/comparative_income" class="ns-sub-dropdown-item">Comparative Income Statement</a>
                            <a href="?page=reports/financial/daily_profit" class="ns-sub-dropdown-item">Daily Profit Report</a>
                            <a href="?page=reports/financial/trial_balance" class="ns-sub-dropdown-item">Trial Balance</a>
                            <a href="?page=reports/financial/general_ledger" class="ns-sub-dropdown-item">General Ledger</a>
                            <a href="?page=reports/financial/equity_statement" class="ns-sub-dropdown-item">Equity Statement</a>
                            <a href="?page=reports/financial/cash_book" class="ns-sub-dropdown-item">Cash Book</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-chart-line"></i> Sales <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/sales/by_item" class="ns-sub-dropdown-item">Sales by Item</a>
                            <a href="?page=reports/sales/top_profit_items" class="ns-sub-dropdown-item">Top Profit Items</a>
                            <a href="?page=reports/sales/by_customer" class="ns-sub-dropdown-item">Sales by Customer</a>
                            <a href="?page=reports/sales/register" class="ns-sub-dropdown-item">Sales Register</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-shopping-cart"></i> Purchases <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/purchases/by_item" class="ns-sub-dropdown-item">Purchase by Item</a>
                            <a href="?page=reports/purchases/by_vendor" class="ns-sub-dropdown-item">Purchase by Vendor</a>
                            <a href="?page=reports/vendors/payable_aging" class="ns-sub-dropdown-item">AP Aging</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-warehouse"></i> Inventory <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/inventory/stock_summary" class="ns-sub-dropdown-item">Stock Summary</a>
                            <a href="?page=reports/inventory/stock_ledger" class="ns-sub-dropdown-item">Stock Ledger</a>
                            <a href="?page=reports/inventory/low_stock" class="ns-sub-dropdown-item">Low Stock Report</a>
                            <a href="?page=reports/inventory/less_stock" class="ns-sub-dropdown-item">Less Stock Report</a>
                            <a href="?page=reports/inventory/urgent_buy" class="ns-sub-dropdown-item">Urgent Purchases</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-percent"></i> VAT/Tax <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/vat/sales_register" class="ns-sub-dropdown-item">VAT Sales Register</a>
                            <a href="?page=reports/vat/purchase_register" class="ns-sub-dropdown-item">VAT Purchase
                                Register</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-users"></i> Customers <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/customers/statement" class="ns-sub-dropdown-item">Customer Statement</a>
                            <a href="?page=reports/customers/receivable_aging" class="ns-sub-dropdown-item">AR Aging</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ns-nav-item">
                Setup <i class="fas fa-caret-down" style="margin-left: 5px; font-size: 10px; opacity: 0.7;"></i>
                <div class="ns-dropdown">
                    <a href="?page=system/company/manage" class="ns-dropdown-item"><i class="fas fa-building"></i> System Information</a>
                    <a href="?page=system/users" class="ns-dropdown-item"><i class="fas fa-user-shield"></i> Users & Roles</a>
                    <a href="?page=system/fiscal_years" class="ns-dropdown-item"><i class="fas fa-calendar-check"></i> Accounting Periods / Closing</a>
                    <a href="?page=system/settings/accounting" class="ns-dropdown-item"><i class="fas fa-calculator"></i> Accounting Lists</a>
                    <a href="?page=system/settings/accounting_preferences" class="ns-dropdown-item"><i class="fas fa-file-contract"></i> Accounting Preferences</a>
                    <a href="?page=master/account/opening_balance" class="ns-dropdown-item"><i class="fas fa-balance-scale"></i> Bank Opening Balances</a>
                    <a href="?page=system/ref_codes/manage" class="ns-dropdown-item"><i class="fas fa-list-ol"></i> Auto Generated Numbers</a>
                    <a href="?page=system/import_export/manage" class="ns-dropdown-item"><i class="fas fa-file-import"></i> Import / Export Data</a>
                    <a href="?page=system/backup/manage" class="ns-dropdown-item"><i class="fas fa-database"></i> Backup & Restore</a>
                </div>
            </div>
        </nav>

        <!-- Main Application Content -->
        <div class="ns-content <?php echo ($page === 'home' || $page === 'print' || $is_print_page) ? 'ns-content-flush' : ''; ?>">
            <?php
            if ($page == 'home' || $page == 'home-v3') {
                include 'home.php';
            } else {
                // Security: Sanitize page parameter to prevent path traversal
                $page = str_replace(['../', '..\\'], '', $page);
                $page = preg_replace('/[^a-zA-Z0-9\/_\-]/', '', $page);
                
                // Extract module parts
                $parts = explode('/', $page);
                $count = count($parts);
                if ($count > 0) {
                    $action = $parts[$count - 1]; // e.g., 'balance_sheet' or 'manage'
                    $dir_path = implode('/', array_slice($parts, 0, $count - 1));

                    if ($action == 'manage') {
                        $module_name = $parts[$count - 2];
                        if ($module_name == 'users') $module_name = 'user';
                        $page_path = "forms/modules/" . $dir_path . "/" . $module_name . "_manage.php";
                    } elseif ($action == 'view' || $action == 'print') {
                        $page_path = "forms/modules/" . $dir_path . "/" . $action . ".php";
                    } else {
                        $module_name = $action;
                        if ($module_name == 'users') $module_name = 'user';

                        // Primary path: forms/modules/{page}/{action}_list.php
                        $page_path = "forms/modules/" . $page . "/" . $module_name . "_list.php";

                        // Fallback 1: forms/modules/{dir}/{action}_list.php
                        if (!file_exists($page_path)) {
                            $page_path = "forms/modules/" . $dir_path . "/" . $module_name . "_list.php";
                        }

                        // Fallback 2: forms/modules/{page}.php
                        if (!file_exists($page_path)) {
                            $page_path = "forms/modules/" . $page . ".php";
                        }
                    }

                    if (file_exists($page_path)) {
                        include $page_path;
                    } else {
                        echo '<div style="padding:40px;text-align:center;color:#888">
                            <i class="fas fa-file-slash" style="font-size:48px;margin-bottom:16px;display:block;opacity:.3"></i>
                            <div style="font-size:18px;font-weight:600;color:#555">Page Not Found</div>
                            <div style="font-size:13px;margin-top:8px">Module: <code>' . htmlspecialchars($page) . '</code></div>
                            <a href="?page=home" class="ns-btn ns-btn-primary" style="margin-top:20px;display:inline-block"><i class="fas fa-home"></i> Back to Dashboard</a>
                        </div>';
                    }
                }
            }
            ?>

        </div>

    <?php endif; ?>

    <!-- Footer or Script includes -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/ns-transactions.js"></script>
    <script>
        <?php if ($is_logged_in): ?>
            // Theme toggle logic
            (function() {
                const toggleBtn = document.getElementById('ns-theme-toggle');
                if (toggleBtn) {
                    const icon = toggleBtn.querySelector('i');
                    // Check saved theme
                    if (localStorage.getItem('ns_theme') === 'dark') {
                        document.body.classList.add('dark-theme');
                        icon.className = 'fas fa-sun';
                    }
                    toggleBtn.addEventListener('click', function() {
                        document.body.classList.toggle('dark-theme');
                        const isDark = document.body.classList.contains('dark-theme');
                        localStorage.setItem('ns_theme', isDark ? 'dark' : 'light');
                        icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                        
                        // Update active chart colors dynamically
                        if (typeof Chart !== 'undefined' && Chart.instances) {
                            setTimeout(() => {
                                Object.values(Chart.instances).forEach(instance => {
                                    if (instance.options && instance.options.scales) {
                                        const xTicks = instance.options.scales.x ? instance.options.scales.x.ticks : null;
                                        const yTicks = instance.options.scales.y ? instance.options.scales.y.ticks : null;
                                        const yGrid = instance.options.scales.y ? instance.options.scales.y.grid : null;
                                        
                                        if (xTicks) xTicks.color = isDark ? '#94a3b8' : '#64748b';
                                        if (yTicks) yTicks.color = isDark ? '#94a3b8' : '#64748b';
                                        if (yGrid) yGrid.color = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
                                        instance.update();
                                    }
                                });
                            }, 50);
                        }
                    });
                }
            })();

            // Global UX: Clear zero on focus
            document.addEventListener('focus', function (e) {
                if (e.target.tagName === 'INPUT' && (e.target.type === 'number')) {
                    if (parseFloat(e.target.value) === 0) { e.target.value = ''; }
                }
            }, true);

            // NetSuite Grid Logic
            function nsAddLine(tableId) {
                const table = document.getElementById(tableId).getElementsByTagName('tbody')[0];
                const template = table.rows[0];
                const newRow = table.insertRow(table.rows.length);
                newRow.innerHTML = template.innerHTML;
                // Clear inputs, selects, and textareas
                newRow.querySelectorAll('input').forEach(input => {
                    if (input.type === 'checkbox' || input.type === 'radio') input.checked = false;
                    else if (input.type === 'number') input.value = '0.00';
                    else input.value = '';
                });
                newRow.querySelectorAll('select').forEach(select => {
                    select.value = '';
                });
                newRow.querySelectorAll('textarea').forEach(textarea => {
                    textarea.value = '';
                });
                reNumberRows(table);
                updateTotals();
            }

            function nsRemoveLine(btn) {
                const row = btn.closest('tr');
                const table = row.closest('tbody');
                if (table.rows.length > 1) {
                    row.remove();
                    reNumberRows(table);
                    updateTotals();
                } else {
                    alert("At least one line is required.");
                }
            }

            function nsInsertLine(btn) {
                const row = btn.closest('tr');
                const table = row.closest('tbody');
                const newRow = table.insertRow(row.sectionRowIndex);
                const template = table.rows[0];
                newRow.innerHTML = template.innerHTML;
                newRow.querySelectorAll('input').forEach(input => {
                    if (input.type === 'checkbox' || input.type === 'radio') input.checked = false;
                    else if (input.type === 'number') input.value = '0.00';
                    else input.value = '';
                });
                newRow.querySelectorAll('select').forEach(select => {
                    select.value = '';
                });
                newRow.querySelectorAll('textarea').forEach(textarea => {
                    textarea.value = '';
                });
                reNumberRows(table);
                updateTotals();
            }

            function nsClearLines(tableId) {
                if (confirm("Are you sure you want to clear all lines?")) {
                    const table = document.getElementById(tableId).getElementsByTagName('tbody')[0];
                    while (table.rows.length > 1) { table.deleteRow(1); }
                    const firstRow = table.rows[0];
                    firstRow.querySelectorAll('input').forEach(i => i.value = (i.type === 'number' ? '0.00' : ''));
                    updateTotals();
                }
            }

            function reNumberRows(table) {
                Array.from(table.rows).forEach((r, i) => {
                    if (r.cells[0]) r.cells[0].innerText = i + 1;
                });
            }

            function updateTotals() {
                if (typeof calculateInvoiceTotals === 'function') calculateInvoiceTotals();
                if (typeof calculateBillTotals === 'function') calculateBillTotals();
            }

            // Global Line Calculation (Qty * Rate)
            function calculateLine(el) {
                const row = el.closest('tr');
                const qty = parseFloat(row.querySelector('.qty-input')?.value) || 0;
                const rate = parseFloat(row.querySelector('.rate-input')?.value) || 0;
                const amountInput = row.querySelector('.amount-input');
                if (amountInput) {
                    amountInput.value = (qty * rate).toFixed(2);
                }
                updateTotals();
            }

            // Initialize DataTables for all list tables
            $(document).ready(function () {
                $('.ns-table').DataTable({
                    "pageLength": 25,
                    "order": [], // Maintain server-side sorting (latest created on top)
                    "language": {
                        "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                        "infoEmpty": "Showing 0 to 0 of 0 entries",
                        "lengthMenu": "Show _MENU_ entries",
                        "search": "Quick Search:"
                    },
                    "initComplete": function(settings, json) {
                        if ($('#inactive-filter-container').length) {
                            $('#inactive-filter-container').appendTo('.dataTables_length');
                            $('#inactive-filter-container').show();
                        }
                    }
                });
            });
        <?php endif; ?>

        function nsNotify(message, type = 'success') {
            const toast = document.getElementById('ns-notification');
            const icon = toast.querySelector('i');
            const text = toast.querySelector('span');
            
            toast.className = 'show ' + type;
            icon.className = 'fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle');
            icon.style.color = type === 'success' ? '#2ecc71' : '#e74c3c';
            text.innerText = message;
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        function nsDelete(table, id, callback) {
            if (!confirm('Are you sure you want to delete this record?')) return;
            
            const payload = {
                action: 'delete',
                table: table,
                primary_key: 'id',
                primary_value: id
            };

            fetch('api/transaction_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    nsNotify(data.message);
                    if (callback) callback();
                    else location.reload();
                } else {
                    nsNotify(data.message || 'Delete failed', 'error');
                }
            })
            .catch(err => nsNotify('Network error', 'error'));
        }
    </script>
    
    <div id="ns-notification">
        <i></i>
        <span></span>
    </div>


    <script>
        function nsConfirm(message, onOk, onCancel) {
            const modal = document.getElementById('ns-modal');
            const msgEl = document.getElementById('modal-message');
            const okBtn = document.getElementById('modal-ok-btn');
            const cancelBtn = document.getElementById('modal-cancel-btn');

            msgEl.innerText = message;
            modal.style.display = 'flex';

            const cleanup = () => {
                modal.style.display = 'none';
                okBtn.onclick = null;
                cancelBtn.onclick = null;
            };

            okBtn.onclick = () => {
                cleanup();
                if (onOk) onOk();
            };

            cancelBtn.onclick = () => {
                cleanup();
                if (onCancel) onCancel();
            };

            // Close on click outside
            modal.onclick = function(e) {
                if (e.target === modal) cleanup();
            };
        }
    </script>
    <!-- Global Confirmation Modal -->
    <div id="ns-modal" style="display: none; position: fixed; z-index: 10001; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center;">
        <div style="background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); width: 400px; max-width: 90%; text-align: center; font-family: inherit;">
            <div style="font-size: 40px; color: #f39c12; margin-bottom: 15px;"><i class="fas fa-exclamation-triangle"></i></div>
            <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--ns-primary);">Confirmation Required</h3>
            <p id="modal-message" style="margin-bottom: 25px; color: #555; line-height: 1.5; font-size: 15px;"></p>
            <div style="display: flex; justify-content: center; gap: 15px;">
                <button id="modal-cancel-btn" class="ns-btn" style="padding: 8px 20px;">Cancel</button>
                <button id="modal-ok-btn" class="ns-btn ns-btn-primary" style="padding: 8px 20px;">Confirm</button>
            </div>
        </div>
    </div>
</body>

</html>