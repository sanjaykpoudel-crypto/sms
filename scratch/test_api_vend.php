<?php
session_start();
$_SESSION['user_id'] = 'usr-superadmin';
$_GET = ['party_id' => '1', 'party_type' => 'vendor'];
require_once __DIR__ . '/../database/DBConnection.php';
include __DIR__ . '/../api/get_open_transactions.php';
