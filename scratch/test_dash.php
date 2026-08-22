<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
$_SESSION['role_id'] = 'superadmin';
$_SESSION['company_id'] = 1;
$_SESSION['username'] = 'superadmin';
$_SESSION['full_name'] = 'System Administrator';

require_once __DIR__ . '/../database/DBConnection.php';
include __DIR__ . '/../api/get_dashboard_data.php';
