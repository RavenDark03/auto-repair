<?php
require_once __DIR__ . '/session.php';

function isSuperAdminLoggedIn() {
    return isset($_SESSION['super_admin_id'], $_SESSION['super_admin_username']);
}

function requireSuperAdmin() {
    if (!isSuperAdminLoggedIn()) {
        require_once __DIR__ . '/../config/config.php';
        header('Location: ' . mechanix_url_path('/login.php'));
        exit;
    }
}
?>
