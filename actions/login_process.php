<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant_branding.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_url_path('/login.php'));
    exit;
}

$brandingSlugRaw = trim((string) ($_POST['branding_slug'] ?? ''));
$redirectLoginUrl = mechanix_url_path('/login.php');
if ($brandingSlugRaw !== '') {
    $redirectLoginUrl = mechanix_url_path('/t/login.php') . '?slug=' . urlencode($brandingSlugRaw);
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['error_message'] = 'Please enter both username and password.';
    header('Location: ' . $redirectLoginUrl);
    exit;
}

$scopeTenantId = null;
if ($brandingSlugRaw !== '') {
    try {
        $pdoScope = Database::getInstance();
        $scopeTenantId = tenant_branding_tenant_id_for_slug($pdoScope, $brandingSlugRaw);
    } catch (PDOException $e) {
        $scopeTenantId = null;
    }

    if ($scopeTenantId === null) {
        $_SESSION['error_message'] = 'That workspace link is invalid or inactive.';
        header('Location: ' . mechanix_url_path('/login.php'));
        exit;
    }
}

try {
    $pdo = Database::getInstance();

    $sql = "
        SELECT 
            u.user_id,
            u.tenant_id,
            u.full_name,
            u.username,
            u.password_hash,
            u.must_change_password,
            u.role,
            u.status AS user_status,
            t.business_name,
            t.status AS tenant_status,
            t.access_mode
        FROM users u
        INNER JOIN tenants t ON u.tenant_id = t.tenant_id
        WHERE u.username = :username
    ";

    $execParams = ['username' => $username];

    if ($scopeTenantId !== null) {
        $sql .= ' AND u.tenant_id = :scope_tenant_id';
        $execParams['scope_tenant_id'] = $scopeTenantId;
    }

    $sql .= '
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($execParams);
    $user = $stmt->fetch();

    if (!$user) {
        if ($scopeTenantId !== null) {
            $_SESSION['error_message'] = 'Invalid username or password.';
            header('Location: ' . $redirectLoginUrl);
            exit;
        }

        $saStmt = $pdo->prepare("
            SELECT super_admin_id, username, password_hash
            FROM super_admins
            WHERE username = :username
            LIMIT 1
        ");
        $saStmt->execute(['username' => $username]);
        $superAdmin = $saStmt->fetch();

        if (!$superAdmin || !password_verify($password, $superAdmin['password_hash'])) {
            $_SESSION['error_message'] = 'Invalid username or password.';
            header('Location: ' . mechanix_url_path('/login.php'));
            exit;
        }

        session_regenerate_id(true);

        unset(
            $_SESSION['user_id'],
            $_SESSION['tenant_id'],
            $_SESSION['full_name'],
            $_SESSION['username'],
            $_SESSION['role'],
            $_SESSION['business_name'],
            $_SESSION['must_change_password'],
            $_SESSION['access_mode']
        );

        $_SESSION['super_admin_id'] = $superAdmin['super_admin_id'];
        $_SESSION['super_admin_username'] = $superAdmin['username'];

        header('Location: ' . mechanix_url_path('/superadmin/dashboard.php'));
        exit;
    }

    if ($user['user_status'] !== 'active') {
        $_SESSION['error_message'] = 'Your account is inactive.';
        header('Location: ' . $redirectLoginUrl);
        exit;
    }

    if ($user['tenant_status'] !== 'active' && $user['tenant_status'] !== 'pending_payment') {
        $_SESSION['error_message'] = 'This tenant is currently inactive.';
        header('Location: ' . $redirectLoginUrl);
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        $_SESSION['error_message'] = 'Invalid username or password.';
        header('Location: ' . $redirectLoginUrl);
        exit;
    }

    session_regenerate_id(true);

    unset($_SESSION['super_admin_id'], $_SESSION['super_admin_username']);

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['tenant_id'] = $user['tenant_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['business_name'] = $user['business_name'];
    $_SESSION['must_change_password'] = (int) $user['must_change_password'] === 1;
    $_SESSION['access_mode'] = $user['access_mode'] ?? 'full_access';

    if ((int) $user['must_change_password'] === 1) {
        header('Location: ' . mechanix_url_path('/change_password.php'));
        exit;
    }

    if (($user['tenant_status'] ?? '') === 'pending_payment' && ($user['role'] ?? '') === 'admin') {
        header('Location: ' . mechanix_url_path('/admin/dashboard.php'));
        exit;
    }

    if ($user['role'] === 'admin') {
        header('Location: ' . mechanix_url_path('/admin/dashboard.php'));
        exit;
    }

    if ($user['role'] === 'cashier') {
        header('Location: ' . mechanix_url_path('/admin/cashier_dashboard.php'));
        exit;
    }

    $_SESSION['error_message'] = 'Your role is not yet connected to a dashboard.';
    header('Location: ' . $redirectLoginUrl);
    exit;
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'System error: ' . $e->getMessage();
    header('Location: ' . $redirectLoginUrl);
    exit;
}
