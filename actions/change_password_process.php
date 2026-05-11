<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['tenant_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (empty($_SESSION['must_change_password'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }

    if (($_SESSION['role'] ?? '') === 'cashier') {
        header('Location: ' . BASE_URL . '/admin/cashier_dashboard.php');
        exit;
    }

    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (strlen($newPassword) < 8) {
    $_SESSION['change_password_error'] = 'Your new password must be at least 8 characters long.';
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['change_password_error'] = 'The password confirmation does not match.';
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $userStmt = $pdo->prepare("
        SELECT user_id, tenant_id, password_hash, role, status
        FROM users
        WHERE user_id = :user_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $userStmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'tenant_id' => (int) $_SESSION['tenant_id'],
    ]);
    $user = $userStmt->fetch();

    if (!$user) {
        throw new RuntimeException('The current user account could not be found.');
    }

    if ($user['status'] !== 'active') {
        throw new RuntimeException('This account is inactive.');
    }

    if (password_verify($newPassword, $user['password_hash'])) {
        $_SESSION['change_password_error'] = 'Choose a new password that is different from the temporary password.';
        header('Location: ' . BASE_URL . '/change_password.php');
        exit;
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $updateStmt = $pdo->prepare("
        UPDATE users
        SET password_hash = :password_hash,
            must_change_password = 0
        WHERE user_id = :user_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'password_hash' => $passwordHash,
        'user_id' => (int) $_SESSION['user_id'],
        'tenant_id' => (int) $_SESSION['tenant_id'],
    ]);

    $_SESSION['must_change_password'] = false;
    session_regenerate_id(true);

    $_SESSION['change_password_success'] = 'Password updated successfully.';

    if (($user['role'] ?? '') === 'admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }

    if (($user['role'] ?? '') === 'cashier') {
        header('Location: ' . BASE_URL . '/admin/cashier_dashboard.php');
        exit;
    }

    header('Location: ' . BASE_URL . '/login.php');
    exit;
} catch (Throwable $e) {
    $_SESSION['change_password_error'] = 'Password update failed: ' . $e->getMessage();
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}
?>
