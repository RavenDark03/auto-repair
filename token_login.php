<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mechanix_urls.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    $_SESSION['error_message'] = 'Invalid or missing login link.';
    header('Location: ' . mechanix_url_path('/login.php'));
    exit;
}

try {
    $pdo = Database::getInstance();

    // Fetch token row, joining user + tenant
    $stmt = $pdo->prepare("
        SELECT
            lt.token_id,
            lt.tenant_id,
            lt.user_id,
            lt.expires_at,
            lt.used_at,
            u.full_name,
            u.username,
            u.password_hash,
            u.must_change_password,
            u.role,
            u.status AS user_status,
            t.business_name,
            t.status AS tenant_status,
            t.access_mode
        FROM login_tokens lt
        INNER JOIN users u   ON u.user_id   = lt.user_id
        INNER JOIN tenants t ON t.tenant_id = lt.tenant_id
        WHERE lt.token = :token
          AND lt.purpose = 'verified_login'
        LIMIT 1
    ");
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $_SESSION['error_message'] = 'This login link is invalid or has already been used.';
        header('Location: ' . mechanix_url_path('/login.php'));
        exit;
    }

    // Check expiry
    if (new DateTimeImmutable() > new DateTimeImmutable($row['expires_at'])) {
        $_SESSION['error_message'] = 'This login link has expired. Please log in normally with your username and password.';
        header('Location: ' . mechanix_url_path('/login.php'));
        exit;
    }

    // Check already used
    if ($row['used_at'] !== null) {
        $_SESSION['error_message'] = 'This login link has already been used. Please log in normally.';
        header('Location: ' . mechanix_url_path('/login.php'));
        exit;
    }

    // Check user active
    if ($row['user_status'] !== 'active') {
        $_SESSION['error_message'] = 'Your account is not active. Please contact support.';
        header('Location: ' . mechanix_url_path('/login.php'));
        exit;
    }

    // Mark token as used
    $pdo->prepare("
        UPDATE login_tokens SET used_at = NOW() WHERE token_id = :token_id
    ")->execute(['token_id' => (int) $row['token_id']]);

    // Establish session — same pattern as login_process.php
    session_regenerate_id(true);
    unset($_SESSION['super_admin_id'], $_SESSION['super_admin_username']);

    $_SESSION['user_id']              = $row['user_id'];
    $_SESSION['tenant_id']            = $row['tenant_id'];
    $_SESSION['full_name']            = $row['full_name'];
    $_SESSION['username']             = $row['username'];
    $_SESSION['role']                 = $row['role'];
    $_SESSION['business_name']        = $row['business_name'];
    $_SESSION['must_change_password'] = (int) $row['must_change_password'] === 1;
    $_SESSION['access_mode']          = $row['access_mode'] ?? 'full_access';

    if ((int) $row['must_change_password'] === 1) {
        header('Location: ' . mechanix_url_path('/change_password.php'));
        exit;
    }

    // Redirect to dashboard — blur overlay will handle the payment gate if still pending_payment
    if ($row['role'] === 'admin') {
        header('Location: ' . mechanix_url_path('/admin/dashboard.php'));
        exit;
    }

    // Fallback for unexpected roles
    $_SESSION['error_message'] = 'Your account is set up. Please log in normally.';
    header('Location: ' . mechanix_url_path('/login.php'));
    exit;

} catch (Throwable $e) {
    $_SESSION['error_message'] = 'Login link could not be verified. Please try logging in normally.';
    header('Location: ' . mechanix_url_path('/login.php'));
    exit;
}
