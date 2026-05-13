<?php
require_once __DIR__ . '/includes/session.php';

$redirect = 'login.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ctx = (string) ($_POST['logout_context'] ?? '');
    if ($ctx === 'superadmin') {
        $redirect = 'superadmin/login.php';
    }
} else {
    $ctx = (string) ($_GET['logout_context'] ?? '');
    if ($ctx === 'superadmin') {
        $redirect = 'superadmin/login.php';
    }
}

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
