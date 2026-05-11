<?php
require_once __DIR__ . '/includes/session.php';

$redirect = isset($_SESSION['super_admin_id']) ? 'superadmin/login.php' : 'login.php';

unset($_SESSION['super_admin_id'], $_SESSION['super_admin_username']);
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: ' . $redirect);
exit;
