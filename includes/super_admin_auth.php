<?php
require_once __DIR__ . '/session.php';

function isSuperAdminLoggedIn() {
    return isset($_SESSION['super_admin_id'], $_SESSION['super_admin_username']);
}

function requireSuperAdmin() {
    if (!isSuperAdminLoggedIn()) {
        header('Location: ../superadmin/login.php');
        exit;
    }
}
?>
