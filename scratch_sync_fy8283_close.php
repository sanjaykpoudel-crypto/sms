<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['userdata'] = ['id' => 1, 'role' => 'admin'];

require_once __DIR__ . '/database/DBConnection.php';
require_once __DIR__ . '/api/reference_helper.php';

$db = db();

// Set FY 82/83 to open temporarily so close action can re-run
$db->execute("UPDATE fiscal_years SET status = 'open' WHERE id = 1");

$_POST['action'] = 'close';
$_POST['id'] = '1';
$_POST['notes'] = 'Auto-sync closing entry with updated inventory GL reconciliation';

ob_start();
include __DIR__ . '/api/fiscal_year_handler.php';
$out = ob_get_clean();

echo "Handler output:\n{$out}\n";
