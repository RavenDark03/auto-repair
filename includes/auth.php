<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/config.php';

function isLoggedIn() {
    return isset($_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['role']);
}

function mustChangePassword() {
    return !empty($_SESSION['must_change_password']);
}

function redirectToPasswordChange() {
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: ../login.php");
        exit;
    }
}

function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

function currentUserHasTenantRole(array $allowedRoles) {
    return in_array((string) getCurrentUserRole(), $allowedRoles, true);
}

function requireTenantRoles(array $allowedRoles, $redirectPath = '../login.php') {
    requireLogin();

    if (mustChangePassword()) {
        redirectToPasswordChange();
    }

    if (!currentUserHasTenantRole($allowedRoles)) {
        $_SESSION['auth_error'] = 'You do not have permission to open that page.';

        if (in_array((string) getCurrentUserRole(), ['admin', 'cashier'], true)) {
            $dashboardPath = getCurrentUserRole() === 'cashier'
                ? BASE_URL . '/admin/cashier_dashboard.php'
                : BASE_URL . '/admin/dashboard.php';
            header("Location: " . $dashboardPath);
            exit;
        }

        header("Location: " . $redirectPath);
        exit;
    }
}

function requireAdmin() {
    requireTenantRoles(['admin']);
}
?>
