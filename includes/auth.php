<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
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
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

function currentUserHasTenantRole(array $allowedRoles) {
    return in_array((string) getCurrentUserRole(), $allowedRoles, true);
}

function requireTenantRoles(array $allowedRoles, $redirectPath = null) {
    requireLogin();

    if ($redirectPath === null) {
        $redirectPath = BASE_URL . '/login.php';
    }

    if (mustChangePassword()) {
        redirectToPasswordChange();
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $isDashboard = strpos($scriptName, 'dashboard.php') !== false;
    $isPendingPaymentPage = strpos($scriptName, 'pending_payment.php') !== false;

    if (!$isDashboard && !$isPendingPaymentPage) {
        try {
            $pdo = Database::getInstance();
            $tenantStmt = $pdo->prepare("
                SELECT status
                FROM tenants
                WHERE tenant_id = :tenant_id
                LIMIT 1
            ");
            $tenantStmt->execute(['tenant_id' => (int) ($_SESSION['tenant_id'] ?? 0)]);
            $tenantStatus = (string) $tenantStmt->fetchColumn();
            
            if ($tenantStatus === 'pending_payment') {
                header('Location: ' . BASE_URL . '/admin/dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
        }
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

    enforceTenantWriteAccess();
}

function requireAdmin() {
    requireTenantRoles(['admin']);
}

function enforceTenantWriteAccess() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $tenantId = (int) ($_SESSION['tenant_id'] ?? 0);

    if ($tenantId <= 0) {
        return;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($scriptName, '/admin/actions/') === false) {
        return;
    }

    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT access_mode
            FROM tenants
            WHERE tenant_id = :tenant_id
            LIMIT 1
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $mode = (string) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $mode = 'full_access';
    }

    $_SESSION['access_mode'] = $mode !== '' ? $mode : 'full_access';

    if ($_SESSION['access_mode'] === 'read_only') {
        $_SESSION['auth_error'] = 'This tenant is currently on the Read-Only plan. Editing actions are locked.';
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }
}
?>
