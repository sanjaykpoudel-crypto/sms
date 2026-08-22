<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

$new_hash = password_hash('admin123', PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'")->execute([$new_hash]);
echo "Admin password updated to 'admin123'.\n";
