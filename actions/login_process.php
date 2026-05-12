<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../login.php");
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['error_message'] = 'Please enter both username and password.';
    header("Location: ../login.php");
    exit;
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
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['error_message'] = 'Invalid username or password.';
        header("Location: ../login.php");
        exit;
    }

    if ($user['user_status'] !== 'active') {
        $_SESSION['error_message'] = 'Your account is inactive.';
        header("Location: ../login.php");
        exit;
    }

    if ($user['tenant_status'] !== 'active') {
        $_SESSION['error_message'] = 'This tenant is currently inactive.';
        header("Location: ../login.php");
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        $_SESSION['error_message'] = 'Invalid username or password.';
        header("Location: ../login.php");
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['tenant_id'] = $user['tenant_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['business_name'] = $user['business_name'];
    $_SESSION['must_change_password'] = (int) $user['must_change_password'] === 1;
    $_SESSION['access_mode'] = $user['access_mode'] ?? 'full_access';

    if ((int) $user['must_change_password'] === 1) {
        header("Location: ../change_password.php");
        exit;
    }

    if ($user['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit;
    }

    if ($user['role'] === 'cashier') {
        header("Location: ../admin/cashier_dashboard.php");
        exit;
    }

    $_SESSION['error_message'] = 'Your role is not yet connected to a dashboard.';
    header("Location: ../login.php");
    exit;

} catch (PDOException $e) {
    $_SESSION['error_message'] = 'System error: ' . $e->getMessage();
    header("Location: ../login.php");
    exit;
}
