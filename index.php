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

// Auto-sync POS transactions: run at most once per 5 minutes per session to avoid
// blocking every page load with an expensive full inventory recalculation.
$_idx_last_sync = (int)($_SESSION['_pos_sync_ts'] ?? 0);
if (isset($_SESSION['user_id']) && (time() - $_idx_last_sync) > 300 && function_exists('auto_sync_pos_items_and_invoices')) {
    auto_sync_pos_items_and_invoices(true);
    $_SESSION['_pos_sync_ts'] = time();
}

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
            $_SESSION['location_id'] = !empty($user['location_id']) ? $user['location_id'] : get_user_default_location_id();

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
if ($is_logged_in && empty($_SESSION['location_id'])) {
    $_SESSION['location_id'] = get_user_default_location_id();
}

// Always fetch system branding (needed on both login + dashboard)
if (!isset($db))
    $db = db();
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
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(200%);
            transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            font-weight: 600;
            border-left: 5px solid var(--ns-primary);
        }

        #ns-notification.show {
            transform: translateX(0);
        }

        #ns-notification.success {
            border-left-color: #2ecc71;
        }

        #ns-notification.error {
            border-left-color: #e74c3c;
        }

        <?php if ($is_logged_in && !empty($sys_font = ($db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = 'system_font'")['meta_value'] ?? ''))): ?>
            body,
            .ns-input,
            .ns-btn,
            table {
                font-family:
                    <?php echo $sys_font; ?>
                    !important;
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
                        <img src="<?php echo htmlspecialchars($sys_logo); ?>" alt="Logo"
                            style="max-height: 85px; max-width: 200px; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.35)); border-radius: 4px;">
                    </div>
                <?php else: ?>
                    <div style="font-size:40px; color:rgba(255,255,255,0.8); margin-bottom:10px;"><i class="fas fa-cube"></i>
                    </div>
                <?php endif; ?>
                <div style="font-size:20px; font-weight:700; color:#fff; letter-spacing:0.5px;">
                    <?php echo htmlspecialchars($sys_name); ?></div>
            </div>
            <div class="glass-card">
                <!-- Login View -->
                <div id="login-view">
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
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label for="password" style="margin-bottom: 0;">Password</label>
                                <a href="javascript:void(0)" id="showForgotPassword" class="forgot-password-link">Forgot Password?</a>
                            </div>
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

                <!-- Forgot / Reset Password View -->
                <div id="forgot-view" style="display: none;">
                    <div class="auth-header">
                        <h1 id="forgot-title">Reset Password</h1>
                        <p id="forgot-subtitle">Recover access to your account</p>
                    </div>

                    <div id="forgot-alert" class="alert" style="display: none;"></div>

                    <!-- Step 1: Request Verification Code -->
                    <div id="forgot-step-1">
                        <form id="formRequestReset" onsubmit="return false;">
                            <div class="form-group">
                                <label for="reset_identifier">Username or Email</label>
                                <input type="text" id="reset_identifier" name="identifier" class="form-input" placeholder="Enter your username or email" required>
                            </div>
                            <button type="button" id="btnSendResetCode" class="btn-primary">
                                <i class="fas fa-paper-plane" style="margin-right: 6px;"></i> Send Verification Code
                            </button>
                        </form>
                        <div style="text-align: center; margin-top: 15px;">
                            <a href="javascript:void(0)" class="auth-back-link showLoginBtn">
                                <i class="fas fa-arrow-left" style="margin-right: 5px;"></i> Back to Sign In
                            </a>
                        </div>
                    </div>

                    <!-- Step 2: Verification & New Password -->
                    <div id="forgot-step-2" style="display: none;">
                        <form id="formResetPassword" onsubmit="return false;">
                            <input type="hidden" id="reset_token" name="token" value="">
                            
                            <div id="demo-otp-container" style="display: none;">
                                <div style="font-size: 12px; opacity: 0.8; text-align: center;">Verification Code (Local Setup):</div>
                                <div id="demo-otp-code" class="otp-code-box">------</div>
                            </div>

                            <div class="form-group">
                                <label for="otp_code">6-Digit Verification Code</label>
                                <input type="text" id="otp_code" name="otp_code" class="form-input" placeholder="123456" maxlength="6" style="letter-spacing: 2px; text-align: center; font-weight: 600;">
                            </div>

                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="new_password" name="new_password" class="form-input" placeholder="At least 6 characters" required>
                                    <button type="button" id="toggleNewPassword" class="toggle-password">
                                        <i class="fas fa-eye" id="eyeIconNew"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" placeholder="Re-enter new password" required>
                                    <button type="button" id="toggleConfirmPassword" class="toggle-password">
                                        <i class="fas fa-eye" id="eyeIconConfirm"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="button" id="btnSubmitNewPassword" class="btn-primary">
                                <i class="fas fa-key" style="margin-right: 6px;"></i> Reset Password
                            </button>
                        </form>
                        <div style="text-align: center; margin-top: 15px;">
                            <a href="javascript:void(0)" class="auth-back-link showLoginBtn">
                                <i class="fas fa-arrow-left" style="margin-right: 5px;"></i> Back to Sign In
                            </a>
                        </div>
                    </div>

                    <!-- Step 3: Reset Success -->
                    <div id="forgot-step-3" style="display: none; text-align: center;">
                        <div style="font-size: 48px; color: #2ecc71; margin-bottom: 15px;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <p style="font-size: 14px; margin-bottom: 20px; opacity: 0.9;">Your password has been reset successfully! You can now log in with your new credentials.</p>
                        <button type="button" class="btn-primary showLoginBtn">
                            <i class="fas fa-sign-in-alt" style="margin-right: 6px;"></i> Proceed to Sign In
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Password visibility toggles
                function setupPasswordToggle(btnId, inputId, iconId) {
                    const toggleBtn = document.querySelector('#' + btnId);
                    const pwdInput = document.querySelector('#' + inputId);
                    const iconEl = document.querySelector('#' + iconId);

                    if (toggleBtn && pwdInput && iconEl) {
                        toggleBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            const type = pwdInput.getAttribute('type') === 'password' ? 'text' : 'password';
                            pwdInput.setAttribute('type', type);
                            iconEl.classList.toggle('fa-eye');
                            iconEl.classList.toggle('fa-eye-slash');
                        });
                    }
                }

                setupPasswordToggle('togglePassword', 'password', 'eyeIcon');
                setupPasswordToggle('toggleNewPassword', 'new_password', 'eyeIconNew');
                setupPasswordToggle('toggleConfirmPassword', 'confirm_password', 'eyeIconConfirm');

                // View switching elements
                const loginView = document.getElementById('login-view');
                const forgotView = document.getElementById('forgot-view');
                const showForgotBtn = document.getElementById('showForgotPassword');
                const showLoginBtns = document.querySelectorAll('.showLoginBtn');

                const forgotStep1 = document.getElementById('forgot-step-1');
                const forgotStep2 = document.getElementById('forgot-step-2');
                const forgotStep3 = document.getElementById('forgot-step-3');
                const forgotAlert = document.getElementById('forgot-alert');
                const forgotTitle = document.getElementById('forgot-title');
                const forgotSubtitle = document.getElementById('forgot-subtitle');

                function showAlert(msg, type = 'danger') {
                    forgotAlert.className = 'alert alert-' + type;
                    forgotAlert.innerHTML = msg;
                    forgotAlert.style.display = 'block';
                }

                function hideAlert() {
                    forgotAlert.style.display = 'none';
                    forgotAlert.innerHTML = '';
                }

                function switchToLogin() {
                    forgotView.style.display = 'none';
                    loginView.style.display = 'block';
                    hideAlert();
                }

                function switchToForgot() {
                    loginView.style.display = 'none';
                    forgotView.style.display = 'block';
                    forgotStep1.style.display = 'block';
                    forgotStep2.style.display = 'none';
                    forgotStep3.style.display = 'none';
                    forgotTitle.textContent = 'Reset Password';
                    forgotSubtitle.textContent = 'Recover access to your account';
                    hideAlert();
                }

                if (showForgotBtn) {
                    showForgotBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        switchToForgot();
                    });
                }

                showLoginBtns.forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        switchToLogin();
                    });
                });

                // Auto-detect reset_token in URL query param
                const urlParams = new URLSearchParams(window.location.search);
                const tokenParam = urlParams.get('reset_token');
                if (tokenParam) {
                    switchToForgot();
                    document.getElementById('reset_token').value = tokenParam;
                    forgotStep1.style.display = 'none';
                    forgotStep2.style.display = 'block';
                    forgotTitle.textContent = 'Set New Password';
                    forgotSubtitle.textContent = 'Enter your new password below';
                    showAlert('Please choose a new password for your account.', 'info');
                }

                // Step 1: Request Reset Code
                const btnSendResetCode = document.getElementById('btnSendResetCode');
                const resetIdentifier = document.getElementById('reset_identifier');

                if (btnSendResetCode) {
                    btnSendResetCode.addEventListener('click', function() {
                        const identifier = resetIdentifier.value.trim();
                        if (!identifier) {
                            showAlert('Please enter your username or email address.', 'danger');
                            return;
                        }

                        btnSendResetCode.disabled = true;
                        btnSendResetCode.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                        hideAlert();

                        const formData = new FormData();
                        formData.append('action', 'request_reset');
                        formData.append('identifier', identifier);

                        fetch('api/forgot_password.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            btnSendResetCode.disabled = false;
                            btnSendResetCode.innerHTML = '<i class="fas fa-paper-plane" style="margin-right: 6px;"></i> Send Verification Code';

                            if (data.status === 'success') {
                                document.getElementById('reset_token').value = data.token || '';
                                if (data.otp_code) {
                                    document.getElementById('demo-otp-code').textContent = data.otp_code;
                                    document.getElementById('demo-otp-container').style.display = 'block';
                                    document.getElementById('otp_code').value = data.otp_code;
                                }

                                forgotStep1.style.display = 'none';
                                forgotStep2.style.display = 'block';
                                forgotTitle.textContent = 'Enter Code & New Password';
                                forgotSubtitle.textContent = 'Verification code sent for ' + (data.username || identifier);
                                showAlert(data.message, 'success');
                            } else {
                                showAlert(data.message || 'An error occurred.', 'danger');
                            }
                        })
                        .catch(err => {
                            btnSendResetCode.disabled = false;
                            btnSendResetCode.innerHTML = '<i class="fas fa-paper-plane" style="margin-right: 6px;"></i> Send Verification Code';
                            showAlert('Server connection error. Please try again.', 'danger');
                        });
                    });
                }

                // Step 2: Submit New Password
                const btnSubmitNewPassword = document.getElementById('btnSubmitNewPassword');

                if (btnSubmitNewPassword) {
                    btnSubmitNewPassword.addEventListener('click', function() {
                        const token = document.getElementById('reset_token').value;
                        const otpCode = document.getElementById('otp_code').value.trim();
                        const newPassword = document.getElementById('new_password').value;
                        const confirmPassword = document.getElementById('confirm_password').value;

                        if (!otpCode && !token) {
                            showAlert('Please enter the 6-digit verification code.', 'danger');
                            return;
                        }

                        if (!newPassword) {
                            showAlert('Please enter a new password.', 'danger');
                            return;
                        }

                        if (newPassword.length < 6) {
                            showAlert('Password must be at least 6 characters long.', 'danger');
                            return;
                        }

                        if (newPassword !== confirmPassword) {
                            showAlert('Passwords do not match.', 'danger');
                            return;
                        }

                        btnSubmitNewPassword.disabled = true;
                        btnSubmitNewPassword.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
                        hideAlert();

                        // First verify OTP if needed
                        const formVerify = new FormData();
                        formVerify.append('action', 'verify_otp');
                        formVerify.append('token', token);
                        formVerify.append('otp_code', otpCode);

                        fetch('api/forgot_password.php', {
                            method: 'POST',
                            body: formVerify
                        })
                        .then(res => res.json())
                        .then(vData => {
                            if (vData.status === 'success') {
                                const validToken = vData.token || token;
                                const formReset = new FormData();
                                formReset.append('action', 'reset_password');
                                formReset.append('token', validToken);
                                formReset.append('new_password', newPassword);
                                formReset.append('confirm_password', confirmPassword);

                                return fetch('api/forgot_password.php', {
                                    method: 'POST',
                                    body: formReset
                                }).then(res => res.json());
                            } else {
                                throw new Error(vData.message || 'Invalid verification code');
                            }
                        })
                        .then(rData => {
                            btnSubmitNewPassword.disabled = false;
                            btnSubmitNewPassword.innerHTML = '<i class="fas fa-key" style="margin-right: 6px;"></i> Reset Password';

                            if (rData && rData.status === 'success') {
                                forgotStep2.style.display = 'none';
                                forgotStep3.style.display = 'block';
                                forgotTitle.textContent = 'Password Reset Complete';
                                forgotSubtitle.textContent = 'Your password was successfully updated';
                                if (rData.username) {
                                    document.getElementById('username').value = rData.username;
                                }
                                hideAlert();
                            } else if (rData) {
                                showAlert(rData.message || 'Failed to reset password.', 'danger');
                            }
                        })
                        .catch(err => {
                            btnSubmitNewPassword.disabled = false;
                            btnSubmitNewPassword.innerHTML = '<i class="fas fa-key" style="margin-right: 6px;"></i> Reset Password';
                            showAlert(err.message || 'An error occurred during password reset.', 'danger');
                        });
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
                        <img src="<?php echo htmlspecialchars($sys_logo); ?>" alt="Logo"
                            style="height:28px; max-width: 120px; object-fit: contain; border-radius: 2px; vertical-align: middle;">
                    <?php else: ?>
                        <i class="fas fa-cube" style="font-size:22px;"></i>
                    <?php endif; ?>
                    <span
                        style="font-size:15px; font-weight:700; letter-spacing:0.3px;"><?php echo htmlspecialchars($sys_name); ?></span>
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
                    <div
                        style="font-size: 10px; color: var(--ns-accent); opacity: 0.85; text-transform: capitalize; letter-spacing: 0.3px;">
                        <i class="fas fa-user-tag"
                            style="margin-right: 3px; font-size: 9px;"></i><?php echo htmlspecialchars($displayRole); ?>
                    </div>
                </div>
                <button id="ns-theme-toggle" title="Toggle Theme"
                    style="background: none; border: none; color: white; font-size: 15px; cursor: pointer; transition: var(--ns-transition); margin-right: 15px; display: inline-flex; align-items: center; justify-content: center;"
                    onmouseover="this.style.color='var(--ns-accent)'" onmouseout="this.style.color='white'">
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
                    <a href="?page=activity/calendar" class="ns-dropdown-item"><i class="fas fa-calendar-alt"></i>
                        Calendar</a>
                    <a href="?page=activity&type=task" class="ns-dropdown-item"><i class="fas fa-check-square"></i>
                        Tasks</a>
                    <a href="?page=activity&type=event" class="ns-dropdown-item"><i class="fas fa-bullhorn"></i> Events</a>
                    <a href="?page=activity&type=meeting" class="ns-dropdown-item"><i class="fas fa-users"></i> Meetings</a>
                    <a href="?page=activity&type=phone_call" class="ns-dropdown-item"><i class="fas fa-phone-alt"></i> Phone
                        Calls</a>
                    <a href="?page=activity" class="ns-dropdown-item"><i class="fas fa-list"></i> All Activities</a>
                </div>
            </div>

            <a href="?page=home" class="ns-nav-item" title="Dashboard" style="padding: 0 15px;"><i
                    class="fas fa-home"></i></a>

            <div class="ns-nav-item">
                <i class="fas fa-exchange-alt" style="margin-right: 8px;"></i> <a
                    href="?page=transactions/transactions_list"
                    style="color:inherit; text-decoration:none;">Transactions</a> <i class="fas fa-caret-down"
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
                        <i class="fas fa-undo-alt"></i> Credit Memo <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/credit_memo/manage" class="ns-sub-dropdown-item">New Credit Memo</a>
                            <a href="?page=transactions/credit_memo" class="ns-sub-dropdown-item">Credit Memo List</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-file-invoice-dollar" style="color: #e11d48;"></i> Vendor Credits <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/bill_credit/manage" class="ns-sub-dropdown-item">New Vendor Credit</a>
                            <a href="?page=transactions/bill_credit" class="ns-sub-dropdown-item">Vendor Credit List</a>
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
                    <div class="ns-dropdown-item">
                        <i class="fas fa-boxes-packing"></i> Inventory Transfer <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=transactions/inventory_transfer/manage" class="ns-sub-dropdown-item">New Transfer</a>
                            <a href="?page=transactions/inventory_transfer" class="ns-sub-dropdown-item">Transfer List</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ns-nav-item">
                <a href="?page=master/master_list" style="color:inherit; text-decoration:none;">Lists</a> <i
                    class="fas fa-caret-down" style="margin-left: 5px; font-size: 10px; opacity: 0.7;"></i>
                <div class="ns-dropdown">
                    <a href="?page=master/account" class="ns-dropdown-item"><i class="fas fa-list-ul"></i> Accounts</a>
                    <a href="?page=master/customer" class="ns-dropdown-item"><i class="fas fa-users"></i> Customers</a>
                    <a href="?page=master/vendor" class="ns-dropdown-item"><i class="fas fa-user-tie"></i> Vendors</a>
                    <a href="?page=master/item" class="ns-dropdown-item"><i class="fas fa-boxes"></i> Items</a>
                    <a href="?page=system/users" class="ns-dropdown-item"><i class="fas fa-user-friends"></i> Employees</a>
                </div>
            </div>

            <div class="ns-nav-item"
                onclick="if(event.target.tagName !== 'A' && !event.target.closest('.ns-dropdown')) window.location='?page=reports/reports_list';">
                <a href="?page=reports/reports_list" style="color:inherit; text-decoration:none;">Reports</a> <i
                    class="fas fa-caret-down" style="margin-left: 5px; font-size: 10px; opacity: 0.7;"></i>
                <div class="ns-dropdown">
                    <div class="ns-dropdown-item">
                        <i class="fas fa-file-invoice-dollar"></i> Financial <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/financial/balance_sheet" class="ns-sub-dropdown-item">Balance Sheet</a>
                            <a href="?page=reports/financial/comparative_balance_sheet"
                                class="ns-sub-dropdown-item">Comparative Balance Sheet</a>
                            <a href="?page=reports/financial/income_statement" class="ns-sub-dropdown-item">Income
                                Statement</a>
                            <a href="?page=reports/financial/comparative_income" class="ns-sub-dropdown-item">Comparative
                                Income Statement</a>
                            <a href="?page=reports/financial/daily_profit" class="ns-sub-dropdown-item">Daily Profit
                                Report</a>
                            <a href="?page=reports/financial/trial_balance" class="ns-sub-dropdown-item">Trial Balance</a>
                            <a href="?page=reports/financial/general_ledger" class="ns-sub-dropdown-item">General Ledger</a>
                            <a href="?page=reports/financial/equity_statement" class="ns-sub-dropdown-item">Equity
                                Statement</a>
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
                            <a href="?page=reports/sales/open_invoices" class="ns-sub-dropdown-item">Open Invoices</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-shopping-cart"></i> Purchases <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/purchases/by_item" class="ns-sub-dropdown-item">Purchase by Item</a>
                            <a href="?page=reports/purchases/by_vendor" class="ns-sub-dropdown-item">Purchase by Vendor</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-user-tie"></i> Vendors <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/vendors/balance_confirmation" class="ns-sub-dropdown-item">Vendor Balance
                                Confirmation</a>
                            <a href="?page=reports/vendors/ap_register" class="ns-sub-dropdown-item">AP Register</a>
                            <a href="?page=reports/vendors/ap_payment_by_bill" class="ns-sub-dropdown-item">AP Payment by
                                Bill</a>
                            <a href="?page=reports/vendors/open_bills" class="ns-sub-dropdown-item">Open Bills</a>
                            <a href="?page=reports/vendors/payable_aging" class="ns-sub-dropdown-item">AP Aging</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item">
                        <i class="fas fa-warehouse"></i> Inventory <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/inventory/inventory_valuation" class="ns-sub-dropdown-item">Inventory
                                Valuation</a>
                            <a href="?page=reports/inventory/stock_summary" class="ns-sub-dropdown-item">Current Inventory
                                Snapshot</a>
                            <a href="?page=reports/inventory/stock_ledger" class="ns-sub-dropdown-item">Stock Ledger</a>
                            <a href="?page=reports/inventory/inventory_revenue" class="ns-sub-dropdown-item">Inventory
                                Revenue</a>
                            <a href="?page=reports/inventory/inventory_profitability" class="ns-sub-dropdown-item">Inventory
                                Profitability</a>
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
                            <a href="?page=reports/customers/balance_confirmation" class="ns-sub-dropdown-item">Customer
                                Balance Confirmation</a>
                            <a href="?page=reports/customers/statement" class="ns-sub-dropdown-item">Customer Statement</a>
                            <a href="?page=reports/customers/ar_register" class="ns-sub-dropdown-item">AR Register</a>
                            <a href="?page=reports/customers/ar_payment_by_invoice" class="ns-sub-dropdown-item">AR Payment
                                by Invoice</a>
                            <a href="?page=reports/customers/receivable_aging" class="ns-sub-dropdown-item">AR Aging</a>
                        </div>
                    </div>
                    <div class="ns-dropdown-item" style="background: #f0f9ff;">
                        <i class="fas fa-lightbulb" style="color: #0284c7;"></i> <strong style="color: #0369a1;">General
                            Insights</strong> <i class="fas fa-caret-right"
                            style="float: right; margin-top: 3px; font-size: 10px; color: #0284c7;"></i>
                        <div class="ns-sub-dropdown">
                            <a href="?page=reports/financial/break_even_payback" class="ns-sub-dropdown-item"
                                style="font-weight: 700; color: #0284c7;">Break-Even & Investment Payback</a>
                            <a href="?page=reports/sales/top_profit_items" class="ns-sub-dropdown-item">Top Profit Items</a>
                            <a href="?page=reports/inventory/inventory_profitability" class="ns-sub-dropdown-item">Inventory
                                Profitability Insights</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ns-nav-item">
                Setup <i class="fas fa-caret-down" style="margin-left: 5px; font-size: 10px; opacity: 0.7;"></i>
                <div class="ns-dropdown">
                    <a href="?page=system/company/manage" class="ns-dropdown-item"><i class="fas fa-building"></i> System
                        Information</a>
                    <a href="?page=system/users" class="ns-dropdown-item"><i class="fas fa-user-shield"></i> Employees &
                        Users</a>
                    <a href="?page=system/roles" class="ns-dropdown-item"><i class="fas fa-user-lock"></i> Role
                        Permissions</a>
                    <a href="?page=system/fiscal_years" class="ns-dropdown-item"><i class="fas fa-calendar-check"></i>
                        Accounting Periods / Closing</a>
                    <a href="?page=system/settings/accounting" class="ns-dropdown-item"><i class="fas fa-calculator"></i>
                        Accounting Lists</a>
                    <a href="?page=system/settings/accounting_preferences" class="ns-dropdown-item"><i
                            class="fas fa-file-contract"></i> Accounting Preferences</a>
                    <a href="?page=master/account/opening_balance" class="ns-dropdown-item"><i
                            class="fas fa-balance-scale"></i> Bank Opening Balances</a>
                    <a href="?page=system/settings/whatsapp_settings" class="ns-dropdown-item"><i class="fab fa-whatsapp"
                            style="color:#25D366;"></i> WhatsApp Integration</a>
                    <a href="?page=system/ref_codes/manage" class="ns-dropdown-item"><i class="fas fa-list-ol"></i> Auto
                        Generated Numbers</a>
                    <a href="?page=system/import_export/manage" class="ns-dropdown-item"><i class="fas fa-file-import"></i>
                        Import / Export Data</a>
                    <a href="?page=system/backup/manage" class="ns-dropdown-item"><i class="fas fa-database"></i> Backup &
                        Restore</a>
                </div>
            </div>
        </nav>

        <!-- Main Application Content -->
        <div
            class="ns-content <?php echo ($page === 'home' || $page === 'print' || $is_print_page) ? 'ns-content-flush' : ''; ?>">
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
                        if ($module_name == 'users')
                            $module_name = 'user';
                        if ($module_name == 'roles')
                            $module_name = 'role';
                        $page_path = "forms/modules/" . $dir_path . "/" . $module_name . "_manage.php";
                    } elseif ($action == 'view' || $action == 'print') {
                        $page_path = "forms/modules/" . $dir_path . "/" . $action . ".php";
                        if (!file_exists($page_path) && $count >= 2) {
                            $module_name = $parts[$count - 2];
                            if ($module_name == 'users') $module_name = 'user';
                            if ($module_name == 'roles') $module_name = 'role';
                            $page_path = "forms/modules/" . $dir_path . "/" . $module_name . "_" . $action . ".php";
                        }
                        if (!file_exists($page_path) && $count >= 3) {
                            $parent_dir = implode('/', array_slice($parts, 0, $count - 2));
                            $page_path = "forms/modules/" . $parent_dir . "/" . $action . ".php";
                        }
                    } else {
                        $module_name = $action;
                        if ($module_name == 'users')
                            $module_name = 'user';
                        if ($module_name == 'roles')
                            $module_name = 'role';

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
                (function () {
                    const toggleBtn = document.getElementById('ns-theme-toggle');
                    if (toggleBtn) {
                        const icon = toggleBtn.querySelector('i');
                        // Check saved theme
                        if (localStorage.getItem('ns_theme') === 'dark') {
                            document.body.classList.add('dark-theme');
                            icon.className = 'fas fa-sun';
                        }
                        toggleBtn.addEventListener('click', function () {
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
                if ($.fn.dataTable) {
                    $.fn.dataTable.ext.errMode = 'none';
                }
                $('.ns-table').DataTable({
                    "pageLength": 25,
                    "order": [], // Maintain server-side sorting (latest created on top)
                    "language": {
                        "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                        "infoEmpty": "Showing 0 to 0 of 0 entries",
                        "lengthMenu": "Show _MENU_ entries",
                        "search": "Quick Search:"
                    },
                    "initComplete": function (settings, json) {
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
            modal.onclick = function (e) {
                if (e.target === modal) cleanup();
            };
        }
    </script>
    <!-- Global Confirmation Modal -->
    <div id="ns-modal"
        style="display: none; position: fixed; z-index: 10001; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center;">
        <div
            style="background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); width: 400px; max-width: 90%; text-align: center; font-family: inherit;">
            <div style="font-size: 40px; color: #f39c12; margin-bottom: 15px;"><i
                    class="fas fa-exclamation-triangle"></i></div>
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