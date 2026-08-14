<?php
session_start();
require_once 'database/DBConnection.php';

if (isset($_SESSION['user_id'])) {
    try {
        db()->execute("UPDATE users SET current_session_id = NULL WHERE id = :id", ['id' => $_SESSION['user_id']]);
    } catch (Exception $e) {}
}

session_unset();
session_destroy();
header("Location: index.php");
exit();
?>
