<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['super_admin_error'] = 'Please enter both username and password.';
    header('Location: ../login.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT super_admin_id, username, password_hash
        FROM super_admins
        WHERE username = :username
        LIMIT 1
    ");
    $stmt->execute(['username' => $username]);
    $superAdmin = $stmt->fetch();

    if (!$superAdmin || !password_verify($password, $superAdmin['password_hash'])) {
        $_SESSION['super_admin_error'] = 'Invalid username or password.';
        header('Location: ../login.php');
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['super_admin_id'] = $superAdmin['super_admin_id'];
    $_SESSION['super_admin_username'] = $superAdmin['username'];

    header('Location: ../dashboard.php');
    exit;
} catch (PDOException $e) {
    $_SESSION['super_admin_error'] = 'System error: ' . $e->getMessage();
    header('Location: ../login.php');
    exit;
}
?>
